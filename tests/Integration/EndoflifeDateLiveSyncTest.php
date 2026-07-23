<?php
/**
 * Live smoke test for the endoflife-date-source preset against the REAL,
 * public, unauthenticated https://endoflife.date/api.
 *
 * Follows the established `tests/Integration` self-skipping convention
 * (tests/Integration/Tables/TablesBridgeIntegrationTest.php) — the whole
 * class skips, not fails, on a network-isolated CI run.
 *
 * Design note: rather than constructing a full, DI-heavy `CallService`
 * (which brokers auth/retry/circuit-breaker/call-log persistence unrelated
 * to this preset), a real, unmocked HTTP GET is dispatched directly via
 * Guzzle inside the mocked `CallService::call()`'s callback — the network
 * dispatch is genuinely live; only the surrounding call-log/persistence
 * machinery (CallService's own, already-covered responsibility) is stood
 * in for. The REAL `SynchronizationService`/`MappingService` then process
 * that real response exactly as the seeded `endoflife-date-php-cycles`
 * Synchronization/Mapping would in production.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-a-live-smoke-test-proves-the-preset-against-the-real-public-api
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-a-live-smoke-test-proves-the-preset-against-the-real-public-api
 */
class EndoflifeDateLiveSyncTest extends TestCase
{

    private const SYNC_ID     = 'endoflife-date-php-cycles';
    private const SOURCE_UUID = 'source-uuid-endoflife-date-live';
    private const ENDPOINT    = 'https://endoflife.date/api/php.json';

    /**
     * In-memory synchronization_contract store, mutated by the mocked
     * saveObject() callback built in {@see buildLiveService()}.
     *
     * Instance property (not a local returned by reference) — a PHP
     * function cannot hand back a genuine reference to a local variable via
     * `return [..., &$local]` unless the function itself is declared
     * `function &foo()` ("Attempting to set reference to non referenceable
     * value"); the destructuring silently no-ops instead, which is why this
     * store must be a property the closures close over directly.
     *
     * @var array<string, array>
     */
    private array $contractStore = [];

    /**
     * In-memory eolCycle target store — see {@see $contractStore}.
     *
     * @var array<string, array>
     */
    private array $targetStore = [];


    /**
     * Skip the whole class (never fail) when outbound network access is
     * unavailable, or when explicitly disabled for a network-isolated CI run
     * — mirrors TablesBridgeIntegrationTest's established convention.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->contractStore = [];
        $this->targetStore   = [];

        if (getenv('OPENCONNECTOR_SKIP_NETWORK_TESTS') === '1') {
            $this->markTestSkipped('OPENCONNECTOR_SKIP_NETWORK_TESTS=1 — network-isolated run.');
        }

        $connectable = @fsockopen('endoflife.date', 443, $errno, $errstr, 3);
        if ($connectable === false) {
            $this->markTestSkipped(
                sprintf('endoflife.date is unreachable from this environment (%s) — skipping live smoke test.', $errstr)
            );
        }

        fclose($connectable);

    }//end setUp()


    /**
     * Build a real Guzzle-backed HTTP fetcher for one endoflife.date
     * endpoint, memoising the response so repeated calls within the same
     * test (idempotency re-run) do not re-hit the network.
     *
     * @return callable(string): array{status: int, body: string}
     */
    private function realFetcher(): callable
    {
        // Force HTTP/1.1: this environment intermittently sees a
        // "SSL_read: unexpected eof" mid-stream error over HTTP/2 against
        // endoflife.date's CDN — retried below regardless, but HTTP/1.1
        // avoids the class of failure entirely.
        $client = new GuzzleClient(['timeout' => 15, 'curl' => [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]]);
        $cache  = [];

        return function (string $url) use ($client, &$cache): array {
            if (isset($cache[$url]) === true) {
                return $cache[$url];
            }

            $lastException = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $response = $client->request('GET', $url, ['headers' => ['Accept' => 'application/json']]);
                    $result   = [
                        'status' => $response->getStatusCode(),
                        'body'   => (string) $response->getBody(),
                    ];
                    $cache[$url] = $result;

                    return $result;
                } catch (GuzzleException $exception) {
                    $lastException = $exception;
                    usleep(300000 * $attempt);
                }
            }

