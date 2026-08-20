<?php

/**
 * Unit tests for LtiNrpsService.
 *
 * Covers REQ-LTI-009: roster requests served via the ADR-008
 * `register/schema` read path, synchronous (no CloudEvent), missing/wrong
 * scope rejection, and Tool-role outbound roster pull reusing the
 * JWT-bearer grant.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-nrps-inbound-roster-read-platform-role-via-the-adr-008-registerschema-dispatch-and-outbound-roster-pull-tool-role-req-lti-009
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
use OCA\OpenConnector\Service\Lti\LtiNrpsService;
use OCA\OpenConnector\Service\Lti\LtiRegistrationResolverService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A minimal mapper double exposing only the subset of
 * `OCA\OpenRegister\Service\ObjectServiceMapperAdapter` this service uses.
 */
class FakeNrpsMapper {

	/** @var ObjectEntity[] */
	public array $objects = [];

	public function findAllPaginated(array $requestParams = []): array {
		return ['results' => $this->objects];
	}//end findAllPaginated()
}//end class

/**
 * Tests for NRPS inbound roster reads + outbound roster pull.
 */
class LtiNrpsServiceTest extends TestCase {

	private const TOOL_UUID = 'tool-uuid-1';
	private const TOOL_CLIENT_ID = 'tool-client-1';
	private const DEPLOYMENT_A = 'dep-a';

	private JWK $toolKey;

	protected function setUp(): void {
		parent::setUp();
		$this->toolKey = JWKFactory::createRSAKey(2048, ['kid' => 'tool-kid-1', 'alg' => 'RS256', 'use' => 'sig']);

	}//end setUp()

