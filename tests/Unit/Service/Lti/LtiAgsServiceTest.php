<?php
/**
 * Unit tests for LtiAgsService.
 *
 * Covers REQ-LTI-007 (service-token issuance, deployment-scoped tokens,
 * cross-deployment 403, score-received CloudEvent published — never a
 * direct gradeSink write) and REQ-LTI-008 (Tool-role outbound score
 * publish/result read reusing fetchOAuthTokens() unmodified, token-endpoint
 * failure never silently dropped).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-ags-service-token-issuance-and-inbound-scoreline-item-endpoints-platform-role-fanned-out-as-a-cloudevent-req-lti-007
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Lti;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\OpenConnector\Exception\LtiValidationException;
use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\Lti\LtiAgsService;
use OCA\OpenConnector\Service\Lti\LtiJwksResolverService;
use OCA\OpenConnector\Service\Lti\LtiKeyService;
use OCA\OpenConnector\Service\Lti\LtiLaunchService;
use OCA\OpenConnector\Service\Lti\LtiRegistrationResolverService;
use OCA\OpenConnector\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AGS service-token issuance, inbound score handling, and Tool-role outbound calls.
 */
class LtiAgsServiceTest extends TestCase
{

    private const TOOL_UUID       = 'tool-uuid-1';
    private const TOOL_CLIENT_ID  = 'tool-client-1';
    private const DEPLOYMENT_A    = 'dep-a';
    private const DEPLOYMENT_B    = 'dep-b';

    private JWK $toolKey;


    protected function setUp(): void
    {
        parent::setUp();
        $this->toolKey = JWKFactory::createRSAKey(2048, ['kid' => 'tool-kid-1', 'alg' => 'RS256', 'use' => 'sig']);

    }//end setUp()


    /**
     * Build a real AuthorizationService (iat/exp/nbf reuse).
     *
     * @return AuthorizationService
     */
    private function makeAuthorizationService(): AuthorizationService
    {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn(new ArrayCache());

        return new AuthorizationService(
            $this->createMock(IUserManager::class),
            $this->createMock(IUserSession::class),
            $this->createMock(\OCA\OpenRegister\Service\ObjectService::class),
            $this->createMock(IGroupManager::class),
            $cacheFactory,
            $this->createMock(IRequest::class)
        );

    }//end makeAuthorizationService()


    /**
     * Build the fixture set: one lti_tool + two lti_deployments (A owned by the tool, B owned by a different tool).
     *
     * @return array{resolver: LtiRegistrationResolverService, deploymentA: ObjectEntity, deploymentB: ObjectEntity, tool: ObjectEntity}
     */
    private function makeFixtures(): array
    {
        $tool = new ObjectEntity();
        $tool->setUuid(self::TOOL_UUID);
        $tool->setObject(['clientId' => self::TOOL_CLIENT_ID, 'jwksUri' => 'https://tool.example/jwks.json']);

        $deploymentA = new ObjectEntity();
        $deploymentA->setUuid(self::DEPLOYMENT_A);
        $deploymentA->setObject(['deploymentId' => 'deploy-a', 'ltiToolId' => self::TOOL_UUID, 'gradeSink' => ['targetType' => 'register/schema', 'targetId' => '20/111']]);

        $deploymentB = new ObjectEntity();
        $deploymentB->setUuid(self::DEPLOYMENT_B);
        $deploymentB->setObject(['deploymentId' => 'deploy-b', 'ltiToolId' => 'some-other-tool-uuid']);

        $resolver = $this->createMock(LtiRegistrationResolverService::class);
        $resolver->method('findToolByClientId')->willReturnCallback(
            fn ($clientId) => ($clientId === self::TOOL_CLIENT_ID) ? $tool : null
        );
        $resolver->method('findDeploymentByUuid')->willReturnCallback(
            function ($uuid) use ($deploymentA, $deploymentB) {
                return match ($uuid) {
                    self::DEPLOYMENT_A => $deploymentA,
                    self::DEPLOYMENT_B => $deploymentB,
                    default => null,
                };
            }
        );
        $resolver->method('findRegistrationByUuid')->willReturnCallback(
            fn ($type, $uuid) => ($type === 'lti_platform') ? null : null
        );

        return ['resolver' => $resolver, 'deploymentA' => $deploymentA, 'deploymentB' => $deploymentB, 'tool' => $tool];

    }//end makeFixtures()


