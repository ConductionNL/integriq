<?php

/**
 * Unit tests for StufZknSyncService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\StufZknProviderException;
use OCA\OpenConnector\Service\StufZkn\InboundBerichtTranslator;
use OCA\OpenConnector\Service\StufZkn\LogStufZknProvider;
use OCA\OpenConnector\Service\StufZkn\OutboundKennisgevingTranslator;
use OCA\OpenConnector\Service\StufZkn\StufZknAcknowledgementBuilder;
use OCA\OpenConnector\Service\StufZkn\StufZknClient;
use OCA\OpenConnector\Service\StufZknSyncService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the StUF-ZKN inbound/outbound sync orchestration: provider selection, OR
 * upsert-by-identificatie, idempotent redelivery, Bv03/Fo03 shaping, per-message audit
 * persistence, retry isolation, and not-configured behaviour.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md
 */
class StufZknSyncServiceTest extends TestCase
{

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var LogStufZknProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logProvider;

    /**
     * @var StufZknClient|\PHPUnit\Framework\MockObject\MockObject
     */
    private $restProvider;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var StufZknSyncService
     */
    private StufZknSyncService $service;

    /**
     * Every saveObject invocation captured as [schema => list of {object, register, uuid}].
     *
     * @var array<string, array<int, array{object: array, register: string|null, uuid: string|null}>>
     */
    private array $saved = [];

    /**
     * Pre-seeded openconnector `source` rows.
     *
     * @var array<int, ObjectEntity>
     */
    private array $sources = [];

    /**
     * Pre-seeded `stuf_message` rows.
     *
     * @var array<int, ObjectEntity>
     */
    private array $messages = [];

    /**
     * Pre-seeded zaak/document rows, keyed by "register:schema:identificatie".
     *
     * @var array<string, ObjectEntity>
     */
    private array $targets = [];

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->getMockBuilder(ORObjectService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->logProvider  = $this->createMock(LogStufZknProvider::class);
        $this->restProvider = $this->createMock(StufZknClient::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->saved    = [];
        $this->sources  = [];
        $this->messages = [];
        $this->targets  = [];

        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config): array {
                $filters = ($config['filters'] ?? []);
                $schema  = ($filters['schema'] ?? null);

                if ($schema === StufZknSyncService::SCHEMA_SOURCE) {
                    return ['results' => $this->sources];
                }

                if ($schema === StufZknSyncService::SCHEMA_MESSAGE) {
                    $referentienummer = ($filters['referentienummer'] ?? null);
                    $direction        = ($filters['direction'] ?? null);
                    $matching         = array_values(
                        array_filter(
                            $this->messages,
                            static function (ObjectEntity $m) use ($referentienummer, $direction): bool {
                                $data = $m->getObject();
                                if ($direction !== null && ($data['direction'] ?? null) !== $direction) {
                                    return false;
                                }

                                if ($referentienummer !== null && ($data['referentienummer'] ?? null) !== $referentienummer) {
                                    return false;
                                }

                                return true;
                            }
                        )
                    );
                    return ['results' => $matching];
                }

                // zaak/document target lookup by identificatie.
                $identificatie = ($filters['identificatie'] ?? null);
                $register      = ($filters['register'] ?? '');
                $key           = $register.':'.$schema.':'.$identificatie;
                $found         = ($this->targets[$key] ?? null);

                return ['results' => ($found === null ? [] : [$found])];
            }
        );

        $this->objectService->method('saveObject')->willReturnCallback(
            function ($object, $register=null, $schema=null, $uuid=null): ObjectEntity {
                $key                 = (string) $schema;
                $this->saved[$key][] = ['object' => $object, 'register' => $register, 'uuid' => $uuid];
                $entity              = $this->entity($object, ($uuid ?? 'saved-uuid-'.count($this->saved[$key])));

                if ($schema === StufZknSyncService::DEFAULT_ZAAK_SCHEMA
                    || $schema === StufZknSyncService::DEFAULT_DOCUMENT_SCHEMA
                ) {
                    $identificatie = ($object['identificatie'] ?? null);
                    if ($identificatie !== null) {
                        $this->targets[$register.':'.$schema.':'.$identificatie] = $entity;
                    }
                }

                return $entity;
            }
        );