	/**
	 * @return AuthorizationService
	 */
	private function makeAuthorizationService(): AuthorizationService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());

		return new AuthorizationService(
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IGroupManager::class),
			$cacheFactory,
			$this->createMock(IRequest::class)
		);

	}//end makeAuthorizationService()

	/**
	 * Sign a client_assertion as the tool.
	 *
	 * @return string
	 */
	private function signClientAssertion(): string {
		$now = time();
		$claims = [
			'iss' => self::TOOL_CLIENT_ID,
			'sub' => self::TOOL_CLIENT_ID,
			'aud' => 'https://our-instance.example/api/lti/token',
			'iat' => $now,
			'exp' => ($now + 300),
			'jti' => bin2hex(random_bytes(8)),
		];

		$algorithmManager = new AlgorithmManager([new RS256()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);
		$serializer = new CompactSerializer();
		$jws = $jwsBuilder->create()
			->withPayload(json_encode($claims))
			->addSignature($this->toolKey, ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'tool-kid-1'])
			->build();

		return $serializer->serialize($jws, 0);
	}//end signClientAssertion()

	/**
	 * Build resolver + tool/deployment fixtures and a real AGS service
	 * (issues genuine deployment-scoped tokens for the NRPS scope check).
	 *
	 * @param array $deploymentOverrides Extra `lti_deployment` fields (e.g. `rosterSource`, `mappingId`).
	 *
	 * @return array{resolver: LtiRegistrationResolverService, agsService: LtiAgsService, deployment: ObjectEntity}
	 */
	private function makeFixtures(array $deploymentOverrides = []): array {
		$tool = new ObjectEntity();
		$tool->setUuid(self::TOOL_UUID);
		$tool->setObject(['clientId' => self::TOOL_CLIENT_ID, 'jwksUri' => 'https://tool.example/jwks.json']);

		$deployment = new ObjectEntity();
		$deployment->setUuid(self::DEPLOYMENT_A);
		$deployment->setObject(array_merge(['deploymentId' => 'deploy-a', 'ltiToolId' => self::TOOL_UUID], $deploymentOverrides));

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findToolByClientId')->willReturnCallback(fn ($clientId) => ($clientId === self::TOOL_CLIENT_ID) ? $tool : null);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(fn ($uuid) => ($uuid === self::DEPLOYMENT_A) ? $deployment : null);
		$resolver->method('findRegistrationByUuid')->willReturn(null);

		$jwksResolver = $this->createMock(LtiJwksResolverService::class);
		$jwksResolver->method('resolveKey')->willReturn($this->toolKey->toPublic());

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(fn () => new ArrayCache());

		$keyService = new LtiKeyService($this->createMock(ObjectService::class), new NullLogger());
		$launchService = new LtiLaunchService($resolver, $this->makeAuthorizationService(), $jwksResolver, $keyService, $cacheFactory, new NullLogger());

		$agsService = new LtiAgsService(
			$resolver,
			$launchService,
			$keyService,
			$this->createMock(AuthenticationService::class),
			$this->createMock(CallService::class),
			$this->createMock(EventService::class),
			$cacheFactory,
			new NullLogger()
		);

		return ['resolver' => $resolver, 'agsService' => $agsService, 'deployment' => $deployment];
	}//end makeFixtures()

	// =========================================================================
	// REQ-LTI-009 — inbound roster read
	// =========================================================================

	/**
	 * A roster request is served from the deployment's configured
	 * register/schema source, synchronously (no CloudEvent involved — the
	 * mock EventService is never called).
	 *
	 * @return void
	 */
	public function testRosterServedFromConfiguredRegisterSchema(): void {
		$fixtures = $this->makeFixtures(['rosterSource' => ['targetType' => 'register/schema', 'targetId' => '20/111']]);

		$member = new ObjectEntity();
		$member->setUuid('member-1');
		$member->setObject(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'roles' => ['Learner']]);

		$mapper = new FakeNrpsMapper();
		$mapper->objects = [$member];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('getMapper')->with(20, 111)->willReturn($mapper);

		$eventService = $this->createMock(EventService::class);
		$eventService->expects($this->never())->method('emitCloudEvent');

		$service = new LtiNrpsService(
			$fixtures['resolver'],
			$fixtures['agsService'],
			$objectService,
			$this->createMock(MappingService::class),
			new NullLogger()
		);

		$token = $fixtures['agsService']->issueAccessToken($this->signClientAssertion(), LtiAgsService::SCOPE_NRPS, self::DEPLOYMENT_A);
		$roster = $service->readRoster($token['access_token'], self::DEPLOYMENT_A);

		$this->assertCount(1, $roster['members']);
		$this->assertSame('Ada Lovelace', $roster['members'][0]['name']);

	}//end testRosterServedFromConfiguredRegisterSchema()

	/**
	 * A request without the NRPS scope is rejected 403.
	 *
	 * @return void
	 */
	public function testMissingScopeRejected(): void {
		$fixtures = $this->makeFixtures(['rosterSource' => ['targetType' => 'register/schema', 'targetId' => '20/111']]);

		$service = new LtiNrpsService(
			$fixtures['resolver'],
			$fixtures['agsService'],
			$this->createMock(ObjectService::class),
			$this->createMock(MappingService::class),
			new NullLogger()
		);

		// Token issued WITHOUT the NRPS scope.
		$token = $fixtures['agsService']->issueAccessToken(
			$this->signClientAssertion(),
			'https://purl.imsglobal.org/spec/lti-ags/scope/score',
			self::DEPLOYMENT_A
		);

		$this->expectException(LtiValidationException::class);
		try {
			$service->readRoster($token['access_token'], self::DEPLOYMENT_A);
		} catch (LtiValidationException $exception) {
			$this->assertSame(403, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testMissingScopeRejected()

	/**
	 * A deployment with no rosterSource configured rejects the request 400.
	 *
	 * @return void
	 */
	public function testUnconfiguredRosterSourceRejected(): void {
		$fixtures = $this->makeFixtures();

		$service = new LtiNrpsService(
			$fixtures['resolver'],
			$fixtures['agsService'],
			$this->createMock(ObjectService::class),
			$this->createMock(MappingService::class),
			new NullLogger()
		);

		$token = $fixtures['agsService']->issueAccessToken($this->signClientAssertion(), LtiAgsService::SCOPE_NRPS, self::DEPLOYMENT_A);

		$this->expectException(LtiValidationException::class);
		try {
			$service->readRoster($token['access_token'], self::DEPLOYMENT_A);
		} catch (LtiValidationException $exception) {
			$this->assertSame(400, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testUnconfiguredRosterSourceRejected()
}//end class