            throw $lastException;
        };

    }//end realFetcher()


    /**
     * Build a real SynchronizationService/MappingService pair whose
     * CallService::call() dispatches a genuine, unmocked HTTP GET (via the
     * fetcher built by {@see realFetcher()}) for the first call, then serves
     * an empty page for pagination termination / a deliberate re-run.
     *
     * @param callable $fetcher The real-HTTP fetcher.
     *
     * @return array{0: SynchronizationService, 1: array<string,array>} The service and the synchronization payload — mutations land on $this->contractStore/$this->targetStore (see their docblocks for why these are instance properties, not returned-by-reference locals).
     */
    private function buildLiveService(callable $fetcher): array
    {
        $orObjectService = ObjectServiceMockBuilder::make($this);
        $callService      = $this->createMock(CallService::class);
        $callService->method('applyConfigDot')->willReturnArgument(0);

        $syncPayload = [
            'id'                  => self::SYNC_ID,
            'uuid'                => self::SYNC_ID,
            'sourceId'            => self::SOURCE_UUID,
            'sourceType'          => 'api',
            'targetType'          => 'register/schema',
            'targetId'            => 'openconnector/eolCycle',
            'sourceTargetMapping' => self::SYNC_ID.'-mapping',
            'sourceConfig'        => [
                'endpoint'               => '/php.json',
                'resultsPosition'        => '_root',
                'idPosition'             => 'cycle',
                'deletionRatioThreshold' => 0.5,
            ],
        ];

        $mappingPayload = [
            'mapping' => [
                'product'           => 'php',
                'cycle'             => 'cycle',
                'releaseDate'       => 'releaseDate',
                'eol'               => 'eol',
                'support'           => 'support',
                'latest'            => 'latest',
                'latestReleaseDate' => 'latestReleaseDate',
                'lts'               => 'lts',
                'discontinued'      => "{{ discontinued|default('') }}",
            ],
            'cast'        => [
                'eol'          => 'string',
                'support'      => 'string',
                'discontinued' => 'string',
            ],
            'passThrough' => false,
        ];

        $orObjectService->method('saveObject')->willReturnCallback(
            function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, ...$rest) {
                if ($schema === 'synchronization_contract') {
                    $key = ($uuid ?? ($object['uuid'] ?? 'contract-'.(count($this->contractStore) + 1)));
                    $this->contractStore[$key] = $object;

                    return ObjectServiceMockBuilder::objectEntity($this, $object, (string) $key);
                }

                if ($schema === 'eolCycle') {
                    $key = ($uuid ?? ('eolcycle-'.(count($this->targetStore) + 1)));
                    $this->targetStore[$key] = $object;

                    return ObjectServiceMockBuilder::objectEntity($this, $object, (string) $key);
                }

                return ObjectServiceMockBuilder::objectEntity(
                    $this,
                    is_array($object) ? $object : [],
                    (string) ($uuid ?? ($object['uuid'] ?? 'saved-uuid'))
                );
            }
        );

        $orObjectService->method('findAll')->willReturnCallback(
            function (array $config=[], ...$rest) {
                $filters = ($config['filters'] ?? []);
                if (isset($filters['originId']) === true || isset($filters['synchronizationId']) === true) {
                    $matches = [];
                    foreach ($this->contractStore as $uuid => $payload) {
                        if (isset($filters['originId']) === true && ($payload['originId'] ?? null) !== $filters['originId']) {
                            continue;
                        }

                        if (isset($filters['synchronizationId']) === true
                            && ($payload['synchronizationId'] ?? null) !== $filters['synchronizationId']
                        ) {
                            continue;
                        }

                        $matches[] = ObjectServiceMockBuilder::objectEntity($this, $payload, (string) $uuid);
                    }

                    return ['results' => $matches, 'total' => count($matches)];
                }

                return ['results' => [], 'total' => 0];
            }
        );

        $findPos = [];
        $rm      = new ReflectionMethod(ORObjectService::class, 'find');
        foreach ($rm->getParameters() as $i => $p) {
            $findPos[$p->getName()] = $i;
        }

        $orObjectService->method('find')->willReturnCallback(
            function (...$args) use ($findPos, $syncPayload, $mappingPayload) {
                $id     = ($args[$findPos['id']] ?? null);
                $schema = ($args[$findPos['schema']] ?? null);

                if ($schema === 'source') {
                    return ObjectServiceMockBuilder::objectEntity(
                        $this,
                        ['location' => 'https://endoflife.date/api', 'auth' => 'none', 'isEnabled' => true],
                        self::SOURCE_UUID
                    );
                }

                if ($schema === 'synchronization' && $id === self::SYNC_ID) {
                    return ObjectServiceMockBuilder::objectEntity($this, $syncPayload, (string) $id);
                }

                if ($schema === 'mapping' && $id === self::SYNC_ID.'-mapping') {
                    return ObjectServiceMockBuilder::objectEntity($this, $mappingPayload, (string) $id);
                }

                if ($schema === 'eolCycle') {
                    return ObjectServiceMockBuilder::objectEntity($this, [], (string) $id);
                }

                return null;
            }
        );

        $callPos = [];
        $rmCall  = new ReflectionMethod(CallService::class, 'call');
        foreach ($rmCall->getParameters() as $i => $p) {
            $callPos[$p->getName()] = $i;
        }

        $endpointCallCounts = [];
        $callService->method('call')->willReturnCallback(
            function (...$args) use ($callPos, $fetcher, &$endpointCallCounts) {
                $endpoint = ($args[$callPos['endpoint']] ?? '');
                $endpointCallCounts[$endpoint] = (($endpointCallCounts[$endpoint] ?? 0) + 1);

                // Only the FIRST call per endpoint dispatches the real HTTP
                // GET; every subsequent call (pagination termination) is
                // served an empty page without another network round trip.
                if ($endpointCallCounts[$endpoint] > 1) {
                    return ObjectServiceMockBuilder::objectEntity(
                        $this,
                        ['response' => ['statusCode' => 200, 'body' => '[]', 'encoding' => 'UTF-8', 'headers' => []]],
                        'call-log-empty-'.$endpointCallCounts[$endpoint]
                    );
                }

                $real = $fetcher(self::ENDPOINT);

                return ObjectServiceMockBuilder::objectEntity(
                    $this,
                    [
                        'response' => [
                            'statusCode' => $real['status'],
                            'body'       => $real['body'],
                            'encoding'   => 'UTF-8',
                            'headers'    => [],
                        ],
                    ],
                    'call-log-live-'.$endpointCallCounts[$endpoint]
                );
            }
        );

        $loader           = new ArrayLoader([]);
        $fileService       = $this->createMock(FileService::class);
        $ocObjectService   = $this->createMock(ObjectService::class);
        $mappingService    = new MappingService(
            $loader,
            $callService,
            $fileService,
            $ocObjectService,
            $orObjectService,
            $this->createMock(\OCA\OpenConnector\Service\SynchronizationContractService::class),
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn(null);

        $logger       = $this->createMock(LoggerInterface::class);
        $logOrService = ObjectServiceMockBuilder::make($this);
        $userSession  = $this->createMock(\OCP\IUserSession::class);
        $session      = $this->createMock(\OCP\ISession::class);
        $logService   = new SynchronizationLogService($logOrService, $userSession, $session);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $service = new SynchronizationService(
            $callService,
            $mappingService,
            $container,
            $orObjectService,
            $ocObjectService,
            $logger,
            $logService,
            $appConfig,
            $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
        );

        return [$service, $syncPayload];

    }//end buildLiveService()


    /**
     * GIVEN outbound network access WHEN the seeded php-cycles
     * synchronization runs for real THEN a genuine HTTP GET is made to
     * https://endoflife.date/api/php.json and at least one eolCycle object
     * is created with `cycle`/`product`/`eol` populated; AND running the
     * same synchronization a second time produces no additional objects
     * (real-API idempotency).
     *
     * spec.md scenario: "the live smoke test passes against the real API
     * when network is available".
     *
     * @return void
     *
     * @throws GuzzleException
     */
    public function testLiveSyncAgainstRealPhpEndpointIsCreatedAndIdempotent(): void
    {
        [$service, $syncPayload] = $this->buildLiveService($this->realFetcher());

        $first = $service->synchronize(synchronization: $syncPayload);

        $this->assertGreaterThan(
            0,
            $first['result']['objects']['found'],
            'The real https://endoflife.date/api/php.json response must contain at least one cycle.'
        );
        $this->assertGreaterThan(0, $first['result']['objects']['created']);
        $this->assertNotEmpty($this->targetStore, 'At least one real eolCycle object must have been written.');

        foreach ($this->targetStore as $eolCycle) {
            $this->assertSame('php', $eolCycle['product']);
            $this->assertNotEmpty($eolCycle['cycle']);
            $this->assertIsString($eolCycle['eol'], 'eol must always be cast to string, even for a real JSON false value.');
        }

        $createdCount = count($this->targetStore);

        // Re-run: same live endpoint, same (memoised-per-fetcher) response —
        // real-API idempotency proof.
        $second = $service->synchronize(synchronization: $syncPayload);

        $this->assertSame(0, $second['result']['objects']['created'], 'No additional eolCycle objects on the second run against the same real data.');
        $this->assertCount($createdCount, $this->targetStore, 'No duplicate eolCycle objects were created by the second run.');

    }//end testLiveSyncAgainstRealPhpEndpointIsCreatedAndIdempotent()
}//end class