    /**
     * Build the LtiLaunchService dependency (real, so the assertion
     * verification path is genuinely exercised) resolving the tool's JWKS to
     * this test's own key.
     *
     * @param LtiRegistrationResolverService $resolver Resolver double.
     *
     * @return LtiLaunchService
     */
    private function makeLaunchService($resolver): LtiLaunchService
    {
        $jwksResolver = $this->createMock(LtiJwksResolverService::class);
        $jwksResolver->method('resolveKey')->willReturn($this->toolKey->toPublic());

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturnCallback(fn () => new ArrayCache());

        $keyService = new LtiKeyService($this->createMock(\OCA\OpenRegister\Service\ObjectService::class), new NullLogger());

        return new LtiLaunchService($resolver, $this->makeAuthorizationService(), $jwksResolver, $keyService, $cacheFactory, new NullLogger());

    }//end makeLaunchService()


    /**
     * Sign a client_assertion as the tool.
     *
     * @param array $overrides Claim overrides.
     *
     * @return string
     */
    private function signClientAssertion(array $overrides=[]): string
    {
        $now    = time();
        $claims = array_merge(
            [
                'iss' => self::TOOL_CLIENT_ID,
                'sub' => self::TOOL_CLIENT_ID,
                'aud' => 'https://our-instance.example/api/lti/token',
                'iat' => $now,
                'exp' => ($now + 300),
                'jti' => bin2hex(random_bytes(8)),
            ],
            $overrides
        );

        $algorithmManager = new AlgorithmManager([new RS256()]);
        $jwsBuilder       = new JWSBuilder($algorithmManager);
        $serializer       = new CompactSerializer();
        $jws              = $jwsBuilder->create()
            ->withPayload(json_encode($claims))
            ->addSignature($this->toolKey, ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'tool-kid-1'])
            ->build();

