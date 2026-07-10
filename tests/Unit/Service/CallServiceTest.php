<?php
/**
 * Unit tests for CallService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\BrokeredCallConfigurationException;
use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\BrokeredCallService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;

/**
 * Tests for the outbound call service (OR cutover — no deleted Db types).
 */
class CallServiceTest extends TestCase
{

    /**
     * @var CallService
     */
    private CallService $service;

    /**
     * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = ObjectServiceMockBuilder::make($this);

        $authService = $this->createMock(AuthenticationService::class);
        $appConfig   = $this->createMock(IAppConfig::class);
        $logger      = $this->createMock(LoggerInterface::class);

        // IAppConfig::hasKey defaults to false so no retention config is applied.
        $appConfig->method('hasKey')->willReturn(false);

        // CallService constructor signature (6 args): ORObjectService,
        // ArrayLoader, AuthenticationService, IAppConfig, LoggerInterface,
        // BrokeredCallService (source-broker-credentials). The previous
        // version passed only 4 — the LoggerInterface arg was added by #1011
        // (security-policy warnings) but the test wasn't updated, causing 5
        // ArgumentCountErrors that only surfaced once #1023 unblocked setUp()
        // and the post-#1024 Service-suite peel (#1026) brought the remaining
        // 3 cited #1025 suites to green.
        $brokered = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(false);

        $this->service = new CallService(
            $this->objectService,
            new ArrayLoader([]),
            $authService,
            $appConfig,
            $logger,
            $brokered,
        );
    }//end setUp()


    /**
     * Captured saveObject() invocations from buildBrokeredCallService().
     *
     * @var array
     */
    private array $saved = [];


