<?php

/**
 * Unit tests for BankfeedSyncService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-3
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-4
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-5
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use DateInterval;
use DateTime;
use OCA\OpenConnector\Exception\Psd2ConsentRevokedException;
use OCA\OpenConnector\Exception\Psd2ProviderException;
use OCA\OpenConnector\Service\BankfeedSyncService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\Psd2\LogPsd2AggregatorProvider;
use OCA\OpenConnector\Service\Psd2\RestPsd2AggregatorProvider;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the SCA consent flow, account discovery, and the scheduled bankfeed sync.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md
 */
class BankfeedSyncServiceTest extends TestCase
{

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var LogPsd2AggregatorProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logProvider;

    /**
     * @var RestPsd2AggregatorProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $restProvider;

    /**
     * @var EventService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventService;

    /**
     * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
     */
    private $l;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var BankfeedSyncService
     */
    private BankfeedSyncService $service;

    /**
     * Every saveObject invocation captured as [schema => list of saved objects].
     *
     * @var array<string, array<int, array>>
     */
    private array $saved = [];

    /**
     * Every emitted CloudEvent captured as [type, data] tuples.
     *
     * @var array<int, array{0: string, 1: array}>
     */
    private array $events = [];

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);
        $this->logProvider   = $this->createMock(LogPsd2AggregatorProvider::class);
        $this->restProvider  = $this->createMock(RestPsd2AggregatorProvider::class);
        $this->eventService  = $this->createMock(EventService::class);
        $this->l             = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->saved  = [];
        $this->events = [];

        $this->eventService->method('emitCloudEvent')->willReturnCallback(
            function (string $type, string $source, ?string $subject, array $data): array {
                $this->events[] = [$type, $data];
                return [];
            }
        );

        $this->service = new BankfeedSyncService(
            $this->objectService,
            $this->logProvider,
            $this->restProvider,
            $this->eventService,
            $this->l,
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
     * Route saveObject through a capturing callback that returns a real entity
     * wrapping the saved payload (so magic getters keep working downstream).
     *
     * @return void
     */
    private function captureSaves(): void
    {
        $this->objectService->method('saveObject')->willReturnCallback(
            function ($object, $register=null, $schema=null, $uuid=null): ObjectEntity {
                $key                 = (string) $schema;
                $this->saved[$key][] = $object;
                return $this->entity($object, ($uuid ?? 'saved-uuid-'.count($this->saved[$key])));
            }
        );

    }//end captureSaves()

    /**
     * A PSD2 source entity (type psd2, log provider).
     *
     * @param array $configuration Extra configuration merged over the default.
     *
     * @return ObjectEntity
     */
    private function sourceEntity(array $configuration=[]): ObjectEntity
    {
        return $this->entity(
            [
                'type'          => 'psd2',
                'isEnabled'     => true,
                'configuration' => array_merge(['provider' => 'log', 'aggregator' => 'sandbox'], $configuration),
            ],
            'source-uuid'
        );

    }//end sourceEntity()

    /**
     * List every emitted event type.
     *
     * @return array<int, string>
     */
    private function emittedTypes(): array
    {
        return array_map(static fn (array $event) => $event[0], $this->events);

    }//end emittedTypes()

    // ---- provider resolution -------------------------------------------------

    /**
     * resolveProvider selects the log provider by default and the REST provider for `rest`.
     *
     * @return void
     */
    public function testResolveProviderSelectsBinding(): void
    {
        $this->assertSame($this->logProvider, $this->service->resolveProvider([]));
        $this->assertSame($this->logProvider, $this->service->resolveProvider(['provider' => 'log']));
        $this->assertSame($this->restProvider, $this->service->resolveProvider(['provider' => 'rest']));

    }//end testResolveProviderSelectsBinding()

    // ---- connect (REQ-002) ---------------------------------------------------

    /**
     * connect creates a pending connection carrying the registered redirectUrl
     * and returns the bank SCA redirect URL — REQ-002.
     *
     * @return void
     */
    public function testConnectPersistsPendingConnectionAndReturnsScaUrl(): void
    {
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        $this->logProvider->method('createRequisition')->willReturn(
            ['reference' => 'REQ-SANDBOX-9', 'redirectUrl' => 'https://sandbox.bank.example/psd2/sca/REQ-SANDBOX-9']
        );

        $result = $this->service->connect(
            sourceSlug: 'bank-aggregator-sandbox',
            institutionId: 'SANDBOX_BANK',
            redirectUrl: 'https://nc.example/apps/shillinq/psd2/return'
        );

        $this->assertSame('https://sandbox.bank.example/psd2/sca/REQ-SANDBOX-9', $result['redirectUrl']);
        $this->assertSame('REQ-SANDBOX-9', $result['reference']);
        $this->assertNotEmpty($result['connectionId']);

        $connection = $this->saved['bankfeed_connection'][0];
        $this->assertSame('pending', $connection['lifecycleState']);
        $this->assertSame('REQ-SANDBOX-9', $connection['consentReference']);
        $this->assertSame('https://nc.example/apps/shillinq/psd2/return', $connection['redirectUrl']);
        $this->assertSame('bank-aggregator-sandbox', $connection['aggregatorSourceSlug']);
        $this->assertSame('sandbox', $connection['aggregator']);
        $this->assertArrayNotHasKey('consentToken', $connection);

    }//end testConnectPersistsPendingConnectionAndReturnsScaUrl()

    /**
     * connect rejects a source that is not a PSD2 aggregator source.
     *
     * @return void
     */
    public function testConnectRejectsNonPsd2Source(): void
    {
        $this->objectService->method('find')->willReturn($this->entity(['type' => 'api'], 'source-uuid'));
        $this->objectService->expects($this->never())->method('saveObject');

        $this->expectException(Psd2ProviderException::class);

        $this->service->connect(sourceSlug: 'example-rest-api', institutionId: 'X', redirectUrl: 'https://r');

    }//end testConnectRejectsNonPsd2Source()

    // ---- callback / finaliseConsent (REQ-002, REQ-005) ------------------------

    /**
     * An unknown consent reference is rejected — CSRF/mix-up defence (REQ-002).
     *
     * @return void
     */
    public function testFinaliseConsentRejectsUnknownReference(): void
    {
        $this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
        $this->objectService->expects($this->never())->method('saveObject');

        $this->expectException(Psd2ProviderException::class);

        $this->service->finaliseConsent(reference: 'REQ-UNKNOWN');

    }//end testFinaliseConsentRejectsUnknownReference()

    /**
     * A replayed callback for an already-finalised consent is rejected.
     *
     * @return void
     */
    public function testFinaliseConsentRejectsReplayedCallback(): void
    {
        $existing = $this->entity(
            ['connectionId' => 'conn-1', 'consentReference' => 'REQ-1', 'lifecycleState' => 'active'],
            'conn-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$existing], 'total' => 1]);
        $this->objectService->expects($this->never())->method('saveObject');

        $this->expectException(Psd2ProviderException::class);

        $this->service->finaliseConsent(reference: 'REQ-1');

    }//end testFinaliseConsentRejectsReplayedCallback()

    /**
     * A successful callback activates the connection with consentReference +
     * consentExpiresAt, stores NO token on the object, emits consent.granted,
     * and returns the redirectUrl registered at connect time — REQ-002/005.
     *
     * @return void
     */
    public function testFinaliseConsentActivatesConnectionAndEmitsGranted(): void
    {
        $pending = $this->entity(
            [
                'connectionId'         => 'conn-1',
                'aggregatorSourceSlug' => 'bank-aggregator-sandbox',
                'consentReference'     => 'REQ-1',
                'redirectUrl'          => 'https://nc.example/apps/shillinq/psd2/return',
                'accounts'             => [],
                'lifecycleState'       => 'pending',
            ],
            'conn-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$pending], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        $this->logProvider->method('finaliseConsent')->willReturn(
            [
                'consentReference' => 'REQ-1',
                'consentExpiresAt' => '2026-10-10T00:00:00+00:00',
                'accounts'         => [
                    ['iban' => 'NL00BANK0000000001', 'bic' => 'BANKNL2A', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-1'],
                ],
                'consentToken'     => null,
            ]
        );

        $result = $this->service->finaliseConsent(reference: 'REQ-1');

        $this->assertSame('https://nc.example/apps/shillinq/psd2/return', $result['redirectUrl']);
        $this->assertSame('conn-1', $result['connectionId']);

        $saved = $this->saved['bankfeed_connection'][0];
        $this->assertSame('active', $saved['lifecycleState']);
        $this->assertSame('2026-10-10T00:00:00+00:00', $saved['consentExpiresAt']);
        $this->assertNotEmpty($saved['consentGrantedAt']);
        $this->assertCount(1, $saved['accounts']);
        $this->assertArrayNotHasKey('consentToken', $saved);

        $this->assertSame([BankfeedSyncService::EVENT_TYPE_CONSENT_GRANTED], $this->emittedTypes());
        $this->assertSame('conn-1', $this->events[0][1]['connectionId']);
        $this->assertSame('bank-aggregator-sandbox', $this->events[0][1]['aggregatorSourceSlug']);
        $this->assertSame('REQ-1', $this->events[0][1]['consentReference']);
        $this->assertArrayHasKey('consentExpiresAt', $this->events[0][1]);

    }//end testFinaliseConsentActivatesConnectionAndEmitsGranted()

    /**
     * When a provider returns token material and the credential store cannot
     * broker it, the finalisation fails closed: nothing is persisted and no
     * plaintext token lands anywhere — REQ-006.
     *
     * The brokering failure is injected. This test used to rely on `\OCP\Server`
     * having no container in the unit environment, which made it pass for a
     * reason unrelated to what it checks: once the suite ran against a real
     * Nextcloud the brokering SUCCEEDED, the connection was saved — correctly —
     * and the test failed. A fail-closed path has to be closed on purpose to be
     * evidence of anything.
     *
     * @return void
     */
    public function testFinaliseConsentFailsClosedWhenTokenCannotBeBrokered(): void
    {
        $service = new BankfeedSyncService(
            $this->objectService,
            $this->logProvider,
            $this->restProvider,
            $this->eventService,
            $this->l,
            $this->logger,
            static function (): object {
                throw new \RuntimeException('credential store unavailable');
            }
        );

        $pending = $this->entity(
            [
                'connectionId'         => 'conn-1',
                'aggregatorSourceSlug' => 'bank-aggregator-sandbox',
                'consentReference'     => 'REQ-1',
                'redirectUrl'          => 'https://nc.example/return',
                'lifecycleState'       => 'pending',
            ],
            'conn-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$pending], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->objectService->expects($this->never())->method('saveObject');

        $this->logProvider->method('finaliseConsent')->willReturn(
            [
                'consentReference' => 'REQ-1',
                'consentExpiresAt' => '2026-10-10T00:00:00+00:00',
                'accounts'         => [],
                'consentToken'     => 'SECRET-TOKEN-MATERIAL',
            ]
        );

        try {
            $service->finaliseConsent(reference: 'REQ-1');
            $this->fail('Expected Psd2ProviderException (fail closed on unbrokerable token)');
        } catch (Psd2ProviderException $exception) {
            // The token itself must never leak into the error message (REQ-006).
            $this->assertStringNotContainsString('SECRET-TOKEN-MATERIAL', $exception->getMessage());
        }

        $this->assertSame([], $this->events);

    }//end testFinaliseConsentFailsClosedWhenTokenCannotBeBrokered()

    // ---- account discovery (REQ-003) ------------------------------------------

    /**
     * Discovery on an active connection records IBAN/BIC/currency/account-id — REQ-003.
     *
     * @return void
     */
    public function testDiscoverAccountsRecordsAccountSet(): void
    {
        $connection = $this->entity(
            [
                'connectionId'         => 'conn-1',
                'aggregatorSourceSlug' => 'bank-aggregator-sandbox',
                'consentReference'     => 'REQ-1',
                'accounts'             => [],
                'lifecycleState'       => 'active',
            ],
            'conn-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        $this->logProvider->method('listAccounts')->willReturn(
            [
                ['iban' => 'NL00BANK0000000001', 'bic' => 'BANKNL2A', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-1'],
            ]
        );

        $accounts = $this->service->discoverAccounts(connectionId: 'conn-1');

        $this->assertCount(1, $accounts);
        $this->assertSame('NL00BANK0000000001', $accounts[0]['iban']);
        $this->assertSame('ACC-1', $accounts[0]['aggregatorAccountId']);
        $this->assertSame($accounts, $this->saved['bankfeed_connection'][0]['accounts']);

    }//end testDiscoverAccountsRecordsAccountSet()

    /**
     * Re-discovery replaces the set in place with no duplicates — REQ-003 idempotency.
     *
     * @return void
     */
    public function testDiscoverAccountsIsIdempotentOnRediscovery(): void
    {
        $connection = $this->entity(
            [
                'connectionId'         => 'conn-1',
                'aggregatorSourceSlug' => 'bank-aggregator-sandbox',
                'consentReference'     => 'REQ-1',
                'accounts'             => [
                    ['iban' => 'NL00BANK0000000001', 'bic' => 'OLD', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-1'],
                ],
                'lifecycleState'       => 'active',
            ],
            'conn-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        // The provider answer even contains an internal duplicate — the
        // recorded set must still be unique per aggregatorAccountId.
        $this->logProvider->method('listAccounts')->willReturn(
            [
                ['iban' => 'NL00BANK0000000001', 'bic' => 'BANKNL2A', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-1'],
                ['iban' => 'NL00BANK0000000001', 'bic' => 'BANKNL2A', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-1'],
                ['iban' => 'NL00BANK0000000009', 'bic' => 'BANKNL2A', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-9'],
            ]
        );

        $accounts = $this->service->discoverAccounts(connectionId: 'conn-1');

        $this->assertCount(2, $accounts);
        $this->assertSame('BANKNL2A', $accounts[0]['bic']);

    }//end testDiscoverAccountsIsIdempotentOnRediscovery()

    /**
     * Discovery on a non-active connection is refused.
     *
     * @return void
     */
    public function testDiscoverAccountsRequiresActiveConnection(): void
    {
        $connection = $this->entity(
            ['connectionId' => 'conn-1', 'lifecycleState' => 'expired'],
            'conn-uuid'
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->expects($this->never())->method('saveObject');

        $this->expectException(Psd2ProviderException::class);

        $this->service->discoverAccounts(connectionId: 'conn-1');

    }//end testDiscoverAccountsRequiresActiveConnection()

    // ---- scheduled sync (REQ-004) ----------------------------------------------

    /**
     * Build an active connection with one account for sync tests.
     *
     * @param array $overrides Data merged over the defaults.
     *
     * @return ObjectEntity
     */
    private function activeConnection(array $overrides=[]): ObjectEntity
    {
        $farFuture = (new DateTime())->add(new DateInterval('P60D'))->format('c');

        return $this->entity(
            array_merge(
                [
                    'connectionId'         => 'conn-1',
                    'aggregatorSourceSlug' => 'bank-aggregator-sandbox',
                    'consentReference'     => 'REQ-1',
                    'consentGrantedAt'     => '2026-07-01T00:00:00+00:00',
                    'consentExpiresAt'     => $farFuture,
                    'accounts'             => [
                        ['iban' => 'NL00BANK0123456789', 'bic' => 'BANKNL2A', 'currency' => 'EUR', 'aggregatorAccountId' => 'ACC-1'],
                    ],
                    'lastSyncAt'           => '2026-07-10T00:00:00+00:00',
                    'lifecycleState'       => 'active',
                ],
                $overrides
            ),
            'conn-uuid'
        );

    }//end activeConnection()

    /**
     * A scheduled pull persists a bankfeed_batch and emits the synced event with
     * {connectionId, accountIban, since, until, transactionCount, batchUri} — REQ-004.
     *
     * @return void
     */
    public function testSyncAllPersistsBatchAndEmitsSyncedEvent(): void
    {
        $connection = $this->activeConnection();
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        $rows = [['transactionId' => 'TX-1'], ['transactionId' => 'TX-2']];
        $this->logProvider->method('listTransactions')->willReturn($rows);

        $batches = $this->service->syncAll();

        $this->assertSame(1, $batches);

        $batch = $this->saved['bankfeed_batch'][0];
        $this->assertSame('conn-1', $batch['connectionId']);
        $this->assertSame('NL00BANK0123456789', $batch['accountIban']);
        $this->assertSame('2026-07-10T00:00:00+00:00', $batch['since']);
        $this->assertSame(2, $batch['transactionCount']);
        $this->assertSame($rows, $batch['transactions']);

        $this->assertContains(BankfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes());
        $syncedIndex = array_search(BankfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes(), true);
        $payload     = $this->events[$syncedIndex][1];
        $this->assertSame('conn-1', $payload['connectionId']);
        $this->assertSame('NL00BANK0123456789', $payload['accountIban']);
        $this->assertSame('2026-07-10T00:00:00+00:00', $payload['since']);
        $this->assertNotEmpty($payload['until']);
        $this->assertSame(2, $payload['transactionCount']);
        $this->assertStringContainsString('/apps/openregister/api/objects/openconnector/bankfeed_batch/', $payload['batchUri']);

        // Watermark advanced only after the successful pull.
        $connectionSaves = $this->saved['bankfeed_connection'];
        $this->assertSame($payload['until'], end($connectionSaves)['lastSyncAt']);

    }//end testSyncAllPersistsBatchAndEmitsSyncedEvent()

    /**
     * An expired connection is marked expired and skipped — no aggregator call,
     * no synced event (REQ-004).
     *
     * @return void
     */
    public function testSyncAllSkipsExpiredConnectionWithoutAggregatorCall(): void
    {
        $connection = $this->activeConnection(
            ['consentExpiresAt' => '2026-01-01T00:00:00+00:00']
        );
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        $this->logProvider->expects($this->never())->method('listTransactions');

        $batches = $this->service->syncAll();

        $this->assertSame(0, $batches);
        $this->assertSame('expired', $this->saved['bankfeed_connection'][0]['lifecycleState']);
        $this->assertNotContains(BankfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes());
        $this->assertArrayNotHasKey('bankfeed_batch', $this->saved);

    }//end testSyncAllSkipsExpiredConnectionWithoutAggregatorCall()

    /**
     * A revoked or pending connection is skipped without any aggregator call.
     *
     * @return void
     */
    public function testSyncAllSkipsNonActiveLifecycleStates(): void
    {
        $revoked = $this->activeConnection(['lifecycleState' => 'revoked']);
        $pending = $this->activeConnection(['lifecycleState' => 'pending']);
        $this->objectService->method('findAll')->willReturn(['results' => [$revoked, $pending], 'total' => 2]);
        $this->captureSaves();

        $this->logProvider->expects($this->never())->method('listTransactions');

        $this->assertSame(0, $this->service->syncAll());
        $this->assertSame([], $this->events);

    }//end testSyncAllSkipsNonActiveLifecycleStates()

    /**
     * A failed pull persists nothing and does NOT advance the watermark, so the
     * same window is re-attempted next sweep — REQ-004.
     *
     * @return void
     */
    public function testSyncAllFailedPullDoesNotAdvanceWatermark(): void
    {
        $connection = $this->activeConnection();
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        $this->logProvider->method('listTransactions')
            ->willThrowException(new Psd2ProviderException(message: 'aggregator unreachable'));

        $batches = $this->service->syncAll();

        $this->assertSame(0, $batches);
        // Nothing persisted at all: no batch, no watermark advance.
        $this->assertSame([], $this->saved);
        $this->assertSame('2026-07-10T00:00:00+00:00', $connection->getObject()['lastSyncAt']);
        $this->assertSame([], $this->events);

    }//end testSyncAllFailedPullDoesNotAdvanceWatermark()

    /**
     * A consent the aggregator reports revoked moves the connection to `revoked`
     * and emits consent.revoked with the connection identifiers — REQ-005.
     *
     * @return void
     */
    public function testSyncAllRevokedConsentEmitsRevokedEvent(): void
    {
        $connection = $this->activeConnection();
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();

        $this->logProvider->method('listTransactions')
            ->willThrowException(new Psd2ConsentRevokedException(message: 'consent revoked upstream'));

        $this->service->syncAll();

        $lastConnectionSave = end($this->saved['bankfeed_connection']);
        $this->assertSame('revoked', $lastConnectionSave['lifecycleState']);
        $this->assertSame([BankfeedSyncService::EVENT_TYPE_CONSENT_REVOKED], $this->emittedTypes());
        $this->assertSame('conn-1', $this->events[0][1]['connectionId']);
        $this->assertSame('REQ-1', $this->events[0][1]['consentReference']);

    }//end testSyncAllRevokedConsentEmitsRevokedEvent()

    /**
     * A consent entering the warning window emits consent.expiring exactly once
     * (the second sweep does not re-emit) while syncing continues — REQ-005.
     *
     * @return void
     */
    public function testSyncAllEmitsExpiringEventOnlyOnce(): void
    {
        $soon       = (new DateTime())->add(new DateInterval('P5D'))->format('c');
        $connection = $this->activeConnection(['consentExpiresAt' => $soon]);
        $this->objectService->method('findAll')->willReturn(['results' => [$connection], 'total' => 1]);
        $this->objectService->method('find')->willReturn($this->sourceEntity());
        $this->captureSaves();
        $this->logProvider->method('listTransactions')->willReturn([]);

        // First sweep: warning emitted once, sync still runs.
        $this->service->syncAll();
        $firstSweepTypes = $this->emittedTypes();
        $this->assertSame(1, count(array_keys($firstSweepTypes, BankfeedSyncService::EVENT_TYPE_CONSENT_EXPIRING, true)));
        $this->assertContains(BankfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $firstSweepTypes);
        $this->assertNotEmpty($connection->getObject()['expiryWarnedAt']);

        // Second sweep on the (now warned) connection: no second expiring event.
        $this->events = [];
        $this->service->syncAll();
        $this->assertNotContains(BankfeedSyncService::EVENT_TYPE_CONSENT_EXPIRING, $this->emittedTypes());
        $this->assertContains(BankfeedSyncService::EVENT_TYPE_TRANSACTIONS_SYNCED, $this->emittedTypes());

    }//end testSyncAllEmitsExpiringEventOnlyOnce()
}//end class