        return $serializer->serialize($jws, 0);

    }//end signClientAssertion()


    /**
     * Build the LtiAgsService under test.
     *
     * @param array                  $fixtures     From makeFixtures().
     * @param AuthenticationService|null $authenticationService Override (defaults to a never-called mock).
     * @param CallService|null       $callService  Override.
     * @param EventService|null      $eventService Override.
     *
     * @return LtiAgsService
     */
    private function makeService(array $fixtures, $authenticationService=null, $callService=null, $eventService=null): LtiAgsService
    {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturnCallback(fn () => new ArrayCache());

        return new LtiAgsService(
            $fixtures['resolver'],
            $this->makeLaunchService($fixtures['resolver']),
            new LtiKeyService($this->createMock(\OCA\OpenRegister\Service\ObjectService::class), new NullLogger()),
            ($authenticationService ?? $this->createMock(AuthenticationService::class)),
            ($callService ?? $this->createMock(CallService::class)),
            ($eventService ?? $this->createMock(EventService::class)),
            $cacheFactory,
            new NullLogger()
        );

    }//end makeService()


    // =========================================================================
    // REQ-LTI-007 — token issuance
    // =========================================================================

    /**
     * A valid client assertion is exchanged for a deployment-scoped access token.
     *
     * @return void
     */
    public function testValidAssertionIssuesDeploymentScopedToken(): void
    {
        $fixtures = $this->makeFixtures();
        $service  = $this->makeService($fixtures);

        $token = $service->issueAccessToken(
            $this->signClientAssertion(),
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            self::DEPLOYMENT_A
        );

        $this->assertSame('Bearer', $token['token_type']);
        $this->assertNotEmpty($token['access_token']);
        $this->assertSame('https://purl.imsglobal.org/spec/lti-ags/scope/score', $token['scope']);

        $resolved = $service->resolveAccessToken($token['access_token']);
        $this->assertSame(self::DEPLOYMENT_A, $resolved['deploymentUuid']);

    }//end testValidAssertionIssuesDeploymentScopedToken()


    /**
     * An assertion whose iss/sub mismatch is rejected.
     *
     * @return void
     */
    public function testMismatchedIssSubRejected(): void
    {
        $fixtures = $this->makeFixtures();
        $service  = $this->makeService($fixtures);

        $this->expectException(LtiValidationException::class);
        $service->issueAccessToken(
            $this->signClientAssertion(['sub' => 'someone-else']),
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            self::DEPLOYMENT_A
        );

    }//end testMismatchedIssSubRejected()


    /**
     * A deployment not owned by the asserting tool is rejected 403.
     *
     * @return void
     */
    public function testDeploymentNotOwnedByAssertingToolRejected(): void
    {
        $fixtures = $this->makeFixtures();
        $service  = $this->makeService($fixtures);

        $this->expectException(LtiValidationException::class);
        try {
            $service->issueAccessToken(
                $this->signClientAssertion(),
                'https://purl.imsglobal.org/spec/lti-ags/scope/score',
                self::DEPLOYMENT_B
            );
        } catch (LtiValidationException $exception) {
            $this->assertSame(403, $exception->getHttpStatus());
            throw $exception;
        }

    }//end testDeploymentNotOwnedByAssertingToolRejected()


    /**
     * A disallowed scope is rejected.
     *
     * @return void
     */
    public function testDisallowedScopeRejected(): void
    {
        $fixtures = $this->makeFixtures();
        $service  = $this->makeService($fixtures);

        $this->expectException(LtiValidationException::class);
        $service->issueAccessToken($this->signClientAssertion(), 'https://example.com/not-a-real-scope', self::DEPLOYMENT_A);

    }//end testDisallowedScopeRejected()


    /**
     * A token issued for deployment A is rejected when presented against deployment B (cross-deployment 403).
     *
     * @return void
     */
    public function testCrossDeploymentAccessRejected(): void
    {
        $fixtures = $this->makeFixtures();
        $service  = $this->makeService($fixtures);

        $token = $service->issueAccessToken(
            $this->signClientAssertion(),
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            self::DEPLOYMENT_A
        );

        $this->expectException(LtiValidationException::class);
        try {
            $service->assertScopedToDeployment($token['access_token'], self::DEPLOYMENT_B, 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem');
        } catch (LtiValidationException $exception) {
            $this->assertSame(403, $exception->getHttpStatus());
            throw $exception;
        }

    }//end testCrossDeploymentAccessRejected()


    // =========================================================================
    // REQ-LTI-007 — score received → CloudEvent, not a direct write
    // =========================================================================

    /**
     * A received score is published as a CloudEvent with the correct type
     * and payload — never a direct write to gradeSink.
     *
     * @return void
     */
    public function testReceivedScorePublishedAsCloudEvent(): void
    {
        $fixtures = $this->makeFixtures();

        $eventService = $this->createMock(EventService::class);
        $capturedArgs = [];
        $eventService->expects($this->once())->method('emitCloudEvent')->willReturnCallback(
            function ($type, $source, $subject, $data, $userId=null) use (&$capturedArgs) {
                $capturedArgs = ['type' => $type, 'source' => $source, 'subject' => $subject, 'data' => $data];
                return [new ObjectEntity()];
            }
        );

        $service = $this->makeService($fixtures, null, null, $eventService);
        $token   = $service->issueAccessToken(
            $this->signClientAssertion(),
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            self::DEPLOYMENT_A
        );

        $result = $service->receiveScore($token['access_token'], self::DEPLOYMENT_A, 'lineitem-1', ['scoreGiven' => 8.5, 'scoreMaximum' => 10]);

        $this->assertSame(1, $result['messagesCreated']);
        $this->assertSame('nl.conduction.lti.ags.score.received', $capturedArgs['type']);
        $this->assertSame('lineitem-1', $capturedArgs['subject']);
        $this->assertSame(self::DEPLOYMENT_A, $capturedArgs['data']['deploymentUuid']);
        $this->assertSame(['scoreGiven' => 8.5, 'scoreMaximum' => 10], $capturedArgs['data']['score']);
        // The event data references gradeSink for the consuming app's OWN
        // subscription to act on — but this service performs no write to it.
        $this->assertSame(['targetType' => 'register/schema', 'targetId' => '20/111'], $capturedArgs['data']['gradeSink']);

    }//end testReceivedScorePublishedAsCloudEvent()


    /**
     * REQ-LTI-010: a deployment with no matching `event_subscription` still
     * succeeds — the CloudEvent is created (existing `events-cloudevents`
     * zero-subscriber-fan-out-is-not-an-error behaviour, reused unmodified),
     * it is simply undelivered until the consuming app subscribes.
     *
     * @return void
     */
    public function testScoreReceivedSucceedsWithZeroSubscribers(): void
    {
        $fixtures = $this->makeFixtures();

        // Simulates events-cloudevents' existing "zero matching subscriptions" behaviour.
        $eventService = $this->createMock(EventService::class);
        $eventService->method('emitCloudEvent')->willReturn([]);

        $service = $this->makeService($fixtures, null, null, $eventService);
        $token   = $service->issueAccessToken(
            $this->signClientAssertion(),
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            self::DEPLOYMENT_A
        );

        $result = $service->receiveScore($token['access_token'], self::DEPLOYMENT_A, 'lineitem-1', ['scoreGiven' => 5]);

        $this->assertSame(0, $result['messagesCreated'], 'zero subscribers is not an error — the score is simply undelivered until the app subscribes');

    }//end testScoreReceivedSucceedsWithZeroSubscribers()


    /**
     * A score-POST without the `score` scope is rejected 403.
     *
     * @return void
     */
    public function testScoreWithoutRequiredScopeRejected(): void
    {
        $fixtures = $this->makeFixtures();
        $service  = $this->makeService($fixtures);

        $token = $service->issueAccessToken(
            $this->signClientAssertion(),
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            self::DEPLOYMENT_A
        );

        $this->expectException(LtiValidationException::class);
        try {
            $service->receiveScore($token['access_token'], self::DEPLOYMENT_A, 'lineitem-1', ['scoreGiven' => 1]);
        } catch (LtiValidationException $exception) {
            $this->assertSame(403, $exception->getHttpStatus());
            throw $exception;
        }

    }//end testScoreWithoutRequiredScopeRejected()


    // =========================================================================
    // REQ-LTI-008 — Tool-role outbound score publish / result read
    // =========================================================================

    /**
     * publishScore() reuses fetchOAuthTokens() with the jwt-bearer client-assertion type.
     *
     * @return void
     */
    public function testPublishScoreReusesJwtBearerGrant(): void
    {
        $platform = new ObjectEntity();
        $platform->setUuid('plat-1');
        $platform->setObject(['clientId' => 'our-client-id', 'authTokenUrl' => 'https://platform.example/token']);

        $deployment = new ObjectEntity();
        $deployment->setUuid('dep-out-1');
        $deployment->setObject(['ltiPlatformId' => 'plat-1']);

        $resolver = $this->createMock(LtiRegistrationResolverService::class);
        $resolver->method('findDeploymentByUuid')->willReturnCallback(fn ($uuid) => ($uuid === 'dep-out-1') ? $deployment : null);
        $resolver->method('findRegistrationByUuid')->willReturnCallback(fn ($type, $uuid) => ($type === 'lti_platform' && $uuid === 'plat-1') ? $platform : null);

        // Real LtiKeyService with an in-memory store so the platform has a genuine active key.
        $registrations = ['plat-1' => ['signingKeys' => []]];
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function ($id) use (&$registrations) {
                $entity = new ObjectEntity();
                $entity->setUuid($id);
                $entity->setObject($registrations[$id]);
                return $entity;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function ($object=[], $register=null, $schema=null, $uuid=null) use (&$registrations) {
                $registrations[$uuid] = $object;
                $entity = new ObjectEntity();
                $entity->setUuid($uuid);
                $entity->setObject($object);
                return $entity;
            }
        );
        $keyService = new LtiKeyService($objectService, new NullLogger());
        $keyService->generateKey('lti_platform', 'plat-1');

        $capturedConfig = null;
        $authenticationService = $this->createMock(AuthenticationService::class);
        $authenticationService->expects($this->once())->method('fetchOAuthTokens')->willReturnCallback(
            function ($configuration) use (&$capturedConfig) {
                $capturedConfig = $configuration;
                return 'access-token-value';
            }
        );

        $callLog = new ObjectEntity();
        $callLog->setUuid('log-1');
        $callLog->setObject(['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => '{}']]);
        $callService = $this->createMock(CallService::class);
        $callService->expects($this->once())->method('call')->willReturn($callLog);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturnCallback(fn () => new ArrayCache());

        $service = new LtiAgsService(
            $resolver,
            $this->makeLaunchService($resolver),
            $keyService,
            $authenticationService,
            $callService,
            $this->createMock(EventService::class),
            $cacheFactory,
            new NullLogger()
        );

        $result = $service->publishScore('dep-out-1', 'https://platform.example/lineitems/1', ['scoreGiven' => 9]);

        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $capturedConfig['client_assertion_type']);
        $this->assertSame('client_credentials', $capturedConfig['grant_type']);
        $this->assertNotEmpty($capturedConfig['private_key'], 'must reuse the platform\'s own signing key material, unmodified fetchOAuthTokens() shape');

    }//end testPublishScoreReusesJwtBearerGrant()


    /**
     * A token-endpoint failure during score publish is never silently
     * dropped — it propagates as an exception (never a swallowed write).
     *
     * @return void
     */
    public function testTokenEndpointFailureDuringPublishNotSilentlyDropped(): void
    {
        $platform = new ObjectEntity();
        $platform->setUuid('plat-1');
        $platform->setObject(['clientId' => 'our-client-id', 'authTokenUrl' => 'https://platform.example/token']);

        $deployment = new ObjectEntity();
        $deployment->setUuid('dep-out-1');
        $deployment->setObject(['ltiPlatformId' => 'plat-1']);

        $resolver = $this->createMock(LtiRegistrationResolverService::class);
        $resolver->method('findDeploymentByUuid')->willReturnCallback(fn ($uuid) => ($uuid === 'dep-out-1') ? $deployment : null);
        $resolver->method('findRegistrationByUuid')->willReturnCallback(fn ($type, $uuid) => $platform);

        $registrations = ['plat-1' => ['signingKeys' => []]];
        $objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $objectService->method('find')->willReturnCallback(
            function ($id) use (&$registrations) {
                $entity = new ObjectEntity();
                $entity->setUuid($id);
                $entity->setObject($registrations[$id]);
                return $entity;
            }
        );
        $objectService->method('saveObject')->willReturnCallback(
            function ($object=[], $register=null, $schema=null, $uuid=null) use (&$registrations) {
                $registrations[$uuid] = $object;
                $entity = new ObjectEntity();
                $entity->setUuid($uuid);
                $entity->setObject($object);
                return $entity;
            }
        );
        $keyService = new LtiKeyService($objectService, new NullLogger());
        $keyService->generateKey('lti_platform', 'plat-1');

        $authenticationService = $this->createMock(AuthenticationService::class);
        $authenticationService->method('fetchOAuthTokens')->willThrowException(new \RuntimeException('token endpoint unreachable'));

        // The AGS REST call must NEVER be dispatched when the token exchange failed.
        $callService = $this->createMock(CallService::class);
        $callService->expects($this->never())->method('call');

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturnCallback(fn () => new ArrayCache());

        $service = new LtiAgsService(
            $resolver,
            $this->makeLaunchService($resolver),
            $keyService,
            $authenticationService,
            $callService,
            $this->createMock(EventService::class),
            $cacheFactory,
            new NullLogger()
        );

        $this->expectException(LtiValidationException::class);
        try {
            $service->publishScore('dep-out-1', 'https://platform.example/lineitems/1', ['scoreGiven' => 9]);
        } catch (LtiValidationException $exception) {
            $this->assertSame(502, $exception->getHttpStatus());
            throw $exception;
        }

    }//end testTokenEndpointFailureDuringPublishNotSilentlyDropped()
}//end class