    /**
     * Build a CallService with a capturing ObjectService and the given broker double.
     *
     * @param BrokeredCallService|\PHPUnit\Framework\MockObject\MockObject $brokered The brokered-call double.
     *
     * @return CallService
     */
    private function buildBrokeredCallService($brokered): CallService
    {
        $this->saved = [];

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturnCallback(
            function ($object=[], $register=null, $schema=null, $uuid=null) {
                $this->saved[] = [
                    'object'   => $object,
                    'register' => $register,
                    'schema'   => $schema,
                    'uuid'     => $uuid,
                ];
                $entity = new ObjectEntity();
                $entity->setUuid('saved-'.count($this->saved));
                $entity->setObject(is_array($object) === true ? $object : []);

                return $entity;
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        return new CallService(
            $objectService,
            new ArrayLoader([]),
            $this->createMock(AuthenticationService::class),
            $appConfig,
            $this->createMock(LoggerInterface::class),
            $brokered,
        );
    }//end buildBrokeredCallService()


    /**
     * Hydrate an enabled brokered source entity.
     *
     * The location deliberately points at a non-resolvable host: if the
     * engine ever fell back to the Guzzle client, the call would surface as a
     * synthetic 503 ConnectException log — not the brokered response asserted
     * by the tests below.
     *
     * @param array|null $configuration Source configuration override.
     *
     * @return ObjectEntity
     */
    private function makeBrokeredSource(?array $configuration=null): ObjectEntity
    {
        if ($configuration === null) {
            $configuration = [
                'authentication' => [
                    'credentialRef' => ['credentialId' => '00000000-0000-0000-0000-000000000000'],
                ],
            ];
        }

        $source = new ObjectEntity();
        $source->setUuid('source-uuid-1');
        $source->setObject(
            [
                'name'          => 'brokered-source',
                'isEnabled'     => true,
                'location'      => 'https://api.example.invalid',
                'configuration' => $configuration,
            ]
        );

        return $source;
    }//end makeBrokeredSource()


    /**
     * Return the captured call_log payloads.
     *
     * @return array
     */
    private function savedCallLogs(): array
    {
        return array_values(
            array_filter(
                $this->saved,
                function ($row) {
                    return ($row['schema'] === 'call_log');
                }
            )
        );
    }//end savedCallLogs()


    /**
     * A brokered call bypasses Guzzle and persists a normal-envelope CallLog.
     *
     * REQ-SBC-002: dispatch goes through the broker double (statusCode 200
     * against an unresolvable host proves the Guzzle client was not invoked)
     * and the persisted CallLog carries the standard request/response envelope.
     *
     * @return void
     */
    public function testBrokeredCallBypassesGuzzleAndPersistsNormalCallLog(): void
    {
        $dispatched = [];
        $brokered   = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willReturn(
            [
                'credentialId' => '00000000-0000-0000-0000-000000000000',
                'actingUserId' => null,
            ]
        );
        $brokered->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(
                function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use (&$dispatched) {
                    $dispatched[] = [
                        'credentialId' => $credentialId,
                        'actingUserId' => $actingUserId,
                        'method'       => $method,
                        'url'          => $url,
                        'config'       => $config,
                    ];

                    return new Response(200, ['Content-Type' => ['application/json']], '{"ok":true}');
                }
            );

        $service = $this->buildBrokeredCallService($brokered);
        $callLog = $service->call(source: $this->makeBrokeredSource(), endpoint: '/v1/items');

        $this->assertSame('00000000-0000-0000-0000-000000000000', $dispatched[0]['credentialId']);
        $this->assertSame('GET', $dispatched[0]['method']);
        $this->assertSame('https://api.example.invalid/v1/items', $dispatched[0]['url']);
        // The stripped config never carries authentication material.
        $this->assertArrayNotHasKey('authentication', $dispatched[0]['config']);

        $logs = $this->savedCallLogs();
        $this->assertCount(1, $logs);
        $log = $logs[0]['object'];
        $this->assertSame(200, $log['statusCode']);
        $this->assertSame('source-uuid-1', $log['source']);
        $this->assertArrayHasKey('request', $log);
        $this->assertArrayHasKey('response', $log);
        $this->assertSame('GET', $log['request']['method']);
        $this->assertArrayHasKey('responseTime', $log['response']);
        $this->assertInstanceOf(ObjectEntity::class, $callLog);
    }//end testBrokeredCallBypassesGuzzleAndPersistsNormalCallLog()


    /**
     * A brokered config error persists a synthetic 409 CallLog; dispatch never runs.
     *
     * REQ-SBC-001: sibling embedded secrets are a hard config error — no
     * outbound request is dispatched, brokered or Guzzle.
     *
     * @return void
     */
    public function testBrokeredConfigErrorPersists409EarlyLog(): void
    {
        $brokered = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willThrowException(
            new BrokeredCallConfigurationException(
                message: 'Embedded authentication fields are forbidden alongside credentialRef (found: client_secret).'
            )
        );
        $brokered->expects($this->never())->method('dispatch');

        $service = $this->buildBrokeredCallService($brokered);
        $service->call(source: $this->makeBrokeredSource(), endpoint: '/v1/items');

        $logs = $this->savedCallLogs();
        $this->assertCount(1, $logs);
        $this->assertSame(409, $logs[0]['object']['statusCode']);
        $this->assertStringContainsString('forbidden alongside credentialRef', $logs[0]['object']['statusMessage']);
    }//end testBrokeredConfigErrorPersists409EarlyLog()


    /**
     * Broker unavailable soft-fails as a 409 config log with NO fallback dispatch.
     *
     * REQ-SBC-004: only the synthetic 409 log is persisted — no call_log with
     * an upstream status exists, proving no embedded-secret fallback ran.
     *
     * @return void
     */
    public function testBrokeredSoftFailWithoutBrokerNeverFallsBack(): void
    {
        $brokered = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willThrowException(
            new BrokeredCallConfigurationException(
                message: 'credentialRef is configured but the OpenRegister credential broker is unavailable.'
            )
        );
        $brokered->expects($this->never())->method('dispatch');

        $service = $this->buildBrokeredCallService($brokered);
        $service->call(source: $this->makeBrokeredSource(), endpoint: '/v1/items');

        $this->assertCount(1, $this->saved);
        $this->assertSame('call_log', $this->saved[0]['schema']);
        $this->assertSame(409, $this->saved[0]['object']['statusCode']);
        $this->assertStringContainsString('credential broker is unavailable', $this->saved[0]['object']['statusMessage']);
    }//end testBrokeredSoftFailWithoutBrokerNeverFallsBack()


    /**
     * Brokered CallLogs run through the same redaction pipeline as Guzzle logs.
     *
     * REQ-SBC-004 (task 12 fixture): a secret-looking request header is
     * redacted in the persisted request config, and a response body echoing
     * the value is scrubbed — identical to the Guzzle-path redaction tests.
     *
     * @return void
     */
    public function testBrokeredCallLogRedactsSecretsLikeGuzzlePath(): void
    {
        $brokered = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willReturn(
            [
                'credentialId' => '00000000-0000-0000-0000-000000000000',
                'actingUserId' => null,
            ]
        );
        $brokered->method('dispatch')->willReturn(
            new Response(500, [], 'upstream error echoing sekret-value-123 back')
        );

        $configuration = [
            'headers'        => ['X-Api-Key' => 'sekret-value-123'],
            'authentication' => [
                'credentialRef' => ['credentialId' => '00000000-0000-0000-0000-000000000000'],
            ],
        ];

        $service = $this->buildBrokeredCallService($brokered);
        $service->call(source: $this->makeBrokeredSource(configuration: $configuration), endpoint: '/v1/items');

        $logs = $this->savedCallLogs();
        $this->assertCount(1, $logs);
        $log = $logs[0]['object'];
        $this->assertSame(500, $log['statusCode']);
        // Request header value redacted.
        $this->assertSame('***REDACTED***', $log['request']['headers']['X-Api-Key']);
        // Echoed secret scrubbed from the persisted response body.
        $this->assertStringContainsString('***REDACTED***', $log['response']['body']);
        $this->assertStringNotContainsString('sekret-value-123', json_encode($log));
    }//end testBrokeredCallLogRedactsSecretsLikeGuzzlePath()


    /**
     * A paginated brokered sync issues ONE brokered request per page and the
     * engine's rate-limit tracking keeps working off the returned headers.
     *
     * REQ-SBC-002: `SynchronizationService::fetchSinglePageData()` performs
     * one `CallService::call()` per page (with `config['pagination']`); this
     * exercises exactly that engine seam for three pages.
     *
     * @return void
     */
    public function testBrokeredPaginationOneRequestPerPageWithRateLimitTracking(): void
    {
        $dispatched = [];
        $brokered   = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willReturn(
            [
                'credentialId' => '00000000-0000-0000-0000-000000000000',
                'actingUserId' => null,
            ]
        );
        $brokered->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(
                function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use (&$dispatched) {
                    $dispatched[] = $config;
                    $page         = count($dispatched);

                    return new Response(
                        200,
                        [
                            'X-RateLimit-Limit'     => ['100'],
                            'X-RateLimit-Remaining' => [(string) (100 - $page)],
                            'X-RateLimit-Reset'     => [(string) (time() + 3600)],
                        ],
                        '[]'
                    );
                }
            );

        $service = $this->buildBrokeredCallService($brokered);
        $source  = $this->makeBrokeredSource();

        foreach ([1, 2, 3] as $page) {
            $service->call(
                source: $source,
                endpoint: '/v1/items',
                config: [
                    'pagination' => [
                        'paginationQuery' => 'page',
                        'page'            => $page,
                    ],
                ],
            );
        }

        // One brokered request per page, with the page mapped into the query.
        $this->assertCount(3, $dispatched);
        $this->assertSame(1, $dispatched[0]['query']['page']);
        $this->assertSame(2, $dispatched[1]['query']['page']);
        $this->assertSame(3, $dispatched[2]['query']['page']);

        // Rate-limit headers fed the source tracking (one source save per call).
        $sourceSaves = array_values(
            array_filter(
                $this->saved,
                function ($row) {
                    return ($row['schema'] === 'source');
                }
            )
        );
        $this->assertCount(3, $sourceSaves);
        $this->assertSame(97, $sourceSaves[2]['object']['rateLimitRemaining']);
        $this->assertSame(100, $sourceSaves[2]['object']['rateLimitLimit']);

        // The persisted CallLogs expose the merged X-RateLimit-* headers.
        $logs = $this->savedCallLogs();
        $this->assertCount(3, $logs);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $logs[2]['object']['response']['headers']);
    }//end testBrokeredPaginationOneRequestPerPageWithRateLimitTracking()


    /**
     * Test that the constructor instantiates CallService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(CallService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that applyConfigDot expands dot-notation keys into nested arrays.
     *
     * @return void
     */
    public function testApplyConfigDotExpandsDotKeys(): void
    {
        // Arrange
        $config = [
            'foo.bar' => 'baz',
            'plain'   => 'value',
        ];

        // Act
        $result = $this->service->applyConfigDot($config);

        // Assert
        $this->assertArrayHasKey('foo', $result);
        $this->assertSame('baz', $result['foo']['bar']);
        $this->assertSame('value', $result['plain']);
        $this->assertArrayNotHasKey('foo.bar', $result);
    }//end testApplyConfigDotExpandsDotKeys()


    /**
     * Test that applyConfigDot returns flat config unchanged when no dot keys present.
     *
     * @return void
     */
    public function testApplyConfigDotLeavesNonDotKeysUntouched(): void
    {
        // Arrange
        $config = ['key' => 'value', 'another' => 123];

        // Act
        $result = $this->service->applyConfigDot($config);

        // Assert
        $this->assertSame($config, $result);
    }//end testApplyConfigDotLeavesNonDotKeysUntouched()


    /**
     * Test that removeFiles does not throw when config has no certificate keys.
     *
     * @return void
     */
    public function testRemoveFilesHandlesEmptyConfig(): void
    {
        // Arrange / Act / Assert — should not throw
        $this->service->removeFiles([]);
        $this->assertTrue(true);
    }//end testRemoveFilesHandlesEmptyConfig()


    /**
     * Test that getCertificate passes through config with no cert keys unchanged.
     *
     * @return void
     */
    public function testGetCertificatePassesThroughWhenNoCertKey(): void
    {
        // Arrange
        $config = ['url' => 'https://example.com'];

        // Act
        $this->service->getCertificate($config);

        // Assert
        $this->assertSame(['url' => 'https://example.com'], $config);
    }//end testGetCertificatePassesThroughWhenNoCertKey()


    /**
     * oc#94 regression — a Source's own `configuration.listMethod` MUST
     * promote a default-GET list/fetch call to POST, and the static
     * `configuration.body` template MUST be sent as the request body.
     *
     * Diagnostic run against HEAD before the fix (decideMethod() ran BEFORE
     * mergeSourceConfiguration()) proved the dispatched method stayed 'GET'
     * even with `configuration.listMethod = 'POST'` set — the Source-level
     * override was invisible to decideMethod(). This is the exact shape TED's
     * v3 search endpoint needs (`sync_ted_eu`/`ted_eu.json`): POST-only,
     * static JSON body, no explicit `method:` argument from the caller (mirrors
     * `SynchronizationService::callSourceObject()`, which never passes one).
     *
     * The source is unreachable-by-design (`.invalid` TLD, RFC 2606 — never
     * resolves, no live external call) so dispatch synthesises a 503
     * `ConnectException` response; only the PERSISTED request envelope
     * (method + body) is asserted, not a real upstream reply.
     *
     * @return void
     */
    public function testSourceLevelListMethodPromotesDefaultGetCallToPostWithBody(): void
    {
        $brokered = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(false);

        $service = $this->buildBrokeredCallService($brokered);

        $bodyTemplate = '{"query":"classification-cpv = 48000000*","page":1,"limit":50}';
        $source       = new ObjectEntity();
        $source->setUuid('source-uuid-ted');
        $source->setObject(
            [
                'name'          => 'ted',
                'isEnabled'     => true,
                'location'      => 'https://api.example.invalid',
                'configuration' => [
                    'listMethod' => 'POST',
                    'body'       => $bodyTemplate,
                    'headers'    => ['Content-Type' => 'application/json'],
                ],
            ]
        );

        // No explicit method/config override — mirrors how
        // SynchronizationService::callSourceObject() actually invokes call().
        $service->call(source: $source, endpoint: '/v3/notices');

        $logs = $this->savedCallLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('POST', $logs[0]['object']['request']['method']);
        $this->assertSame($bodyTemplate, $logs[0]['object']['request']['body']);
        // The method-override key MUST NOT leak into the persisted request envelope.
        $this->assertArrayNotHasKey('listMethod', $logs[0]['object']['request']);
    }//end testSourceLevelListMethodPromotesDefaultGetCallToPostWithBody()


    /**
     * oc#94 regression — a source WITHOUT a `listMethod` override keeps
     * dispatching GET, byte-for-byte as before the ordering fix (backward
     * compatibility for every existing non-POST source).
     *
     * @return void
     */
    public function testSourceWithoutListMethodOverrideStillDispatchesGet(): void
    {
        $brokered = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(false);

        $service = $this->buildBrokeredCallService($brokered);

        $source = new ObjectEntity();
        $source->setUuid('source-uuid-plain');
        $source->setObject(
            [
                'name'          => 'plain-rest-source',
                'isEnabled'     => true,
                'location'      => 'https://api.example.invalid',
                'configuration' => [],
            ]
        );

        $service->call(source: $source, endpoint: '/v1/items');

        $logs = $this->savedCallLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('GET', $logs[0]['object']['request']['method']);
    }//end testSourceWithoutListMethodOverrideStillDispatchesGet()


    /**
     * oc#94 — body-based pagination substitutes the page value into the
     * source's static JSON body template at the configured dot-path, leaving
     * every other field in the template untouched, across three consecutive
     * pages (mirrors how `SynchronizationService::getNextPage()` re-issues one
     * brokered/Guzzle call per page with an incrementing `pagination.page`).
     *
     * @return void
     */
    public function testBodyBasedPaginationSubstitutesPageAcrossThreePages(): void
    {
        $dispatched = [];
        $brokered   = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willReturn(
            [
                'credentialId' => '00000000-0000-0000-0000-000000000000',
                'actingUserId' => null,
            ]
        );
        $brokered->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(
                function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use (&$dispatched) {
                    $dispatched[] = $config;

                    return new Response(200, ['Content-Type' => ['application/json']], '{"notices":[]}');
                }
            );

        $service = $this->buildBrokeredCallService($brokered);

        $bodyTemplate  = '{"query":"classification-cpv = 48000000*","page":1,"limit":50}';
        $configuration = [
            'listMethod'     => 'POST',
            'body'           => $bodyTemplate,
            'authentication' => [
                'credentialRef' => ['credentialId' => '00000000-0000-0000-0000-000000000000'],
            ],
        ];
        $source = $this->makeBrokeredSource(configuration: $configuration);

        foreach ([1, 2, 3] as $page) {
            $service->call(
                source: $source,
                endpoint: '/v3/notices',
                config: [
                    'pagination' => [
                        'paginationQuery' => 'page',
                        'paginationIn'    => 'body',
                        'page'            => $page,
                    ],
                ],
            );
        }

        $this->assertCount(3, $dispatched);
        foreach ([1, 2, 3] as $index => $expectedPage) {
            $decodedBody = json_decode($dispatched[$index]['body'], true);
            $this->assertSame($expectedPage, $decodedBody['page'], "page {$expectedPage} substitution");
            // Every other field in the static template is untouched.
            $this->assertSame('classification-cpv = 48000000*', $decodedBody['query']);
            $this->assertSame(50, $decodedBody['limit']);
        }
    }//end testBodyBasedPaginationSubstitutesPageAcrossThreePages()


    /**
     * oc#94 edge case — `paginationIn: "body"` with no static
     * `configuration.body` template still produces a sane body (`{"page":
     * N}`) instead of silently dropping the pagination directive.
     *
     * @return void
     */
    public function testBodyBasedPaginationWithoutStaticBodyTemplateStillSetsPageKey(): void
    {
        $dispatched = [];
        $brokered   = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willReturn(
            [
                'credentialId' => '00000000-0000-0000-0000-000000000000',
                'actingUserId' => null,
            ]
        );
        $brokered->method('dispatch')->willReturnCallback(
            function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use (&$dispatched) {
                $dispatched[] = $config;

                return new Response(200, [], '{"notices":[]}');
            }
        );

        $service = $this->buildBrokeredCallService($brokered);
        $source  = $this->makeBrokeredSource();

        $service->call(
            source: $source,
            endpoint: '/v3/notices',
            config: [
                'pagination' => [
                    'paginationQuery' => 'page',
                    'paginationIn'    => 'body',
                    'page'            => 2,
                ],
            ],
        );

        $this->assertSame(['page' => 2], json_decode($dispatched[0]['body'], true));
    }//end testBodyBasedPaginationWithoutStaticBodyTemplateStillSetsPageKey()


    /**
     * oc#94 regression — query-string pagination (the pre-existing default,
     * `paginationIn` omitted) is unaffected by the body-pagination branch.
     * Complements `testBrokeredPaginationOneRequestPerPageWithRateLimitTracking`
     * (which already covers this path) with an explicit assertion that no
     * `body` key is introduced.
     *
     * @return void
     */
    public function testQueryPaginationUnaffectedWhenPaginationInOmitted(): void
    {
        $dispatched = [];
        $brokered   = $this->createMock(BrokeredCallService::class);
        $brokered->method('hasCredentialRef')->willReturn(true);
        $brokered->method('prepare')->willReturn(
            [
                'credentialId' => '00000000-0000-0000-0000-000000000000',
                'actingUserId' => null,
            ]
        );
        $brokered->method('dispatch')->willReturnCallback(
            function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config) use (&$dispatched) {
                $dispatched[] = $config;

                return new Response(200, [], '[]');
            }
        );

        $service = $this->buildBrokeredCallService($brokered);
        $source  = $this->makeBrokeredSource();

        $service->call(
            source: $source,
            endpoint: '/v1/items',
            config: [
                'pagination' => [
                    'paginationQuery' => 'page',
                    'page'            => 4,
                ],
            ],
        );

        $this->assertSame(4, $dispatched[0]['query']['page']);
        $this->assertArrayNotHasKey('body', $dispatched[0]);
    }//end testQueryPaginationUnaffectedWhenPaginationInOmitted()


