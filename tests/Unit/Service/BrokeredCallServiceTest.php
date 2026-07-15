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
use OCP\IUserManager;
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
     * Optional exception thrown instead of returning.
     *
     * @var \Throwable|null
     */
    public ?\Throwable $throwable = null;


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
            'actingUserId' => $actingUserId,
        ];

        return $this->result;
    }//end request()
}//end class

/**
 * Fake broker exposing resolveInjectable() for the app-side injection path.
 */
class FakeInjectingBroker
{

    /**
     * Recorded resolveInjectable() invocations.
     *
     * @var array
     */
    public array $resolveCalls = [];

    /**
     * The secret to return from resolveInjectable() (null simulates a proxy credential).
     *
     * @var string|null
     */
    public ?string $secret = 'VAULT-SECRET';

    /**
     * When true, resolveInjectable() returns null (the credential is a proxy credential).
     *
     * @var boolean
     */
    public bool $returnsNull = false;

    /**
     * Optional exception thrown by resolveInjectable() instead of returning.
     *
     * @var \Throwable|null
     */
    public ?\Throwable $throwable = null;


    /**
     * request() with the optional in-process acting-user parameter (feature-detection).
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
        return ['status' => 200, 'headers' => [], 'body' => ''];
    }//end request()


    /**
     * Resolve an inject-only credential's raw secret (guards 1+2 in production).
     *
     * @param string      $credentialId The credential UUID.
     * @param string      $appId        The calling app id.
     * @param string|null $actingUserId Acting user for sessionless in-process callers.
     *
     * @return string|null The raw secret, or null when the credential is a proxy credential.
     */
    public function resolveInjectable(
        string $credentialId,
        string $appId,
        ?string $actingUserId=null
    ): ?string {
        $this->resolveCalls[] = [
            'credentialId' => $credentialId,
            'appId'        => $appId,
            'actingUserId' => $actingUserId,
        ];

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        if ($this->returnsNull === true) {
            return null;
        }

        return $this->secret;
    }//end resolveInjectable()
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
     * @var IUserManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userManager;

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
        $this->userManager   = $this->createMock(IUserManager::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        // Default: the pinned owner "alice" resolves to an existing, enabled user.
        $this->userManager->method('get')->willReturnCallback(
            function (string $uid) {
                if ($uid === 'alice') {
                    return $this->makeUser(uid: 'alice', enabled: true);
                }

                return null;
            }
        );

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
            $this->userManager,
            $this->logger,
        );
        $service->brokerInstance = new FakeLegacyBroker();

