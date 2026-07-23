<?php

/**
 * Unit tests for DsoIngestService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dso-connector-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\DsoProviderException;
use OCA\OpenConnector\Exception\DsoTranslationException;
use OCA\OpenConnector\Service\Dso\DsoClient;
use OCA\OpenConnector\Service\Dso\DsoVerzoekTranslator;
use OCA\OpenConnector\Service\Dso\LogDsoConnectorProvider;
use OCA\OpenConnector\Service\DsoIngestService;
use OCA\OpenConnector\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Service\Handoff\HandoffService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for verzoek ingest (persist + translate), the authenticated handoff
 * trigger, and the outbound status/besluit post — including per-verzoek/
 * per-message failure isolation and the not-configured outbound path.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DsoIngestServiceTest extends TestCase
{

    /**
     * In-memory `dso_verzoek` store keyed by uuid.
     *
     * @var array<string, ObjectEntity>
     */
    private array $verzoekStore = [];

    /**
     * In-memory `dso_message` store (append-only, indexed).
     *
     * @var array<int, ObjectEntity>
     */
    private array $messageStore = [];

    /**
     * In-memory `source` fixtures.
     *
     * @var array<int, ObjectEntity>
     */
    private array $sourceFixtures = [];

    private int $uuidCounter = 0;

    private HandoffService $handoffService;

    private DsoClient $restProvider;

    private function buildEntity(array $data, string $uuid): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($data);

        return $entity;

    }//end buildEntity()

    /**
     * @return ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildObjectService()
    {
        $mock = $this->getMockBuilder(ORObjectService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mock->method('saveObject')->willReturnCallback(
            function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null) {
                if ($schema === DsoIngestService::SCHEMA_MESSAGE) {
                    $entity = $this->buildEntity((array) $object, 'message-'.(++$this->uuidCounter));
                    $this->messageStore[] = $entity;
                    return $entity;
                }

                if ($schema !== DsoIngestService::SCHEMA_VERZOEK) {
                    return $this->buildEntity((array) $object, ($uuid ?? 'other-'.(++$this->uuidCounter)));
                }

                $resolvedUuid = ($uuid ?? 'verzoek-'.(++$this->uuidCounter));
                $entity       = $this->buildEntity((array) $object, $resolvedUuid);
                $this->verzoekStore[$resolvedUuid] = $entity;

                return $entity;
            }
        );

        $mock->method('find')->willReturnCallback(
            function ($id, ?string $register=null, ?string $schema=null) {
                if ($schema === DsoIngestService::SCHEMA_VERZOEK) {
                    return ($this->verzoekStore[(string) $id] ?? null);
                }

                // RawSourceResolver re-reads the located source by uuid with
                // `_render: false` (ocon#242). The fake must model that read, or
                // the synthetic fallback below silently REPLACES the source with
                // a credential-free stub and the assertions stop meaning anything.
                if ($schema === DsoIngestService::SCHEMA_SOURCE) {
                    foreach ($this->sourceFixtures as $sourceFixture) {
                        if ($sourceFixture->getUuid() === (string) $id) {
                            return $sourceFixture;
                        }
                    }

                    return null;
                }

                // Handoff-target lookups: any non-verzoek find() returns a synthetic target entity.
                return $this->buildEntity(['title' => 'Case'], (string) $id);
            }
        );

        $mock->method('findAll')->willReturnCallback(
            function (array $config=[]) {
                $schema = ($config['filters']['schema'] ?? null);
                if ($schema === DsoIngestService::SCHEMA_SOURCE) {
                    return ['results' => $this->sourceFixtures, 'total' => count($this->sourceFixtures)];
                }

                if ($schema === DsoIngestService::SCHEMA_VERZOEK) {
                    $status  = ($config['filters']['status'] ?? null);
                    $results = array_values($this->verzoekStore);
                    if ($status !== null) {
                        $results = array_values(
                            array_filter(
                                $results,
                                static fn (ObjectEntity $e): bool => (($e->getObject()['status'] ?? null) === $status)
                            )
                        );
                    }

                    return ['results' => $results, 'total' => count($results)];
                }

                return ['results' => [], 'total' => 0];
            }
        );

        return $mock;

    }//end buildObjectService()

    private function buildService(): DsoIngestService
    {
        $objectService = $this->buildObjectService();

        $this->handoffService = $this->getMockBuilder(HandoffService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->restProvider = $this->getMockBuilder(DsoClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new DsoIngestService(
            objectService: $objectService,
            handoffService: $this->handoffService,
            translator: new DsoVerzoekTranslator(),
            logProvider: new LogDsoConnectorProvider(),
            restProvider: $this->restProvider,
            logger: $this->createMock(LoggerInterface::class),
            rawSourceResolver: new RawSourceResolver($objectService, $this->createMock(LoggerInterface::class))
        );

    }//end buildService()

    private function addSourceFixture(array $configuration, bool $enabled=true): void
    {
        $this->sourceFixtures[] = $this->buildEntity(
            ['type' => 'dso', 'isEnabled' => $enabled, 'configuration' => $configuration],
            'source-dso'
        );

    }//end addSourceFixture()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-successful-ingest-reaches-mapped
     */
    public function testIngestReachesMappedForValidVerzoek(): void
    {
        $service = $this->buildService();

        $verzoek = $service->ingest(
            parsedVerzoek: [
                'verzoekId'    => 'dso-1',
                'type'         => 'aanvraag',
                'activiteiten' => [['code' => 'bouwen-01', 'omschrijving' => 'Bouwen van een woning']],
            ]
        );

        $this->assertSame('mapped', $verzoek->getObject()['status']);
        $this->assertSame('Bouwen van een woning', $verzoek->getObject()['mappedTitle']);

    }//end testIngestReachesMappedForValidVerzoek()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#scenario-a-verzoek-with-no-verzoekid-is-refused-not-fabricated
     */
    public function testIngestFailsClosedForMissingVerzoekId(): void
    {
        $service = $this->buildService();

        $verzoek = $service->ingest(parsedVerzoek: ['type' => 'aanvraag']);

        $this->assertSame('failed', $verzoek->getObject()['status']);
        $this->assertStringContainsString('verzoekId', (string) $verzoek->getObject()['errorDetail']);

    }//end testIngestFailsClosedForMissingVerzoekId()

    /**
     * Per-verzoek isolation: a translation failure on one verzoek MUST NOT
     * affect a second, valid verzoek ingested after it.
     *
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-dso_verzoek-lifecycle-with-per-verzoek-isolation-req-003
     */
    public function testTranslationFailureIsolatesToOneVerzoek(): void
    {
        $service = $this->buildService();

        $first  = $service->ingest(parsedVerzoek: ['type' => 'aanvraag']);
        $second = $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-2', 'type' => 'melding']);

        $this->assertSame('failed', $first->getObject()['status']);
        $this->assertSame('mapped', $second->getObject()['status']);
        $this->assertNotSame($first->getUuid(), $second->getUuid());

    }//end testTranslationFailureIsolatesToOneVerzoek()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-rest-surface-to-list-and-complete-mapped-verzoeken-req-004
     */
    public function testListVerzoekenFiltersByStatus(): void
    {
        $service = $this->buildService();

        $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);
        $service->ingest(parsedVerzoek: ['type' => 'aanvraag']);

        $mapped = $service->listVerzoeken(status: 'mapped');
        $failed = $service->listVerzoeken(status: 'failed');

        $this->assertCount(1, $mapped);
        $this->assertCount(1, $failed);

    }//end testListVerzoekenFiltersByStatus()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
     */
    public function testHandoffSucceedsAndUpdatesVerzoek(): void
    {
        $service = $this->buildService();
        $verzoek = $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);
        $this->assertSame('mapped', $verzoek->getObject()['status']);

        $this->handoffService->method('execute')->willReturn(
            [
                'status'        => 'executed',
                'target'        => ['register' => 'procest', 'schema' => 'case', 'uuid' => 'case-uuid-1'],
                'correlationId' => 'corr-1',
            ]
        );

        $result = $service->handoff(uuid: $verzoek->getUuid());

        $this->assertSame('executed', $result['status']);
        $stored = $this->verzoekStore[$verzoek->getUuid()]->getObject();
        $this->assertSame('corr-1', $stored['correlationId']);
        $this->assertSame('case-uuid-1', $stored['targetCase']['uuid']);

    }//end testHandoffSucceedsAndUpdatesVerzoek()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
     */
    public function testHandoffRejectsWhenVerzoekNotYetMapped(): void
    {
        $service = $this->buildService();
        $this->verzoekStore['v-received'] = $this->buildEntity(['status' => 'received'], 'v-received');

        $this->expectException(DsoTranslationException::class);

        $service->handoff(uuid: 'v-received');

    }//end testHandoffRejectsWhenVerzoekNotYetMapped()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-005
     */
    public function testHandoffFailureMarksVerzoekFailedAndRethrows(): void
    {
        $service = $this->buildService();
        $verzoek = $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

        $this->handoffService->method('execute')->willThrowException(
            new HandoffException(errorCode: HandoffException::PROVIDER_UNAVAILABLE, message: 'no provider')
        );

        $this->expectException(HandoffException::class);

        try {
            $service->handoff(uuid: $verzoek->getUuid());
        } finally {
            $this->assertSame('failed', $this->verzoekStore[$verzoek->getUuid()]->getObject()['status']);
        }

    }//end testHandoffFailureMarksVerzoekFailedAndRethrows()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
     */
    public function testPostOutboundThrowsWhenNotConfigured(): void
    {
        $service = $this->buildService();
        $verzoek = $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

        $this->expectException(DsoProviderException::class);
        $this->expectExceptionMessageMatches('/No active DSO source/');

        $service->postOutbound(verzoekUuid: $verzoek->getUuid(), type: 'status', fields: ['status' => 'in_behandeling']);

    }//end testPostOutboundThrowsWhenNotConfigured()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
     */
    public function testPostOutboundDispatchesViaLogProviderByDefault(): void
    {
        $this->addSourceFixture(configuration: []);
        $service = $this->buildService();
        $verzoek = $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

        $result = $service->postOutbound(verzoekUuid: $verzoek->getUuid(), type: 'status', fields: ['status' => 'in_behandeling']);

        $this->assertSame('sent', $result['status']);
        $this->assertStringStartsWith('MOCK-DSO-', $result['ref']);
        $this->assertCount(1, $this->messageStore);
        $this->assertSame('status', $this->messageStore[0]->getObject()['type']);
        $this->assertSame($verzoek->getUuid(), $this->messageStore[0]->getObject()['verzoekUuid']);

    }//end testPostOutboundDispatchesViaLogProviderByDefault()

    /**
     * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md#requirement-outbound-status-besluit-post-with-per-message-audit-req-006
     */
    public function testPostOutboundPersistsFailedMessageAndRethrowsOnProviderFailure(): void
    {
        $this->addSourceFixture(configuration: ['provider' => 'rest']);
        $service = $this->buildService();
        $verzoek = $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

        $this->restProvider->method('send')->willThrowException(new DsoProviderException(message: 'transport down'));

        $this->expectException(DsoProviderException::class);

        try {
            $service->postOutbound(verzoekUuid: $verzoek->getUuid(), type: 'besluit', fields: ['besluit' => 'verleend']);
        } finally {
            $this->assertCount(1, $this->messageStore);
            $this->assertSame('failed', $this->messageStore[0]->getObject()['status']);
            $this->assertSame('transport down', $this->messageStore[0]->getObject()['error']);
        }

    }//end testPostOutboundPersistsFailedMessageAndRethrowsOnProviderFailure()

    /**
     * postOutbound() rejects an unrecognised message type before touching
     * any source/provider.
     *
     * @return void
     */
    public function testPostOutboundRejectsUnknownType(): void
    {
        $service = $this->buildService();
        $verzoek = $service->ingest(parsedVerzoek: ['verzoekId' => 'dso-1', 'type' => 'aanvraag']);

        $this->expectException(DsoTranslationException::class);

        $service->postOutbound(verzoekUuid: $verzoek->getUuid(), type: 'onbekend', fields: []);

    }//end testPostOutboundRejectsUnknownType()
}//end class
