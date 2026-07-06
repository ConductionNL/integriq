<?php
/**
 * Unit tests for BrokeredCallService (source-broker-credentials).
 *
 * Covers REQ-SBC-001 (credentialRef contract: shape validation, sibling-secret
 * rejection, name resolution 0/1/many), REQ-SBC-002 (request derivation,
 * PSR-7 adaptation, v1 scope guards), REQ-SBC-003 (acting-user threading with
 * reflection-based feature detection), and REQ-SBC-004 (soft-fail without a
 * broker, secret-free 403/502 mapping).
 *
 * The fake brokers below carry the EXACT request() signature verified against
 * openregister origin/development — FakeLegacyBroker without the acting-user
 * parameter, FakeActingUserBroker with it (the shape `credential-doriath-leaf`
 * ships).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\BrokeredCallConfigurationException;
use OCA\OpenConnector\Service\BrokeredCallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Testable subclass exposing the broker seams (class availability + resolution).
 */
class TestableBrokeredCallService extends BrokeredCallService
{

    /**
     * Whether the broker class should report as loadable.
     *
     * @var boolean
     */
    public bool $brokerClassAvailable = true;

    /**
     * The broker double returned by resolveBroker().
     *
     * @var object|null
     */
    public ?object $brokerInstance = null;


    /**
     * Overridden seam: broker class availability.
     *
     * @return boolean
     */
    protected function isBrokerClassAvailable(): bool
    {
        return $this->brokerClassAvailable;
    }//end isBrokerClassAvailable()


    /**
     * Overridden seam: broker resolution (never touches \OCP\Server in tests).
     *
     * @return object
     */
    protected function resolveBroker(): object
    {
        if ($this->brokerInstance === null) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef is configured but the OpenRegister credential broker could not be resolved.'
            );
        }

        return $this->brokerInstance;
    }//end resolveBroker()
}//end class

/**
 * Fake broker with the origin/development signature (NO acting-user parameter).
 */
class FakeLegacyBroker
{

    /**
     * Recorded request() invocations.
     *
     * @var array
     */
    public array $calls = [];

    /**
     * The array{status, headers, body} result to return.
     *
     * @var array
     */
    public array $result = [
        'status'  => 200,
        'headers' => ['X-Test' => ['1']],
        'body'    => '{"ok":true}',
    ];

    /**
     * Optional exception thrown instead of returning.
     *
     * @var \Throwable|null
     */
    public ?\Throwable $throwable = null;


    /**
     * Mirror of CredentialBrokerService::request() on OR origin/development.
     *
     * @param string      $credentialId The credential UUID.
     * @param string      $appId        The calling app id.
     * @param string      $method       The HTTP method.
     * @param string      $path         The provider-relative path.
     * @param array       $headers      Extra request headers.
     * @param string|null $body         Raw request body.
     *
     * @return array The upstream status/headers/body.
     */
    public function request(
        string $credentialId,
        string $appId,
        string $method,
        string $path,
        array $headers=[],
        ?string $body=null
    ): array {
        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        $this->calls[] = [
            'credentialId' => $credentialId,
            'appId'        => $appId,
            'method'       => $method,
            'path'         => $path,
            'headers'      => $headers,
            'body'         => $body,
        ];

        return $this->result;
    }//end request()
}//end class

/**
 * Fake broker WITH the optional acting-user parameter (credential-doriath-leaf shape).
 */
class FakeActingUserBroker
{

    /**
     * Recorded request() invocations.
     *
     * @var array
     */
    public array $calls = [];

    /**
     * The array{status, headers, body} result to return.
     *
     * @var array
     */
    public array $result = [
        'status'  => 200,
        'headers' => [],
        'body'    => '',
    ];


    /**
     * request() with the optional in-process acting-user parameter.
     *
     * @param string      $credentialId The credential UUID.
     * @param string      $appId        The calling app id.
     * @param string      $method       The HTTP method.
     * @param string      $path         The provider-relative path.
     * @param array       $headers      Extra request headers.
     * @param string|null $body         Raw request body.
     * @param string|null $actingUserId Acting user for in-process trusted callers.
     *
     * @return array The upstream status/headers/body.
     */
    public function request(
        string $credentialId,
        string $appId,
        string $method,
        string $path,
        array $headers=[],
        ?string $body=null,
        ?string $actingUserId=null
    ): array {
        $this->calls[] = [
            'credentialId' => $credentialId,
            'appId'        => $appId,
            'method'       => $method,
            'path'         => $path,
            'headers'      => $headers,
            'body'         => $body,
            'actingUserId' => $actingUserId,
        ];

        return $this->result;
    }//end request()
}//end class