        return $service;
    }//end buildService()


    /**
     * Build a mock IUser with the given uid and enabled state.
     *
     * @param string  $uid     The user id.
     * @param boolean $enabled Whether the account is enabled.
     *
     * @return IUser|\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeUser(string $uid, bool $enabled)
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('isEnabled')->willReturn($enabled);

        return $user;
    }//end makeUser()


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

        // Owner-pinning policy: the acting user is deterministically the
        // credential OWNER (alice) read from the OR metadata object.
        $this->assertSame('alice', $result['actingUserId']);
    }//end testPrepareSessionlessActingUserBrokerReturnsOwner()


    /**
     * Owner-pinning: an owner-less credential fails CLOSED with a config error.
     *
     * @return void
     */
    public function testPrepareSessionlessOwnerEmptyFailsClosed(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('find')->willReturn($this->makeCredential(uuid: self::NIL_UUID, owner: ''));
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $service = $this->buildService();
        $service->brokerInstance = new FakeActingUserBroker();

        try {
            $service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);
            $this->fail('Expected BrokeredCallConfigurationException');
        } catch (BrokeredCallConfigurationException $exception) {
            $this->assertStringContainsString('no owner recorded', $exception->getMessage());
            $this->assertStringContainsString(self::NIL_UUID, $exception->getMessage());
        }
    }//end testPrepareSessionlessOwnerEmptyFailsClosed()


    /**
     * Owner-pinning: a credential whose owner no longer exists fails CLOSED and
     * logs the guard name + owner uid (never a secret).
     *
     * @return void
     */
    public function testPrepareSessionlessOwnerGoneFailsClosed(): void
    {
        // The default userManager stub returns null for any uid but "alice";
        // a credential owned by "ghost" therefore resolves to a deleted user.
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('find')->willReturn($this->makeCredential(uuid: self::NIL_UUID, owner: 'ghost'));
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $loggedContext = null;
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(
                function ($message, $context) use (&$loggedContext) {
                    $loggedContext = $context;
                }
            );

        $service = $this->buildService();
        $service->brokerInstance = new FakeActingUserBroker();

        try {
            $service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);
            $this->fail('Expected BrokeredCallConfigurationException');
        } catch (BrokeredCallConfigurationException $exception) {
            $this->assertStringContainsString('no longer exists', $exception->getMessage());
            $this->assertStringContainsString('ghost', $exception->getMessage());
        }

        $this->assertNotNull($loggedContext);
        $this->assertSame('owner-gone', $loggedContext['guard']);
        $this->assertSame('ghost', $loggedContext['owner']);
    }//end testPrepareSessionlessOwnerGoneFailsClosed()


    /**
     * Owner-pinning: a credential whose owner is disabled fails CLOSED with a
     * distinct config error (distinct from owner-gone).
     *
     * @return void
     */
    public function testPrepareSessionlessOwnerDisabledFailsClosed(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('find')->willReturn($this->makeCredential(uuid: self::NIL_UUID, owner: 'bob'));
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        // Rebuild the userManager so "bob" resolves to a DISABLED account.
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userManager->method('get')->willReturnCallback(
            function (string $uid) {
                if ($uid === 'bob') {
                    return $this->makeUser(uid: 'bob', enabled: false);
                }

                return null;
            }
        );

        $service = $this->buildService();
        $service->brokerInstance = new FakeActingUserBroker();

        try {
            $service->prepare(config: $this->brokeredConfig(), sourceData: [], asynchronous: false);
            $this->fail('Expected BrokeredCallConfigurationException');
        } catch (BrokeredCallConfigurationException $exception) {
            $this->assertStringContainsString('is disabled', $exception->getMessage());
            $this->assertStringContainsString('bob', $exception->getMessage());
        }
    }//end testPrepareSessionlessOwnerDisabledFailsClosed()


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
     * Sessionless refusal → 403 carries the distinct un-migrated-secret hint
     * (the vault→Doriath migration only runs in a user session), still secret-free.
     *
     * @return void
     */
    public function testDispatchSessionlessAccessDeniedIncludesMigrationHint(): void
    {
        // The sessionless path passes actingUserId to the broker, so it must be
        // the acting-user-capable broker shape; drive the refusal via its hook.
        $broker = new FakeActingUserBroker();
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
            actingUserId: 'alice',
            method: 'GET',
            url: 'https://api.example.org/v1/items',
            config: ['headers' => ['X-Api-Key' => 'sekret-value-123']],
        );

        $this->assertSame(403, $response->getStatusCode());
        // Distinct, actionable migration hint present on the sessionless path.
        $this->assertStringContainsString('migrated to Doriath', $response->getReasonPhrase());
        $this->assertStringContainsString('background job', $response->getReasonPhrase());
        // Still guard-family only: no secret material.
        $this->assertStringNotContainsString('sekret-value-123', $response->getReasonPhrase());
        $this->assertStringNotContainsString('sekret-value-123', json_encode($loggedContext));
        $this->assertTrue($loggedContext['sessionless']);
    }//end testDispatchSessionlessAccessDeniedIncludesMigrationHint()


    /**
     * Session (interactive) refusal → 403 keeps the allowedApps hint but OMITS
     * the sessionless migration hint (that failure mode cannot occur in-session).
     *
     * @return void
     */
    public function testDispatchSessionAccessDeniedOmitsMigrationHint(): void
    {
        $broker = new FakeLegacyBroker();
        $broker->throwable = new CredentialAccessDeniedException('Request not permitted');
        $this->service->brokerInstance = $broker;

        $response = $this->service->dispatch(
            credentialId: self::NIL_UUID,
            actingUserId: null,
            method: 'POST',
            url: 'https://api.example.org/v1/items',
            config: [],
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('allowedApps', $response->getReasonPhrase());
        $this->assertStringNotContainsString('migrated to Doriath', $response->getReasonPhrase());
    }//end testDispatchSessionAccessDeniedOmitsMigrationHint()


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


    // -------------------------------------------------------------------
    // App-side injection: hasInjectableCredentials() + hydrateInjectableCredentials().
    // -------------------------------------------------------------------


    /**
     * Build source data whose authentication carries a nested credential placeholder.
     *
     * @param array $authentication The authentication block.
     *
     * @return array
     */
    private function injectableSource(array $authentication): array
    {
        return ['configuration' => ['authentication' => $authentication]];
    }//end injectableSource()


    /**
     * hasInjectableCredentials() detects a NESTED placeholder but not the top-level proxy form.
     *
     * @return void
     */
    public function testHasInjectableCredentialsDetectsNestedPlaceholderOnly(): void
    {
        // Nested placeholder (apikey position) → injectable.
        $this->assertTrue(
            $this->service->hasInjectableCredentials(
                $this->injectableSource(['apikey' => ['credentialRef' => ['credentialId' => self::NIL_UUID]]])
            )
        );

        // Top-level proxy credentialRef → NOT an injectable (handled by the proxy path).
        $this->assertFalse(
            $this->service->hasInjectableCredentials(
                $this->injectableSource(['credentialRef' => ['credentialId' => self::NIL_UUID]])
            )
        );

        // Plain embedded secret → nothing to inject.
        $this->assertFalse(
            $this->service->hasInjectableCredentials($this->injectableSource(['apikey' => 'literal']))
        );

        // No authentication block at all.
        $this->assertFalse($this->service->hasInjectableCredentials(['configuration' => []]));
    }//end testHasInjectableCredentialsDetectsNestedPlaceholderOnly()


    /**
     * hydrateInjectableCredentials() replaces a placeholder with the vault secret in place.
     *
     * @return void
     */
    public function testHydrateReplacesPlaceholderWithSecret(): void
    {
        $broker = new FakeInjectingBroker();
        $broker->secret = 'sk-live-XYZ';
        $this->service->brokerInstance = $broker;

        $source = $this->injectableSource(
            [
                'type'   => 'apikey',
                'apikey' => ['credentialRef' => ['credentialId' => self::NIL_UUID]],
            ]
        );

        $hydrated = $this->service->hydrateInjectableCredentials($source);

        $this->assertSame('sk-live-XYZ', $hydrated['configuration']['authentication']['apikey']);
        // Non-secret scaffolding is preserved verbatim.
        $this->assertSame('apikey', $hydrated['configuration']['authentication']['type']);
        // The broker was asked for exactly this credential, as openconnector.
        $this->assertSame(self::NIL_UUID, $broker->resolveCalls[0]['credentialId']);
        $this->assertSame('openconnector', $broker->resolveCalls[0]['appId']);
    }//end testHydrateReplacesPlaceholderWithSecret()


    /**
     * A DEEPLY nested placeholder (e.g. OAuth client_secret) is resolved too.
     *
     * @return void
     */
    public function testHydrateReplacesDeeplyNestedPlaceholder(): void
    {
        $broker = new FakeInjectingBroker();
        $broker->secret = 'client-secret-value';
        $this->service->brokerInstance = $broker;

        $source = $this->injectableSource(
            [
                'type'      => 'oauth',
                'client_id' => 'public-client-id',
                'oauth'     => [
                    'tokenUrl'      => 'https://idp.example.org/token',
                    'client_secret' => ['credentialRef' => ['credentialName' => 'my-oauth']],
                ],
            ]
        );

        // credentialName resolution finds exactly one owned credential.
        $this->objectService->method('findAll')->willReturn(
            ['results' => [$this->makeCredential(uuid: self::NIL_UUID, owner: 'alice')]]
        );

        $hydrated = $this->service->hydrateInjectableCredentials($source);

        $this->assertSame(
            'client-secret-value',
            $hydrated['configuration']['authentication']['oauth']['client_secret']
        );
        $this->assertSame('public-client-id', $hydrated['configuration']['authentication']['client_id']);
    }//end testHydrateReplacesDeeplyNestedPlaceholder()


    /**
     * A proxy credential (resolveInjectable returns null) is a hard config error, not injected.
     *
     * @return void
     */
    public function testHydrateRefusesProxyCredential(): void
    {
        $broker = new FakeInjectingBroker();
        $broker->returnsNull = true;
        $this->service->brokerInstance = $broker;

        $source = $this->injectableSource(
            ['apikey' => ['credentialRef' => ['credentialId' => self::NIL_UUID]]]
        );

        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessageMatches('/proxy credential|inject-only|generic/i');
        $this->service->hydrateInjectableCredentials($source);
    }//end testHydrateRefusesProxyCredential()


    /**
     * A broker refusal (guards 1/2) surfaces as a secret-free config error.
     *
     * @return void
     */
    public function testHydrateMapsBrokerRefusalToConfigError(): void
    {
        $broker = new FakeInjectingBroker();
        $broker->throwable = new CredentialAccessDeniedException(message: 'Request not permitted');
        $this->service->brokerInstance = $broker;

        $source = $this->injectableSource(
            ['apikey' => ['credentialRef' => ['credentialId' => self::NIL_UUID]]]
        );

        $this->expectException(BrokeredCallConfigurationException::class);
        $this->service->hydrateInjectableCredentials($source);
    }//end testHydrateMapsBrokerRefusalToConfigError()


    /**
     * An older broker WITHOUT resolveInjectable() fails closed with an upgrade hint.
     *
     * @return void
     */
    public function testHydrateFailsClosedOnBrokerWithoutResolveInjectable(): void
    {
        // FakeActingUserBroker has request() but NO resolveInjectable().
        $this->service->brokerInstance = new FakeActingUserBroker();

        $source = $this->injectableSource(
            ['apikey' => ['credentialRef' => ['credentialId' => self::NIL_UUID]]]
        );

        $this->expectException(BrokeredCallConfigurationException::class);
        $this->expectExceptionMessageMatches('/resolveInjectable|does not support app-side injection/i');
        $this->service->hydrateInjectableCredentials($source);
    }//end testHydrateFailsClosedOnBrokerWithoutResolveInjectable()


    /**
     * A source with no placeholders is returned untouched and never calls the broker.
     *
     * @return void
     */
    public function testHydrateIsANoOpWithoutPlaceholders(): void
    {
        $broker = new FakeInjectingBroker();
        $this->service->brokerInstance = $broker;

        $source   = $this->injectableSource(['type' => 'apikey', 'apikey' => 'literal-key']);
        $hydrated = $this->service->hydrateInjectableCredentials($source);

        $this->assertSame($source, $hydrated);
        $this->assertCount(0, $broker->resolveCalls);
    }//end testHydrateIsANoOpWithoutPlaceholders()


}//end class