        $this->service = new StufZknSyncService(
            $this->objectService,
            $this->logProvider,
            $this->restProvider,
            new InboundBerichtTranslator(),
            new OutboundKennisgevingTranslator(),
            new StufZknAcknowledgementBuilder(),
            $this->logger
        );

    }//end setUp()

    /**
     * Build a real ObjectEntity for a data payload (magic getters need the real Entity path).
     *
     * @param array  $data The object data.
     * @param string $uuid The entity uuid.
     *
     * @return ObjectEntity
     */
    private function entity(array $data, string $uuid='uuid-1'): ObjectEntity
    {
        return ObjectServiceMockBuilder::objectEntity($this, $data, $uuid);

    }//end entity()

    /**
     * A stuf-zkn source entity (log provider by default).
     *
     * @param array  $configuration Extra configuration merged over the default.
     * @param string $uuid          Entity uuid.
     *
     * @return ObjectEntity
     */
    private function sourceEntity(array $configuration=[], string $uuid='source-1'): ObjectEntity
    {
        return $this->entity(
            [
                'type'          => 'stuf-zkn',
                'isEnabled'     => true,
                'configuration' => array_merge(['provider' => 'log', 'organisatie' => 'Procest'], $configuration),
            ],
            $uuid
        );

    }//end sourceEntity()

    /**
     * A minimal well-formed zakLk01 SOAP envelope.
     *
     * @param string $verwerkingssoort  The object's verwerkingssoort attribute.
     * @param string $referentienummer  The stuurgegevens.referentienummer.
     * @param string $identificatie     The object's identificatie.
     *
     * @return string
     */
    private function zakLk01(string $verwerkingssoort='T', string $referentienummer='REF-1', string $identificatie='ZAAK-1'): string
    {
        return <<<XML
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:StUF="http://www.egem.nl/StUF/StUF0301"
                xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <soap:Body>
    <zkn:zakLk01>
      <zkn:stuurgegevens>
        <StUF:berichtcode>Lk01</StUF:berichtcode>
        <StUF:zender><StUF:organisatie>Gemeente X</StUF:organisatie></StUF:zender>
        <StUF:ontvanger><StUF:organisatie>Procest</StUF:organisatie></StUF:ontvanger>
        <StUF:referentienummer>{$referentienummer}</StUF:referentienummer>
        <StUF:tijdstipBericht>20260716120000</StUF:tijdstipBericht>
        <StUF:entiteittype>ZAK</StUF:entiteittype>
      </zkn:stuurgegevens>
      <zkn:parameters><StUF:mutatiesoort>{$verwerkingssoort}</StUF:mutatiesoort></zkn:parameters>
      <zkn:object StUF:entiteittype="ZAK" StUF:verwerkingssoort="{$verwerkingssoort}">
        <zkn:identificatie>{$identificatie}</zkn:identificatie>
        <zkn:omschrijving>Kapvergunning</zkn:omschrijving>
        <zkn:zaaktype><zkn:code>B0337</zkn:code></zkn:zaaktype>
        <zkn:registratiedatum>20260716</zkn:registratiedatum>
        <zkn:startdatum>20260716</zkn:startdatum>
      </zkn:object>
    </zkn:zakLk01>
  </soap:Body>
</soap:Envelope>
XML;

    }//end zakLk01()

    /**
     * A complete zaak fields set for outbound.
     *
     * @return array
     */
    private function zaakFields(): array
    {
        return [
            'identificatie'    => 'ZAAK-2026-001',
            'omschrijving'     => 'Kapvergunning',
            'zaaktypeCode'     => 'B0337',
            'registratiedatum' => '20260716',
            'startdatum'       => '20260716',
        ];

    }//end zaakFields()

    /**
     * resolveProvider() defaults to the log/sandbox binding.
     *
     * @return void
     */
    public function testResolveProviderDefaultsToLog(): void
    {
        $this->assertSame($this->logProvider, $this->service->resolveProvider([]));

    }//end testResolveProviderDefaultsToLog()

    /**
     * resolveProvider() selects the REST binding when configured.
     *
     * @return void
     */
    public function testResolveProviderSelectsRest(): void
    {
        $this->assertSame($this->restProvider, $this->service->resolveProvider(['provider' => 'rest']));

    }//end testResolveProviderSelectsRest()

    /**
     * resolveActiveSource() throws when no active source is configured.
     *
     * @return void
     */
    public function testResolveActiveSourceThrowsWhenNoneConfigured(): void
    {
        $this->expectException(StufZknProviderException::class);
        $this->service->resolveActiveSource();

    }//end testResolveActiveSourceThrowsWhenNoneConfigured()

    /**
     * sendKennisgeving() with no active source throws — not-configured behaviour.
     *
     * @return void
     */
    public function testSendKennisgevingThrowsWhenNoSourceConfigured(): void
    {
        $this->expectException(StufZknProviderException::class);
        $this->service->sendKennisgeving($this->zaakFields(), 'T');

    }//end testSendKennisgevingThrowsWhenNoSourceConfigured()

    /**
     * A successful outbound send (log provider) persists a sent record.
     *
     * @return void
     */
    public function testSendKennisgevingPersistsSentRecordOnSuccess(): void
    {
        $this->sources[] = $this->sourceEntity();
        $this->logProvider->method('send')->willReturn('MOCK-STUFZKN-1');

        $result = $this->service->sendKennisgeving($this->zaakFields(), 'T');

        $this->assertNotSame('', $result['referentienummer']);
        $this->assertCount(1, $this->saved[StufZknSyncService::SCHEMA_MESSAGE]);
        $saved = $this->saved[StufZknSyncService::SCHEMA_MESSAGE][0]['object'];
        $this->assertSame('outbound', $saved['direction']);
        $this->assertSame('sent', $saved['status']);
        $this->assertSame('T', $saved['verwerkingssoort']);

    }//end testSendKennisgevingPersistsSentRecordOnSuccess()

    /**
     * A provider send failure persists a failed record with the error, then rethrows.
     *
     * @return void
     */
    public function testSendKennisgevingPersistsFailedRecordAndRethrows(): void
    {
        $this->sources[] = $this->sourceEntity(['provider' => 'rest']);
        $this->restProvider->method('send')->willThrowException(
            new StufZknProviderException('consumer unreachable')
        );

        try {
            $this->service->sendKennisgeving($this->zaakFields(), 'T');
            $this->fail('Expected StufZknProviderException was not thrown.');
        } catch (StufZknProviderException $exception) {
            $this->assertSame('consumer unreachable', $exception->getMessage());
        }

        $saved = $this->saved[StufZknSyncService::SCHEMA_MESSAGE][0]['object'];
        $this->assertSame('failed', $saved['status']);
        $this->assertSame('consumer unreachable', $saved['error']);

    }//end testSendKennisgevingPersistsFailedRecordAndRethrows()

    /**
     * receiveInbound() with a well-formed zakLk01 upserts the target zaak, persists a processed
     * record, and replies with a Bv03.
     *
     * @return void
     */
    public function testReceiveInboundUpsertsZaakAndRepliesBv03(): void
    {
        $reply = $this->service->receiveInbound($this->zakLk01());

        $this->assertStringContainsString('Bv03Bericht', $reply);
        $this->assertStringContainsString('<StUF:crossRefnummer>REF-1</StUF:crossRefnummer>', $reply);

        $this->assertCount(1, $this->saved[StufZknSyncService::DEFAULT_ZAAK_SCHEMA]);
        $savedZaak = $this->saved[StufZknSyncService::DEFAULT_ZAAK_SCHEMA][0]['object'];
        $this->assertSame('ZAAK-1', $savedZaak['identificatie']);

        $savedMessage = $this->saved[StufZknSyncService::SCHEMA_MESSAGE][0]['object'];
        $this->assertSame('inbound', $savedMessage['direction']);
        $this->assertSame('processed', $savedMessage['status']);
        $this->assertSame('REF-1', $savedMessage['referentienummer']);

    }//end testReceiveInboundUpsertsZaakAndRepliesBv03()

    /**
     * receiveInbound() with an untranslatable envelope persists a failed record and replies
     * with a Fo03 that leaks no internal detail.
     *
     * @return void
     */
    public function testReceiveInboundWithMalformedXmlRepliesFo03(): void
    {
        $reply = $this->service->receiveInbound('not xml at all');

        $this->assertStringContainsString('Fo03Bericht', $reply);
        $this->assertCount(1, $this->saved[StufZknSyncService::SCHEMA_MESSAGE]);
        $this->assertSame('failed', $this->saved[StufZknSyncService::SCHEMA_MESSAGE][0]['object']['status']);

    }//end testReceiveInboundWithMalformedXmlRepliesFo03()

    /**
     * A redelivered inbound message (same referentienummer, already processed) does not create a
     * second stuf_message row and does not re-upsert the OR object — idempotency.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-a-redelivered-inbound-message-is-acknowledged-without-duplicating-state
     */
    public function testRedeliveredInboundMessageDoesNotDuplicate(): void
    {
        $first = $this->service->receiveInbound($this->zakLk01(referentienummer: 'REF-DUP'));
        $this->assertStringContainsString('Bv03Bericht', $first);
        $this->assertCount(1, $this->saved[StufZknSyncService::SCHEMA_MESSAGE]);
        $this->assertCount(1, $this->saved[StufZknSyncService::DEFAULT_ZAAK_SCHEMA]);

        // Seed the "already processed" message row the redelivery must find.
        $this->messages[] = $this->entity(
            [
                'direction'        => 'inbound',
                'referentienummer' => 'REF-DUP',
                'status'           => 'processed',
                'berichttype'      => 'zakLk01',
            ],
            'msg-dup'
        );

        $second = $this->service->receiveInbound($this->zakLk01(referentienummer: 'REF-DUP'));

        $this->assertStringContainsString('Bv03Bericht', $second);
        // No NEW stuf_message row and no NEW zaak upsert were added for the redelivery.
        $this->assertCount(1, $this->saved[StufZknSyncService::SCHEMA_MESSAGE]);
        $this->assertCount(1, $this->saved[StufZknSyncService::DEFAULT_ZAAK_SCHEMA]);

    }//end testRedeliveredInboundMessageDoesNotDuplicate()

    /**
     * verwerkingssoort=V (vervallen) on an existing zaak marks it vervallen rather than deleting it.
     *
     * @return void
     */
    public function testVervallenMarksExistingZaakVervallenRatherThanDeleting(): void
    {
        $this->targets['zaken:zaak:ZAAK-1'] = $this->entity(
            ['identificatie' => 'ZAAK-1', 'omschrijving' => 'Kapvergunning', 'status' => 'open'],
            'zaak-uuid-1'
        );

        $reply = $this->service->receiveInbound($this->zakLk01(verwerkingssoort: 'V'));

        $this->assertStringContainsString('Bv03Bericht', $reply);
        $savedZaak = $this->saved[StufZknSyncService::DEFAULT_ZAAK_SCHEMA][0]['object'];
        $this->assertSame('vervallen', $savedZaak['status']);
        $this->assertSame('Kapvergunning', $savedZaak['omschrijving'], 'vervallen must not wipe other fields');

    }//end testVervallenMarksExistingZaakVervallenRatherThanDeleting()

    /**
     * verwerkingssoort=V for an identificatie with no existing record is a no-op — still
     * acknowledged, nothing written.
     *
     * @return void
     */
    public function testVervallenForUnknownIdentificatieIsNoOp(): void
    {
        $reply = $this->service->receiveInbound($this->zakLk01(verwerkingssoort: 'V', identificatie: 'ZAAK-NEVER-SEEN'));

        $this->assertStringContainsString('Bv03Bericht', $reply);
        $this->assertArrayNotHasKey(StufZknSyncService::DEFAULT_ZAAK_SCHEMA, $this->saved);

    }//end testVervallenForUnknownIdentificatieIsNoOp()

    /**
     * receiveInbound() with no active source configured is still acknowledgeable (inbound must
     * never depend on a configured outbound source to accept a message).
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-inbound-receipt-works-even-with-no-active-source-configured
     */
    public function testReceiveInboundWorksWithoutAnyConfiguredSource(): void
    {
        $reply = $this->service->receiveInbound($this->zakLk01());
        $this->assertStringContainsString('Bv03Bericht', $reply);

    }//end testReceiveInboundWorksWithoutAnyConfiguredSource()

    /**
     * retryFailed() retries only failed outbound rows and is isolated per-row — one throwing
     * retry never aborts the sweep.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-one-failing-retry-does-not-abort-the-sweep
     */
    public function testRetryFailedIsIsolatedPerRow(): void
    {
        $this->sources[] = $this->sourceEntity();

        $this->messages[] = $this->entity(
            ['direction' => 'outbound', 'status' => 'failed', 'referentienummer' => 'REF-A'],
            'msg-a'
        );
        $this->messages[] = $this->entity(
            ['direction' => 'outbound', 'status' => 'failed', 'referentienummer' => 'REF-B'],
            'msg-b'
        );
        $this->messages[] = $this->entity(
            ['direction' => 'outbound', 'status' => 'sent', 'referentienummer' => 'REF-C'],
            'msg-c'
        );

        $call = 0;
        $this->logProvider->method('send')->willReturnCallback(
            function () use (&$call) {
                $call++;
                if ($call === 1) {
                    throw new StufZknProviderException('still down');
                }

                return 'MOCK-RETRY-OK';
            }
        );

        $retried = $this->service->retryFailed();

        // Only 1 of the 2 failed rows succeeds; the sent row is never retried.
        $this->assertSame(1, $retried);

    }//end testRetryFailedIsIsolatedPerRow()
}//end class