/**
 * Tests for the brokered (credentialRef) dispatch service.
 */
class BrokeredCallServiceTest extends TestCase
{
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * @var TestableBrokeredCallService
     */
    private TestableBrokeredCallService $service;

    /**
     * @var ObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appManager;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;


    /**
     * Set up a service with a session user, an enabled openregister, and an
     * existing credential owned by that user.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->appManager    = $this->createMock(IAppManager::class);
        $this->userSession   = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->objectService->method('find')->willReturnCallback(
            function () {
                return $this->makeCredential(uuid: self::NIL_UUID, owner: 'alice');
            }
        );

        $this->service = $this->buildService();
    }//end setUp()


    /**
     * Build a testable service against the current mocks.
     *
     * @return TestableBrokeredCallService
     */
    private function buildService(): TestableBrokeredCallService
    {
        $service = new TestableBrokeredCallService(
            $this->objectService,
            $this->appManager,
            $this->userSession,
            $this->logger,
        );
        $service->brokerInstance = new FakeLegacyBroker();

        return $service;
    }//end buildService()


    /**
     * Hydrate a credential metadata ObjectEntity.
     *
     * @param string $uuid  The credential UUID.
     * @param string $owner The owner uid.
     *
     * @return ObjectEntity
     */
    private function makeCredential(string $uuid, string $owner): ObjectEntity
    {
        $credential = new ObjectEntity();
        $credential->setUuid($uuid);
        $credential->setOwner($owner);
        $credential->setObject(['name' => 'doffin-subscription', 'allowedApps' => ['openconnector']]);

        return $credential;
    }//end makeCredential()


    /**
     * Merged config carrying a clean credentialId ref.
     *
     * @param array $extra Extra config keys merged on top.
     *
     * @return array
     */
    private function brokeredConfig(array $extra=[]): array
    {
        return array_merge(
            ['authentication' => ['credentialRef' => ['credentialId' => self::NIL_UUID]]],
            $extra
        );
    }//end brokeredConfig()


    // -------------------------------------------------------------------
    // Detection helpers (REQ-SBC-001 / REQ-SBC-002 selection).
    // -------------------------------------------------------------------


    /**
     * isBrokered() detects credentialRef on the raw source object shape.
     *
     * @return void
     */
    public function testIsBrokeredDetectsSourceLevelCredentialRef(): void
    {
        $sourceData = ['configuration' => ['authentication' => ['credentialRef' => ['credentialId' => self::NIL_UUID]]]];

        $this->assertTrue($this->service->isBrokered($sourceData));
        $this->assertFalse($this->service->isBrokered(['configuration' => ['authentication' => ['apikey' => 'x']]]));
        $this->assertFalse($this->service->isBrokered([]));
    }//end testIsBrokeredDetectsSourceLevelCredentialRef()


    /**
     * hasCredentialRef() detects the merged call configuration shape.
     *
     * @return void
     */
    public function testHasCredentialRefDetectsMergedConfig(): void
    {
        $this->assertTrue($this->service->hasCredentialRef($this->brokeredConfig()));
        $this->assertFalse($this->service->hasCredentialRef(['authentication' => ['apikey' => 'x']]));
        $this->assertFalse($this->service->hasCredentialRef(['headers' => []]));
    }//end testHasCredentialRefDetectsMergedConfig()


    // -------------------------------------------------------------------
    // prepare(): happy path + shape validation (REQ-SBC-001).
    // -------------------------------------------------------------------


    /**
     * A clean credentialId ref with a session resolves without an acting user.
     *
     * @return void
     */
    public function testPrepareHappyPathWithCredentialIdAndSession(): void
    {
        $result = $this->service->prepare(
            config: $this->brokeredConfig(),
            sourceData: ['type' => 'api'],
            asynchronous: false,
        );

        $this->assertSame(self::NIL_UUID, $result['credentialId']);
        $this->assertNull($result['actingUserId']);
    }//end testPrepareHappyPathWithCredentialIdAndSession()