    /**
     * REQ-STUF-011 scenario "Client certificate used for mTLS request": a
     * string PEM certificate configured on a StUF (or any mTLS) Source is
     * written to a temp file by `getCertificate()`, the config is rewritten
     * to point at that file (so Guzzle's `cert` option receives a path, not
     * PEM content), and the file is real / readable on disk.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateWritesCertToTempFile(): void
    {
        // Arrange
        $pem    = "-----BEGIN CERTIFICATE-----\nMIIBAjCB...fixture...\n-----END CERTIFICATE-----";
        $config = ['cert' => $pem];

        // Act
        $this->service->getCertificate($config);

        // Assert: config now holds a filesystem path, not the raw PEM.
        $this->assertIsString($config['cert']);
        $this->assertNotSame($pem, $config['cert']);
        $this->assertFileExists($config['cert']);
        $this->assertSame($pem, file_get_contents($config['cert']));

        // Cleanup via the service's own removal path (also exercises it).
        $this->service->removeFiles($config);
        $this->assertFileDoesNotExist($config['cert']);
    }//end testGetCertificateWritesCertToTempFile()


    /**
     * REQ-STUF-011 scenario "Client certificate used for mTLS request",
     * SSL-key variant: `ssl_key` is written to disk exactly like `cert`.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateWritesSslKeyToTempFile(): void
    {
        // Arrange
        $key    = "-----BEGIN PRIVATE KEY-----\nMIIEvQ...fixture...\n-----END PRIVATE KEY-----";
        $config = ['ssl_key' => $key];

        // Act
        $this->service->getCertificate($config);

        // Assert
        $this->assertFileExists($config['ssl_key']);
        $this->assertSame($key, file_get_contents($config['ssl_key']));

        $this->service->removeFiles($config);
        $this->assertFileDoesNotExist($config['ssl_key']);
    }//end testGetCertificateWritesSslKeyToTempFile()


    /**
     * REQ-STUF-011 scenario "Escaped newlines in PEM converted correctly":
     * a PEM stored with literal `\n` escape sequences (as it would arrive
     * from a JSON-encoded Source configuration) is converted to real
     * newline bytes on write, asserted byte-for-byte.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateConvertsEscapedNewlines(): void
    {
        // Arrange: literal backslash-n sequences, as stored in a JSON field.
        $escaped  = '-----BEGIN CERTIFICATE-----\nMIIBAjCB...fixture...\n-----END CERTIFICATE-----';
        $expected = "-----BEGIN CERTIFICATE-----\nMIIBAjCB...fixture...\n-----END CERTIFICATE-----";
        $config   = ['cert' => $escaped];

        // Act
        $this->service->getCertificate($config);

        // Assert byte-for-byte: no stray literal backslash-n left in the file.
        $written = file_get_contents($config['cert']);
        $this->assertSame($expected, $written);
        $this->assertStringNotContainsString('\\n', $written);

        $this->service->removeFiles($config);
    }//end testGetCertificateConvertsEscapedNewlines()


    /**
     * REQ-STUF-011: `cert` supplied as a `[pem, password]` array (Guzzle's
     * client-cert-with-passphrase shape) writes only the PEM element
     * (index 0) to disk and preserves the password element untouched.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testGetCertificateWritesArrayFormCertPreservingPassword(): void
    {
        // Arrange
        $pem    = "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----";
        $config = ['cert' => [$pem, 'super-secret-passphrase']];

        // Act
        $this->service->getCertificate($config);

        // Assert
        $this->assertFileExists($config['cert'][0]);
        $this->assertSame($pem, file_get_contents($config['cert'][0]));
        $this->assertSame('super-secret-passphrase', $config['cert'][1]);

        $this->service->removeFiles($config);
        $this->assertFileDoesNotExist($config['cert'][0]);
    }//end testGetCertificateWritesArrayFormCertPreservingPassword()


    /**
     * REQ-STUF-011: `removeFiles()` cleans up cert + ssl_key + verify
     * together in a single call, mirroring the real teardown path in
     * `CallService::call()` after both the success and exception branches.
     *
     * @return void
     *
     * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
     */
    public function testRemoveFilesCleansUpCertSslKeyAndVerifyTogether(): void
    {
        // Arrange
        $config = [
            'cert'    => "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----",
            'ssl_key' => "-----BEGIN PRIVATE KEY-----\nfixture\n-----END PRIVATE KEY-----",
            'verify'  => "-----BEGIN CERTIFICATE-----\nca-fixture\n-----END CERTIFICATE-----",
        ];
        $this->service->getCertificate($config);
        $certPath   = $config['cert'];
        $keyPath    = $config['ssl_key'];
        $verifyPath = $config['verify'];

        $this->assertFileExists($certPath);
        $this->assertFileExists($keyPath);
        $this->assertFileExists($verifyPath);

        // Act
        $this->service->removeFiles($config);

        // Assert: every temp file is gone (exception-path cleanup is
        // identical — `removeFiles()` is called the same way from both the
        // success and `finally`/catch branches in `call()`).
        $this->assertFileDoesNotExist($certPath);
        $this->assertFileDoesNotExist($keyPath);
        $this->assertFileDoesNotExist($verifyPath);
    }//end testRemoveFilesCleansUpCertSslKeyAndVerifyTogether()


}//end class
