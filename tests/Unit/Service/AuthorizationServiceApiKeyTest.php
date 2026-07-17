<?php
/**
 * Security unit tests for consumer-backed API-key enforcement.
 *
 * Proves REQ-CON-001: a request presenting no / a wrong API key is rejected
 * (fail-closed), a request presenting a valid Consumer apiKey authenticates and
 * resolves that consumer, and the pre-existing rule-inline key path is
 * preserved.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Consumer-backed apiKey enforcement (REQ-CON-001).
 */
class AuthorizationServiceApiKeyTest extends TestCase
{

    /**
     * @var ORObjectService|MockObject
     */
    private $orObjectService;

    /**
     * @var IUserManager|MockObject
     */
    private $userManager;

    /**
     * @var IUserSession|MockObject
     */
    private $userSession;


    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->orObjectService = $this->createMock(ORObjectService::class);
        $this->userManager     = $this->createMock(IUserManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);
    }//end setUp()


    /**
     * Build an AuthorizationService wired to the shared mocks.
     *
     * @return AuthorizationService The service under test.
     */
    private function makeService(): AuthorizationService
    {
        $cache        = $this->createMock(ICache::class);
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        return new AuthorizationService(
            $this->userManager,
            $this->userSession,
            $this->orObjectService,
            $this->createMock(IGroupManager::class),
            $cacheFactory,
            $this->createMock(IRequest::class)
        );
    }//end makeService()


    /**
     * Build a real consumer ObjectEntity with the given payload.
     *
     * @param array  $object The consumer object payload.
     * @param string $uuid   The consumer uuid.
     *
     * @return ObjectEntity The hydrated consumer entity.
     */
    private function consumer(array $object, string $uuid = 'consumer-1'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setObject($object);
        $entity->setUuid($uuid);
        return $entity;
    }//end consumer()


    /**
     * A valid Consumer apiKey authenticates and resolves the consumer.
     *
     * @return void
     */
    public function testValidConsumerApiKeyAuthenticatesAndResolvesConsumer(): void
    {
        $consumer = $this->consumer(
            [
                'name'                       => 'partner-a',
                'authorizationType'          => 'apiKey',
                'authorizationConfiguration' => ['apiKey' => 'sk_live_secret'],
                'userId'                     => 'svc-partner-a',
            ]
        );

        $this->orObjectService->expects($this->once())
            ->method('findAll')
            ->willReturn(['results' => [$consumer]]);

        $user = $this->createMock(IUser::class);
        $this->userManager->expects($this->once())
            ->method('get')
            ->with('svc-partner-a')
            ->willReturn($user);
        $this->userSession->expects($this->once())
            ->method('setUser')
            ->with($user);

        $service = $this->makeService();
        $service->authorizeApiKey(header: 'sk_live_secret', keys: []);

        $this->assertSame($consumer, $service->getResolvedConsumer());
    }//end testValidConsumerApiKeyAuthenticatesAndResolvesConsumer()


    /**
     * A missing API key is rejected fail-closed (no consumer lookup, no data).
     *
     * @return void
     */
    public function testMissingApiKeyIsRejected(): void
    {
        // Empty presented key must never match — the consumer store is not even
        // queried and no consumer is resolved.
        $this->orObjectService->expects($this->never())->method('findAll');

        $service = $this->makeService();

        try {
            $service->authorizeApiKey(header: '', keys: []);
            $this->fail('Expected AuthenticationException for a missing API key');
        } catch (AuthenticationException $e) {
            $this->assertSame('Invalid API key', $e->getMessage());
        }

        $this->assertNull($service->getResolvedConsumer());
    }//end testMissingApiKeyIsRejected()


    /**
     * A wrong API key is rejected even when a valid consumer key exists.
     *
     * @return void
     */
    public function testWrongApiKeyIsRejected(): void
    {
        $consumer = $this->consumer(
            [
                'name'                       => 'partner-a',
                'authorizationType'          => 'apiKey',
                'authorizationConfiguration' => ['apiKey' => 'sk_live_secret'],
            ]
        );
        $this->orObjectService->method('findAll')->willReturn(['results' => [$consumer]]);

        $this->userSession->expects($this->never())->method('setUser');

        $service = $this->makeService();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key');
        try {
            $service->authorizeApiKey(header: 'sk_live_WRONG', keys: []);
        } finally {
            $this->assertNull($service->getResolvedConsumer());
        }
    }//end testWrongApiKeyIsRejected()


    /**
     * A consumer whose authorizationType is not apiKey is never matched by key.
     *
     * @return void
     */
    public function testNonApiKeyConsumerIsIgnored(): void
    {
        // Same secret value, but the consumer is a jwt consumer — the apiKey
        // path must not authenticate it.
        $consumer = $this->consumer(
            [
                'name'                       => 'jwt-partner',
                'authorizationType'          => 'jwt',
                'authorizationConfiguration' => ['apiKey' => 'sk_live_secret'],
            ]
        );
        $this->orObjectService->method('findAll')->willReturn(['results' => [$consumer]]);

        $service = $this->makeService();

        $this->expectException(AuthenticationException::class);
        $service->authorizeApiKey(header: 'sk_live_secret', keys: []);
    }//end testNonApiKeyConsumerIsIgnored()


    /**
     * The pre-existing rule-inline key path still authenticates a NC user and
     * short-circuits before any consumer lookup.
     *
     * @return void
     */
    public function testRuleInlineKeyStillMatches(): void
    {
        // Rule-inline match must win without querying the consumer store.
        $this->orObjectService->expects($this->never())->method('findAll');

        $user = $this->createMock(IUser::class);
        $this->userManager->expects($this->once())
            ->method('get')
            ->with('admin')
            ->willReturn($user);
        $this->userSession->expects($this->once())
            ->method('setUser')
            ->with($user);

        $service = $this->makeService();
        $service->authorizeApiKey(header: 'rule-key-1', keys: ['rule-key-1' => 'admin']);

        // Rule-inline keys authenticate a NC user, not a consumer.
        $this->assertNull($service->getResolvedConsumer());
    }//end testRuleInlineKeyStillMatches()


    /**
     * SECURITY (ocon#147 last residual): inbound apiKey auth end-to-end through
     * authorizeApiKey() given a RAW-read rule. The rule's `configuration.authentication.keys`
     * map is now write-only, so EndpointService::getRuleById() reads it with `_render: false`;
     * this test proves that the keys map the engine gets from that raw read authenticates the
     * mapped Nextcloud user exactly as before the strip.
     *
     * @return void
     */
    public function testRawReadRuleKeysAuthenticateInboundApiKey(): void
    {
        // The engine reads the rule raw (_render: false), so the keys map arrives intact.
        $rawRuleKeys = ['SUPER-SECRET-INBOUND-KEY-1' => 'alice'];

        $this->orObjectService->expects($this->never())->method('findAll');

        $user = $this->createMock(IUser::class);
        $this->userManager->expects($this->once())->method('get')->with('alice')->willReturn($user);
        $this->userSession->expects($this->once())->method('setUser')->with($user);

        $service = $this->makeService();
        $service->authorizeApiKey(header: 'SUPER-SECRET-INBOUND-KEY-1', keys: $rawRuleKeys);

        // Rule-inline keys authenticate a NC user, not a consumer.
        $this->assertNull($service->getResolvedConsumer());
    }//end testRawReadRuleKeysAuthenticateInboundApiKey()


    /**
     * SECURITY (ocon#147 last residual): the mirror image — a STRIPPED read. If getRuleById()
     * had used only `_rbac: false` (not `_render: false`), the write-only strip would blank the
     * keys map and the engine would call authorizeApiKey() with `keys: []`. With no consumer
     * backing the presented key, auth then fails closed (401). This documents why `_render: false`
     * is load-bearing: without it, every inbound apiKey is refused.
     *
     * @return void
     */
    public function testStrippedRuleKeysRefuseInboundApiKey(): void
    {
        // A stripped rule yields an empty keys map; no consumer matches the presented key.
        $this->orObjectService->method('findAll')->willReturn(['results' => []]);
        $this->userSession->expects($this->never())->method('setUser');

        $service = $this->makeService();

        $this->expectException(AuthenticationException::class);
        $service->authorizeApiKey(header: 'SUPER-SECRET-INBOUND-KEY-1', keys: []);
    }//end testStrippedRuleKeysRefuseInboundApiKey()


    /**
     * A matching consumer without a backing userId is still authenticated.
     *
     * @return void
     */
    public function testConsumerWithoutUserIdStillAuthenticates(): void
    {
        $consumer = $this->consumer(
            [
                'name'                       => 'keyless-user-consumer',
                'authorizationType'          => 'apikey',
                'authorizationConfiguration' => ['apiKey' => 'sk_live_secret'],
            ]
        );
        $this->orObjectService->method('findAll')->willReturn(['results' => [$consumer]]);

        // No userId on the consumer → no NC user is set, but auth still passes.
        $this->userManager->expects($this->never())->method('get');
        $this->userSession->expects($this->never())->method('setUser');

        $service = $this->makeService();
        $service->authorizeApiKey(header: 'sk_live_secret', keys: []);

        $this->assertSame($consumer, $service->getResolvedConsumer());
    }//end testConsumerWithoutUserIdStillAuthenticates()


    /**
     * REQ-CON-002 (notificaties-api-subscriber): apiKey consumer
     * authentication MUST remain callable outside the endpoint-runtime
     * dispatch path. Every test in this suite already calls
     * `authorizeApiKey()` directly (never through `EndpointsController`/
     * `EndpointService`), which proves the contract — this test names it
     * explicitly so a future refactor that makes the method
     * `EndpointsController`-only fails loudly here, mirroring the first
     * real non-endpoint-runtime consumer:
     * `NotificatiesSubscriberController::callback()`.
     *
     * @return void
     *
     * @spec openspec/specs/consumer-management/spec.md#requirement-apikey-consumer-authentication-must-remain-callable-outside-the-endpoint-runtime-dispatch-path-req-con-002
     */
    public function testApiKeyAuthWorksIdenticallyForANonEndpointRuntimeCaller(): void
    {
        $consumer = $this->consumer(
            [
                'name'                       => 'Notificaties abonnement: zaken',
                'authorizationType'          => 'apiKey',
                'authorizationConfiguration' => ['apiKey' => 'notif-secret-1'],
            ]
        );
        $this->orObjectService->method('findAll')->willReturn(['results' => [$consumer]]);

        // Simulates NotificatiesSubscriberController::callback() — a
        // controller with zero relationship to EndpointsController/
        // EndpointService's dispatch pipeline — calling authorizeApiKey()
        // directly with no rule-inline keys of its own.
        $service = $this->makeService();
        $service->authorizeApiKey(header: 'notif-secret-1', keys: []);

        $this->assertSame($consumer, $service->getResolvedConsumer());

        // An unmatched key fails closed identically regardless of caller.
        $service2 = $this->makeService();
        $this->expectException(AuthenticationException::class);
        try {
            $service2->authorizeApiKey(header: 'wrong-secret', keys: []);
        } finally {
            $this->assertNull($service2->getResolvedConsumer());
        }

    }//end testApiKeyAuthWorksIdenticallyForANonEndpointRuntimeCaller()
}//end class