    /**
     * Sibling embedded secret fields are a hard config error naming only KEYS.
     *
     * @return void
     */
    public function testPrepareRejectsSiblingSecretFields(): void
    {
        $config = [
            'authentication' => [
                'credentialRef' => ['credentialId' => self::NIL_UUID],
                'client_secret' => 'YOUR_API_KEY_HERE',
            ],
        ];

        try {
            $this->service->prepare(config: $config, sourceData: [], asynchronous: false);
            $this->fail('Expected BrokeredCallConfigurationException');
        } catch (BrokeredCallConfigurationException $exception) {
            $this->assertStringContainsString('forbidden alongside credentialRef', $exception->getMessage());
            $this->assertStringContainsString('client_secret', $exception->getMessage());
            // Secret hygiene: the VALUE must never appear in the error.
            $this->assertStringNotContainsString('YOUR_API_KEY_HERE', $exception->getMessage());
        }
    }//end testPrepareRejectsSiblingSecretFields()


    /**
     * Setting both credentialId and credentialName is a hard config error.
     *
     * @return void
     */
    public function testPrepareRejectsBothIdAndName(): void
    {
        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('not both');

        $this->service->prepare(
            config: ['authentication' => ['credentialRef' => ['credentialId' => self::NIL_UUID, 'credentialName' => 'x']]],
            sourceData: [],
            asynchronous: false,
        );
    }//end testPrepareRejectsBothIdAndName()


    /**
     * Empty / nil values are a hard config error.
     *
     * @return void
     */
    public function testPrepareRejectsEmptyValue(): void
    {
        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('non-empty string');

        $this->service->prepare(
            config: ['authentication' => ['credentialRef' => ['credentialId' => '  ']]],
            sourceData: [],
            asynchronous: false,
        );
    }//end testPrepareRejectsEmptyValue()


    /**
     * A non-object credentialRef is a hard config error.
     *
     * @return void
     */
    public function testPrepareRejectsNonObjectRef(): void
    {
        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('must be an object');

        $this->service->prepare(
            config: ['authentication' => ['credentialRef' => self::NIL_UUID]],
            sourceData: [],
            asynchronous: false,
        );
    }//end testPrepareRejectsNonObjectRef()


    /**
     * Unknown keys inside credentialRef are a hard config error.
     *
     * @return void
     */
    public function testPrepareRejectsUnknownRefKeys(): void
    {
        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('only accepts credentialId or credentialName');

        $this->service->prepare(
            config: ['authentication' => ['credentialRef' => ['credentialId' => self::NIL_UUID, 'secret' => 'x']]],
            sourceData: [],
            asynchronous: false,
        );
    }//end testPrepareRejectsUnknownRefKeys()


    // -------------------------------------------------------------------
    // prepare(): v1 scope guards (REQ-SBC-002 / D8).
    // -------------------------------------------------------------------


    /**
     * credentialRef on a SOAP source is rejected in v1.
     *
     * @return void
     */
    public function testPrepareRejectsSoapSource(): void
    {
        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('SOAP');

        $this->service->prepare(
            config: $this->brokeredConfig(),
            sourceData: ['type' => 'soap'],
            asynchronous: false,
        );
    }//end testPrepareRejectsSoapSource()


    /**
     * Asynchronous dispatch with credentialRef is rejected in v1.
     *
     * @return void
     */
    public function testPrepareRejectsAsynchronousDispatch(): void
    {
        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('asynchronous');

        $this->service->prepare(
            config: $this->brokeredConfig(),
            sourceData: [],
            asynchronous: true,
        );
    }//end testPrepareRejectsAsynchronousDispatch()


    /**
     * TLS client-certificate config alongside credentialRef is rejected in v1.
     *
     * @return void
     */
    public function testPrepareRejectsClientCertificateConfig(): void
    {
        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('cert');

        $this->service->prepare(
            config: $this->brokeredConfig(['cert' => '/tmp/client.pem']),
            sourceData: [],
            asynchronous: false,
        );
    }//end testPrepareRejectsClientCertificateConfig()


    // -------------------------------------------------------------------
    // prepare(): soft-fail availability guards (REQ-SBC-004).
    // -------------------------------------------------------------------


    /**
     * Broker class absent → 409 config error, no fallback.
     *
     * @return void
     */
    public function testPrepareSoftFailsWhenBrokerClassAbsent(): void
    {
        $this->service->brokerClassAvailable = false;

        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('credential broker is unavailable');

        $this->service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);
    }//end testPrepareSoftFailsWhenBrokerClassAbsent()


    /**
     * openregister disabled → 409 config error, no fallback.
     *
     * @return void
     */
    public function testPrepareSoftFailsWhenOpenRegisterDisabled(): void
    {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $service = $this->buildService();

        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('credential broker is unavailable');

        $service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);
    }//end testPrepareSoftFailsWhenOpenRegisterDisabled()


    /**
     * Referenced credential gone → 409 config error naming the reference.
     *
     * @return void
     */
    public function testPrepareSoftFailsWhenCredentialGone(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('find')->willReturn(null);
        $service = $this->buildService();

        try {
            $service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);
            $this->fail('Expected BrokeredCallConfigurationException');
        } catch (BrokeredCallConfigurationException $exception) {
            $this->assertStringContainsString('was not found', $exception->getMessage());
            $this->assertStringContainsString(self::NIL_UUID, $exception->getMessage());
        }
    }//end testPrepareSoftFailsWhenCredentialGone()


    // -------------------------------------------------------------------
    // prepare(): credentialName resolution 0 / 1 / many (REQ-SBC-001 / D5).
    // -------------------------------------------------------------------


    /**
     * Configure findAll to return the given credential entities.
     *
     * @param array $rows The ObjectEntity rows.
     *
     * @return void
     */
    private function stubFindAll(array $rows): void
    {
        $this->objectService->method('findAll')->willReturn(['results' => $rows, 'total' => count($rows)]);
    }//end stubFindAll()


    /**
     * Exactly one owned match resolves the name to its credentialId.
     *
     * @return void
     */
    public function testPrepareResolvesUniqueCredentialName(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->stubFindAll([$this->makeCredential(uuid: self::NIL_UUID, owner: 'alice')]);
        $this->objectService->method('find')->willReturn($this->makeCredential(uuid: self::NIL_UUID, owner: 'alice'));
        $service = $this->buildService();

        $result = $service->prepare(
            config: ['authentication' => ['credentialRef' => ['credentialName' => 'doffin-subscription']]],
            sourceData: [],
            asynchronous: false,
        );

        $this->assertSame(self::NIL_UUID, $result['credentialId']);
    }//end testPrepareResolvesUniqueCredentialName()


    /**
     * Two owned matches → hard config error naming the reference and count (2).
     *
     * @return void
     */
    public function testPrepareRejectsAmbiguousCredentialName(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->stubFindAll(
            [
                $this->makeCredential(uuid: 'uuid-a', owner: 'alice'),
                $this->makeCredential(uuid: 'uuid-b', owner: 'alice'),
            ]
        );
        $service = $this->buildService();

        try {
            $service->prepare(
                config: ['authentication' => ['credentialRef' => ['credentialName' => 'doffin-subscription']]],
                sourceData: [],
                asynchronous: false,
            );
            $this->fail('Expected BrokeredCallConfigurationException');
        } catch (BrokeredCallConfigurationException $exception) {
            $this->assertStringContainsString('doffin-subscription', $exception->getMessage());
            $this->assertStringContainsString('2 credentials', $exception->getMessage());
        }
    }//end testPrepareRejectsAmbiguousCredentialName()


    /**
     * Zero matches for the acting user → hard config error with count 0.
     *
     * Matches owned by OTHER users are excluded from the session-scoped match.
     *
     * @return void
     */
    public function testPrepareRejectsUnresolvedCredentialName(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->stubFindAll([$this->makeCredential(uuid: 'uuid-bob', owner: 'bob')]);
        $service = $this->buildService();

        try {
            $service->prepare(
                config: ['authentication' => ['credentialRef' => ['credentialName' => 'doffin-subscription']]],
                sourceData: [],
                asynchronous: false,
            );
            $this->fail('Expected BrokeredCallConfigurationException');
        } catch (BrokeredCallConfigurationException $exception) {
            $this->assertStringContainsString('0 credentials', $exception->getMessage());
        }
    }//end testPrepareRejectsUnresolvedCredentialName()


    /**
     * Name resolution is memoised per run: two prepares, one findAll query.
     *
     * @return void
     */
    public function testPrepareMemoisesNameResolutionPerRun(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->expects($this->once())
            ->method('findAll')
            ->willReturn(['results' => [$this->makeCredential(uuid: self::NIL_UUID, owner: 'alice')]]);
        $this->objectService->method('find')->willReturn($this->makeCredential(uuid: self::NIL_UUID, owner: 'alice'));
        $service = $this->buildService();

        $config = ['authentication' => ['credentialRef' => ['credentialName' => 'doffin-subscription']]];
        $first  = $service->prepare(config: $config, sourceData: [], asynchronous: false);
        $second = $service->prepare(config: $config, sourceData: [], asynchronous: false);

        $this->assertSame($first['credentialId'], $second['credentialId']);
    }//end testPrepareMemoisesNameResolutionPerRun()


    // -------------------------------------------------------------------
    // prepare(): acting user for sessionless calls (REQ-SBC-003).
    // -------------------------------------------------------------------


    /**
     * Sessionless + legacy broker (no acting-user param) → soft-fail, no TypeError.
     *
     * @return void
     */
    public function testPrepareSessionlessLegacyBrokerSoftFails(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $service = $this->buildService();
        $service->brokerInstance = new FakeLegacyBroker();

        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessage('actingUserId');

        $service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);
    }//end testPrepareSessionlessLegacyBrokerSoftFails()


    /**
     * Sessionless + acting-user-capable broker → actingUserId = credential owner.
     *
     * @return void
     */
    public function testPrepareSessionlessActingUserBrokerReturnsOwner(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $service = $this->buildService();
        $service->brokerInstance = new FakeActingUserBroker();

        $result = $service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);

        $this->assertSame('alice', $result['actingUserId']);
    }//end testPrepareSessionlessActingUserBrokerReturnsOwner()


    // -------------------------------------------------------------------
    // dispatch(): derivation, adaptation, error mapping (REQ-SBC-002/004).
    // -------------------------------------------------------------------


    /**
     * Path+query derivation, header normalisation, json body, appId — all forwarded.
     *
     * @return void
     */
    public function testDispatchDerivesPathQueryHeadersAndBody(): void
    {
        $broker = new FakeLegacyBroker();
        $this->service->brokerInstance = $broker;

        $response = $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'POST',
            url: 'https://api.example.org/v1/items?a=1',
            config: [
                'query'   => ['b' => '2'],
                'headers' => [
                    'Accept'   => 'application/json',
                    'X-Multi'  => ['one', 'two'],
                ],
                'json'    => ['hello' => 'world'],
            ],
        );

        $this->assertCount(1, $broker->calls);
        $call = $broker->calls[0];
        $this->assertSame(self::NIL_UUID, $call['credentialId']);
        $this->assertSame('openconnector', $call['appId']);
        $this->assertSame('POST', $call['method']);
        // Path + query only — the provider host-lock is the sole host authority.
        $this->assertSame('/v1/items?a=1&b=2', $call['path']);
        $this->assertSame('application/json', $call['headers']['Accept']);
        $this->assertSame('one, two', $call['headers']['X-Multi']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
        $this->assertSame('{"hello":"world"}', $call['body']);
        $this->assertSame(200, $response->getStatusCode());
    }//end testDispatchDerivesPathQueryHeadersAndBody()


    /**
     * The broker's array return adapts to a PSR-7 response (status/headers/body).
     *
     * @return void
     */
    public function testDispatchAdaptsBrokerReturnToPsr7(): void
    {
        $broker = new FakeLegacyBroker();
        $broker->result = [
            'status'  => 200,
            'headers' => [
                'X-RateLimit-Remaining' => ['9'],
                'Content-Type'          => 'application/json',
            ],
            'body'    => '{"ok":true}',
        ];
        $this->service->brokerInstance = $broker;

        $response = $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'GET',
            url: 'https://api.example.org/v1/items',
            config: [],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('9', $response->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"ok":true}', (string) $response->getBody());
    }//end testDispatchAdaptsBrokerReturnToPsr7()


    /**
     * Upstream non-2xx via the broker is a COMPLETED call, not an error mapping.
     *
     * @return void
     */
    public function testDispatchUpstreamNon2xxIsCompletedCall(): void
    {
        $broker = new FakeLegacyBroker();
        $broker->result = [
            'status'  => 404,
            'headers' => [],
            'body'    => 'not found',
        ];
        $this->service->brokerInstance = $broker;

        $response = $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'GET',
            url: 'https://api.example.org/missing',
            config: [],
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not found', (string) $response->getBody());
    }//end testDispatchUpstreamNon2xxIsCompletedCall()


    /**
     * CredentialAccessDeniedException → 403 with the allowedApps hint; no payload leak.
     *
     * @return void
     */
    public function testDispatchMapsAccessDeniedTo403WithHint(): void
    {
        $broker = new FakeLegacyBroker();
        $broker->throwable = new CredentialAccessDeniedException('Request not permitted');
        $this->service->brokerInstance = $broker;

        $loggedContext = null;
        $this->logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(
                function ($message, $context) use (&$loggedContext) {
                    $loggedContext = $context;
                }
            );

        $response = $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'POST',
            url: 'https://api.example.org/v1/items',
            config: [
                'headers' => ['X-Api-Key' => 'sekret-value-123'],
                'body'    => 'super-secret-payload',
            ],
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Request not permitted', $response->getReasonPhrase());
        $this->assertStringContainsString('allowedApps', $response->getReasonPhrase());
        // Refusal logging is guard-family only: never the payload, never secrets.
        $this->assertStringNotContainsString('super-secret-payload', $response->getReasonPhrase());
        $this->assertStringNotContainsString('super-secret-payload', (string) $response->getBody());
        $this->assertStringNotContainsString('sekret-value-123', (string) $response->getBody());
        $this->assertNotNull($loggedContext);
        $this->assertStringNotContainsString('sekret-value-123', json_encode($loggedContext));
        $this->assertStringNotContainsString('super-secret-payload', json_encode($loggedContext));
    }//end testDispatchMapsAccessDeniedTo403WithHint()


    /**
     * CredentialUpstreamException → 502 CallLog mapping.
     *
     * @return void
     */
    public function testDispatchMapsUpstreamExceptionTo502(): void
    {
        $broker = new FakeLegacyBroker();
        $broker->throwable = new CredentialUpstreamException('Upstream request failed');
        $this->service->brokerInstance = $broker;

        $response = $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'GET',
            url: 'https://api.example.org/v1/items',
            config: [],
        );

        $this->assertSame(502, $response->getStatusCode());
        $this->assertStringContainsString('Upstream request failed', $response->getReasonPhrase());
    }//end testDispatchMapsUpstreamExceptionTo502()


    /**
     * A raw string body is forwarded verbatim (takes precedence over json).
     *
     * @return void
     */
    public function testDispatchForwardsRawBodyVerbatim(): void
    {
        $broker = new FakeLegacyBroker();
        $this->service->brokerInstance = $broker;

        $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'PUT',
            url: 'https://api.example.org/v1/items/1',
            config: [
                'body' => '<xml>payload</xml>',
                'json' => ['ignored' => true],
            ],
        );

        $this->assertSame('<xml>payload</xml>', $broker->calls[0]['body']);
    }//end testDispatchForwardsRawBodyVerbatim()


    /**
     * When the broker supports it, the acting user is passed by name — and the
     * legacy positional contract stays untouched for session calls.
     *
     * @return void
     */
    public function testDispatchPassesActingUserIdWhenSupported(): void
    {
        $broker = new FakeActingUserBroker();
        $this->service->brokerInstance = $broker;

        $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: 'alice',
            method: 'GET',
            url: 'https://api.example.org/v1/items',
            config: [],
        );

        $this->assertSame('alice', $broker->calls[0]['actingUserId']);
    }//end testDispatchPassesActingUserIdWhenSupported()


    /**
     * A bare host URL derives the root path '/'.
     *
     * @return void
     */
    public function testDispatchDerivesRootPathForBareHostUrl(): void
    {
        $broker = new FakeLegacyBroker();
        $this->service->brokerInstance = $broker;

        $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'GET',
            url: 'https://api.example.org',
            config: [],
        );

        $this->assertSame('/', $broker->calls[0]['path']);
    }//end testDispatchDerivesRootPathForBareHostUrl()


}//end class
