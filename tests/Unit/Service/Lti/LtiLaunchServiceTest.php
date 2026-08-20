<?php

/**
 * Unit tests for LtiLaunchService.
 *
 * Covers REQ-LTI-004 (login initiation), REQ-LTI-005 (launch validation —
 * valid / expired / wrong-aud / wrong-iss / replayed-nonce / unknown-kid),
 * and REQ-LTI-006 (Platform-role launch initiation + Deep Linking).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-launch-id-token-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
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
use OCA\OpenConnector\Service\AuthorizationService;
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
 * Tests exercising a real JWS sign/verify round trip against the launch
 * validation pipeline (rather than mocking crypto), so the security-critical
 * path is genuinely tested.
 */
class LtiLaunchServiceTest extends TestCase {

	private const PLATFORM_UUID = 'plat-uuid-1';
	private const PLATFORM_ISS = 'https://platform.example';
	private const CLIENT_ID = 'client-abc';
	private const DEPLOYMENT_UUID = 'dep-uuid-1';
	private const DEPLOYMENT_ID_CLAIM = 'deploy-claim-1';

	/** @var JWK Platform's own keypair (simulates the external platform's signing key). */
	private JWK $platformKey;

	protected function setUp(): void {
		parent::setUp();
		$this->platformKey = JWKFactory::createRSAKey(2048, ['kid' => 'platform-kid-1', 'alg' => 'RS256', 'use' => 'sig']);

	}//end setUp()

	/**
	 * Build a real AuthorizationService instance (reused, not mocked, for iat/exp/nbf).
	 *
	 * @return AuthorizationService
	 */
	private function makeAuthorizationService(): AuthorizationService {
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
	 * Build platform/deployment ObjectEntity fixtures and a resolver mock
	 * that serves them.
	 *
	 * @return LtiRegistrationResolverService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function makeResolver() {
		$platform = new ObjectEntity();
		$platform->setUuid(self::PLATFORM_UUID);
		$platform->setObject(
			[
				'issuer' => self::PLATFORM_ISS,
				'clientId' => self::CLIENT_ID,
				'authLoginUrl' => 'https://platform.example/auth',
				'jwksUri' => 'https://platform.example/jwks.json',
			]
		);

		$deployment = new ObjectEntity();
		$deployment->setUuid(self::DEPLOYMENT_UUID);
		$deployment->setObject(
			[
				'deploymentId' => self::DEPLOYMENT_ID_CLAIM,
				'ltiPlatformId' => self::PLATFORM_UUID,
				'launchTargetUrl' => 'https://tool.example/app',
			]
		);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findPlatformByIssuer')->willReturnCallback(
			function ($issuer, $clientId = null) use ($platform) {
				if ($issuer !== self::PLATFORM_ISS) {
					return null;
				}

				if ($clientId !== null && $clientId !== self::CLIENT_ID) {
					return null;
				}

				return $platform;
			}
		);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			function ($uuid) use ($deployment) {
				return ($uuid === self::DEPLOYMENT_UUID) ? $deployment : null;
			}
		);
		$resolver->method('findDeployment')->willReturnCallback(
			function ($registrationType, $registrationUuid, $deploymentIdClaim) use ($deployment) {
				if ($registrationType === 'lti_platform'
					&& $registrationUuid === self::PLATFORM_UUID
					&& $deploymentIdClaim === self::DEPLOYMENT_ID_CLAIM
				) {
					return $deployment;
				}

				return null;
			}
		);

		return $resolver;
	}//end makeResolver()

	/**
	 * Build a JWKS resolver double that always resolves the fixed platform
	 * key (simulating a successful JWKS fetch — JWKS resolution itself is
	 * covered independently by LtiJwksResolverServiceTest).
	 *
	 * @param JWK|null $key Override key to resolve (defaults to the platform's own key).
	 *
	 * @return LtiJwksResolverService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function makeJwksResolver(?JWK $key = null) {
		$key = ($key ?? $this->platformKey->toPublic());
		$resolver = $this->createMock(LtiJwksResolverService::class);
		$resolver->method('resolveKey')->willReturn($key);

		return $resolver;
	}//end makeJwksResolver()

	/**
	 * Build the LtiLaunchService under test.
	 *
	 * @param LtiRegistrationResolverService $resolver Registration/deployment resolver.
	 * @param LtiJwksResolverService $jwksResolver JWKS resolver.
	 *
	 * @return LtiLaunchService
	 */
	private function makeService($resolver, $jwksResolver): LtiLaunchService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$stores = [];
		$cacheFactory->method('createDistributed')->willReturnCallback(
			function ($namespace) use (&$stores) {
				if (isset($stores[$namespace]) === false) {
					$stores[$namespace] = new ArrayCache();
				}

				return $stores[$namespace];
			}
		);

		$keyServiceObjectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
		$keyService = new LtiKeyService($keyServiceObjectService, new NullLogger());

		return new LtiLaunchService(
			$resolver,
			$this->makeAuthorizationService(),
			$jwksResolver,
			$keyService,
			$cacheFactory,
			new NullLogger()
		);

	}//end makeService()

	/**
	 * Sign a compact JWS with the platform's own key.
	 *
	 * @param array $claims The payload claims.
	 *
	 * @return string
	 */
	private function signAsPlatform(array $claims): string {
		$algorithmManager = new AlgorithmManager([new RS256()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);
		$serializer = new CompactSerializer();

		$jws = $jwsBuilder->create()
			->withPayload(json_encode($claims))
			->addSignature($this->platformKey, ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->platformKey->get('kid')])
			->build();

		return $serializer->serialize($jws, 0);
	}//end signAsPlatform()

	/**
	 * Build a well-formed, currently-valid launch claim set, merging overrides.
	 *
	 * @param string $nonce The nonce to embed.
	 * @param array $overrides Claim overrides.
	 *
	 * @return array
	 */
	private function baseLaunchClaims(string $nonce, array $overrides = []): array {
		$now = time();

		return array_merge(
			[
				'iss' => self::PLATFORM_ISS,
				'aud' => self::CLIENT_ID,
				'iat' => $now,
				'exp' => ($now + 300),
				'nonce' => $nonce,
				'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => self::DEPLOYMENT_ID_CLAIM,
				'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
				'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
			],
			$overrides
		);

	}//end baseLaunchClaims()

	// =========================================================================
	// REQ-LTI-004 — login initiation
	// =========================================================================

	/**
	 * A valid login-initiation request redirects with state + nonce.
	 *
	 * @return void
	 */
	public function testValidLoginInitiationRedirectsWithStateAndNonce(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());

		$result = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$this->assertNotEmpty($result['state']);
		$this->assertNotEmpty($result['nonce']);
		$this->assertStringContainsString('state=' . $result['state'], $result['redirectUrl']);
		$this->assertStringContainsString('nonce=' . $result['nonce'], $result['redirectUrl']);

	}//end testValidLoginInitiationRedirectsWithStateAndNonce()

	/**
	 * An unregistered issuer is rejected with HTTP 400, no redirect built.
	 *
	 * @return void
	 */
	public function testUnregisteredIssuerRejectedBeforeRedirect(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());

		$this->expectException(LtiValidationException::class);

		try {
			$service->initiateLogin(
				self::DEPLOYMENT_UUID,
				['iss' => 'https://evil.example', 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
				'https://tool.example/launch'
			);
		} catch (LtiValidationException $exception) {
			$this->assertSame(400, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testUnregisteredIssuerRejectedBeforeRedirect()

	// =========================================================================
	// REQ-LTI-005 — launch validation
	// =========================================================================

	/**
	 * A valid, freshly-issued launch redirects into the consuming app and
	 * consumes the nonce (a replay of the SAME token is then rejected).
	 *
	 * @return void
	 */
	public function testValidLaunchRedirectsAndConsumesNonce(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());

		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$idToken = $this->signAsPlatform($this->baseLaunchClaims($login['nonce']));

		$result = $service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);

		$this->assertStringStartsWith('https://tool.example/app', $result['redirectUrl']);
		$this->assertStringContainsString('lti_launch=', $result['redirectUrl']);
		$this->assertNotEmpty($result['launchReference']);

	}//end testValidLaunchRedirectsAndConsumesNonce()

	/**
	 * A replayed nonce (second launch with the same nonce) is rejected 401.
	 *
	 * @return void
	 */
	public function testReplayedNonceRejected(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());

		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);
		$idToken = $this->signAsPlatform($this->baseLaunchClaims($login['nonce']));

		// First launch succeeds and consumes the nonce.
		$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);

		// A second launch presenting the SAME nonce must be rejected as a replay.
		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(401, $exception->getHttpStatus());
			$this->assertStringContainsString('replay', strtolower($exception->getMessage()));
			throw $exception;
		}

	}//end testReplayedNonceRejected()

	/**
	 * An id_token with an unknown/unresolvable kid is rejected 401.
	 *
	 * @return void
	 */
	public function testUnknownKidRejected(): void {
		// The JWKS resolver never resolves any key (simulating an unknown kid).
		$jwksResolver = $this->createMock(LtiJwksResolverService::class);
		$jwksResolver->method('resolveKey')->willReturn(null);

		$service = $this->makeService($this->makeResolver(), $jwksResolver);
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);
		$idToken = $this->signAsPlatform($this->baseLaunchClaims($login['nonce']));

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(401, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testUnknownKidRejected()

	/**
	 * A token signed by a DIFFERENT key than the one the resolver returns
	 * (simulating a forged token with a valid-looking but wrong-kid
	 * signature) fails signature verification — 401.
	 *
	 * @return void
	 */
	public function testForgedSignatureRejected(): void {
		$wrongKey = JWKFactory::createRSAKey(2048, ['kid' => 'platform-kid-1', 'alg' => 'RS256', 'use' => 'sig']);

		// Resolver returns the LEGITIMATE public key, but the token below is
		// signed with a DIFFERENT private key sharing the same kid — the
		// classic "forged token with a valid-looking but wrong-kid
		// signature" attack (REQ-LTI security test list).
		$jwksResolver = $this->makeJwksResolver($this->platformKey->toPublic());

		$service = $this->makeService($this->makeResolver(), $jwksResolver);
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$algorithmManager = new AlgorithmManager([new RS256()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);
		$serializer = new CompactSerializer();
		$jws = $jwsBuilder->create()
			->withPayload(json_encode($this->baseLaunchClaims($login['nonce'])))
			->addSignature($wrongKey, ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'platform-kid-1'])
			->build();
		$forgedToken = $serializer->serialize($jws, 0);

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($forgedToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(401, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testForgedSignatureRejected()

	/**
	 * Algorithm-confusion guard: a token header `alg` that does not match
	 * the resolved JWK's own pinned `alg` (e.g. PS256 header against an
	 * RS256-pinned key) is rejected, even though the underlying RSA key
	 * material is mathematically capable of both signature schemes.
	 *
	 * @return void
	 */
	public function testAlgorithmConfusionRejected(): void {
		// The resolved JWK is pinned to RS256 (as generated in setUp()).
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		// Sign with the SAME RSA key material but an alg-unpinned JWK (an
		// attacker forging a raw JWS is not bound by our own JWK's 'alg'
		// pin — that pin only protects verification, which is exactly what
		// this test exercises) claiming PS256 in the header.
		$unpinnedSigningKey = new JWK(array_diff_key($this->platformKey->all(), ['alg' => 1, 'use' => 1]));

		$algorithmManager = new AlgorithmManager([new \Jose\Component\Signature\Algorithm\PS256()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);
		$serializer = new CompactSerializer();
		$jws = $jwsBuilder->create()
			->withPayload(json_encode($this->baseLaunchClaims($login['nonce'])))
			->addSignature($unpinnedSigningKey, ['alg' => 'PS256', 'typ' => 'JWT', 'kid' => $this->platformKey->get('kid')])
			->build();
		$confusedToken = $serializer->serialize($jws, 0);

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($confusedToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(401, $exception->getHttpStatus());
			$this->assertStringContainsString('algorithm', strtolower($exception->getMessage()));
			throw $exception;
		}

	}//end testAlgorithmConfusionRejected()

	/**
	 * An expired token is rejected (reused AuthorizationService::validatePayload path).
	 *
	 * @return void
	 */
	public function testExpiredTokenRejected(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$expiredClaims = $this->baseLaunchClaims(
			$login['nonce'],
			['iat' => (time() - 7200), 'exp' => (time() - 3600)]
		);
		$idToken = $this->signAsPlatform($expiredClaims);

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(401, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testExpiredTokenRejected()

	/**
	 * A token signed for the wrong audience (aud does not match the
	 * registration's client_id) is rejected 401.
	 *
	 * @return void
	 */
	public function testWrongAudienceRejected(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$idToken = $this->signAsPlatform($this->baseLaunchClaims($login['nonce'], ['aud' => 'some-other-client']));

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(401, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testWrongAudienceRejected()

	/**
	 * A token whose iss matches no registered lti_platform is rejected 400.
	 *
	 * @return void
	 */
	public function testWrongIssuerRejected(): void {
		// A legitimate login-initiation was never performed for the unknown
		// issuer, so no nonce is pre-seeded — but we exercise the launch
		// route directly with a token bearing an unregistered iss.
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());
		$idToken = $this->signAsPlatform($this->baseLaunchClaims('some-nonce', ['iss' => 'https://not-registered.example']));

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, null, null);
		} catch (LtiValidationException $exception) {
			$this->assertSame(400, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testWrongIssuerRejected()

	/**
	 * A deployment_id claim not registered under the resolved platform is rejected 400.
	 *
	 * @return void
	 */
	public function testUnregisteredDeploymentIdRejected(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$idToken = $this->signAsPlatform(
			$this->baseLaunchClaims(
				$login['nonce'],
				['https://purl.imsglobal.org/spec/lti/claim/deployment_id' => 'not-a-real-deployment']
			)
		);

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(400, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testUnregisteredDeploymentIdRejected()

	/**
	 * Missing message_type/version claims are rejected 400.
	 *
	 * @return void
	 */
	public function testMissingMessageTypeRejected(): void {
		$service = $this->makeService($this->makeResolver(), $this->makeJwksResolver());
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$claims = $this->baseLaunchClaims($login['nonce']);
		unset($claims['https://purl.imsglobal.org/spec/lti/claim/message_type']);
		$idToken = $this->signAsPlatform($claims);

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(400, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testMissingMessageTypeRejected()

	// =========================================================================
	// REQ-LTI-006 — Platform-role launch initiation + Deep Linking
	// =========================================================================

	/**
	 * A Platform-role launch is signed with the tool registration's active key.
	 *
	 * @return void
	 */
	public function testPlatformRoleLaunchSignedWithToolActiveKey(): void {
		$toolUuid = 'tool-uuid-1';
		$tool = new ObjectEntity();
		$tool->setUuid($toolUuid);
		$tool->setObject(['clientId' => 'tool-client-id', 'launchUrl' => 'https://tool.example/launch']);

		$deployment = new ObjectEntity();
		$deployment->setUuid('dep-tool-1');
		$deployment->setObject(['deploymentId' => 'deploy-2', 'ltiToolId' => $toolUuid]);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			fn ($uuid) => ($uuid === 'dep-tool-1') ? $deployment : null
		);
		$resolver->method('findRegistrationByUuid')->willReturnCallback(
			fn ($type, $uuid) => ($type === 'lti_tool' && $uuid === $toolUuid) ? $tool : null
		);

		// Real LtiKeyService with an in-memory registration store so the
		// tool has a genuine generated active key.
		$registrations = [$toolUuid => ['signingKeys' => []]];
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
			function ($object = [], $register = null, $schema = null, $uuid = null) use (&$registrations) {
				$registrations[$uuid] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);
		$keyService = new LtiKeyService($objectService, new NullLogger());
		$activeKey = $keyService->generateKey('lti_tool', $toolUuid);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());

		$service = new LtiLaunchService(
			$resolver,
			$this->makeAuthorizationService(),
			$this->makeJwksResolver(),
			$keyService,
			$cacheFactory,
			new NullLogger()
		);

		$result = $service->initiatePlatformLaunch('dep-tool-1', 'https://our-platform.example', 'user-42', 'LtiResourceLinkRequest');

		$this->assertSame('https://tool.example/launch', $result['formActionUrl']);
		$this->assertNotEmpty($result['idToken']);

		// Verify the id_token's header kid matches the tool's active key.
		$parts = explode('.', $result['idToken']);
		$header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
		$this->assertSame($activeKey['kid'], $header['kid']);

	}//end testPlatformRoleLaunchSignedWithToolActiveKey()

	/**
	 * buildDeepLinkingResponse() signs the response with the platform
	 * registration's active key and carries the content items claim.
	 *
	 * @return void
	 */
	public function testDeepLinkingResponseSignedWithPlatformActiveKeyAndContainsContentItems(): void {
		$platformUuid = 'plat-dl-1';
		$registrations = [$platformUuid => ['signingKeys' => []]];
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
			function ($object = [], $register = null, $schema = null, $uuid = null) use (&$registrations) {
				$registrations[$uuid] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);
		$keyService = new LtiKeyService($objectService, new NullLogger());
		$activeKey = $keyService->generateKey('lti_platform', $platformUuid);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$platform = new ObjectEntity();
		$platform->setUuid($platformUuid);
		$platform->setObject([]);
		$resolver->method('findRegistrationByUuid')->willReturnCallback(
			fn ($type, $uuid) => ($type === 'lti_platform' && $uuid === $platformUuid) ? $platform : null
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());

		$service = new LtiLaunchService(
			$resolver,
			$this->makeAuthorizationService(),
			$this->makeJwksResolver(),
			$keyService,
			$cacheFactory,
			new NullLogger()
		);

		$contentItems = [['type' => 'ltiResourceLink', 'title' => 'Chapter 1', 'url' => 'https://tool.example/content/1']];
		$result = $service->buildDeepLinkingResponse(
			$platformUuid,
			'https://platform.example/deep-link-return',
			$contentItems,
			self::DEPLOYMENT_ID_CLAIM,
			['aud' => self::PLATFORM_ISS]
		);

		$this->assertSame('https://platform.example/deep-link-return', $result['formActionUrl']);

		$parts = explode('.', $result['idToken']);
		$header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
		$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

		$this->assertSame($activeKey['kid'], $header['kid']);
		$this->assertSame('LtiDeepLinkingResponse', $payload['https://purl.imsglobal.org/spec/lti/claim/message_type']);
		$this->assertSame($contentItems, $payload['https://purl.imsglobal.org/spec/lti-dl/claim/content_items']);

	}//end testDeepLinkingResponseSignedWithPlatformActiveKeyAndContainsContentItems()

	/**
	 * verifyDeepLinkingResponse() returns content items only after successful verification.
	 *
	 * @return void
	 */
	public function testVerifyDeepLinkingResponseReturnsContentItemsOnSuccess(): void {
		$toolUuid = 'tool-dl-1';
		$toolClientId = 'tool-dl-client';
		$tool = new ObjectEntity();
		$tool->setUuid($toolUuid);
		$tool->setObject(['clientId' => $toolClientId, 'jwksUri' => 'https://tool.example/jwks.json']);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findToolByClientId')->willReturnCallback(fn ($clientId) => ($clientId === $toolClientId) ? $tool : null);

		$service = $this->makeService($resolver, $this->makeJwksResolver());

		$now = time();
		$contentItems = [['type' => 'ltiResourceLink', 'title' => 'Selected content']];
		$claims = [
			'iss' => $toolClientId,
			'aud' => $toolClientId,
			'iat' => $now,
			'exp' => ($now + 300),
			'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingResponse',
			'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
			'https://purl.imsglobal.org/spec/lti-dl/claim/content_items' => $contentItems,
		];
		$idToken = $this->signAsPlatform($claims);

		$result = $service->verifyDeepLinkingResponse($idToken);

		$this->assertSame($contentItems, $result);

	}//end testVerifyDeepLinkingResponseReturnsContentItemsOnSuccess()

	/**
	 * verifyDeepLinkingResponse() rejects a token whose message_type is not LtiDeepLinkingResponse.
	 *
	 * @return void
	 */
	public function testVerifyDeepLinkingResponseRejectsWrongMessageType(): void {
		$toolUuid = 'tool-dl-2';
		$toolClientId = 'tool-dl-client-2';
		$tool = new ObjectEntity();
		$tool->setUuid($toolUuid);
		$tool->setObject(['clientId' => $toolClientId, 'jwksUri' => 'https://tool.example/jwks.json']);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findToolByClientId')->willReturnCallback(fn ($clientId) => ($clientId === $toolClientId) ? $tool : null);

		$service = $this->makeService($resolver, $this->makeJwksResolver());

		$now = time();
		$idToken = $this->signAsPlatform(
			[
				'iss' => $toolClientId,
				'aud' => $toolClientId,
				'iat' => $now,
				'exp' => ($now + 300),
				'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
				'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
			]
		);

		$this->expectException(LtiValidationException::class);
		$service->verifyDeepLinkingResponse($idToken);

	}//end testVerifyDeepLinkingResponseRejectsWrongMessageType()

	// =========================================================================
	// REQ-LTI-011 — registration trust gate, integration
	//
	// LtiRegistrationResolverService's own status-gating logic is unit-tested
	// independently (LtiRegistrationResolverServiceTest — pending/suspended
	// registrations resolve as null). These tests prove LtiLaunchService's
	// calling code has no bypass around that gate: when the resolver returns
	// null (exactly what it does for a pending/suspended registration), the
	// login-initiation and launch paths reject with the SAME shape as a
	// genuinely unregistered issuer — no separate "found but not approved"
	// branch exists in LtiLaunchService that could leak a distinguishing
	// response.
	// =========================================================================

	/**
	 * A login-initiation request for a registration the resolver reports as
	 * not-found (the exact return value a `pending` platform produces) is
	 * rejected identically to an unregistered issuer — HTTP 400, no redirect,
	 * no nonce persisted.
	 *
	 * @return void
	 */
	public function testPendingPlatformLoginInitiationRejectedLikeUnregistered(): void {
		// A resolver that (like the real gated resolver would for a pending
		// registration) reports the issuer as not-found even though a row
		// exists for it.
		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findPlatformByIssuer')->willReturn(null);

		$service = $this->makeService($resolver, $this->makeJwksResolver());

		$this->expectException(LtiValidationException::class);
		try {
			$service->initiateLogin(
				self::DEPLOYMENT_UUID,
				['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
				'https://tool.example/launch'
			);
		} catch (LtiValidationException $exception) {
			$this->assertSame(400, $exception->getHttpStatus());
			// Same message shape testUnregisteredIssuerRejectedBeforeRedirect() asserts.
			$this->assertStringContainsString('Unregistered', $exception->getMessage());
			throw $exception;
		}

	}//end testPendingPlatformLoginInitiationRejectedLikeUnregistered()

	/**
	 * A launch `id_token` whose issuer resolves to null (the exact return
	 * value a `pending`/`suspended` platform produces) is rejected
	 * identically to an unregistered issuer — HTTP 400.
	 *
	 * @return void
	 */
	public function testPendingPlatformLaunchRejectedLikeUnregistered(): void {
		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findPlatformByIssuer')->willReturn(null);

		$service = $this->makeService($resolver, $this->makeJwksResolver());
		$idToken = $this->signAsPlatform($this->baseLaunchClaims('some-nonce'));

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($idToken, self::DEPLOYMENT_UUID, null, null);
		} catch (LtiValidationException $exception) {
			$this->assertSame(400, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testPendingPlatformLaunchRejectedLikeUnregistered()

	// =========================================================================
	// REQ-LTI-012 — identity-linking policy never relaxes launch validation
	// =========================================================================

	/**
	 * Adding `identityPolicy`/`defaultProvisionGroup` to a platform's data
	 * has no effect on `validateLaunch()`'s own cryptographic rejection path
	 * — a forged signature is rejected exactly as it is without those fields
	 * present, proving `validateLaunch()` never branches on identity-linking
	 * policy (design.md "Inbound-JWT security posture", REQ-LTI-012 scenario
	 * 4). Identity resolution itself (unlinked/auto-provision branching) is
	 * unit-tested independently in LtiIdentityLinkServiceTest — this test
	 * only proves the trust decision is unaffected by the new field's mere
	 * presence.
	 *
	 * @return void
	 */
	public function testForgedSignatureRejectedRegardlessOfIdentityPolicy(): void {
		$wrongKey = JWKFactory::createRSAKey(2048, ['kid' => 'platform-kid-1', 'alg' => 'RS256', 'use' => 'sig']);

		$platform = new ObjectEntity();
		$platform->setUuid(self::PLATFORM_UUID);
		$platform->setObject(
			[
				'issuer' => self::PLATFORM_ISS,
				'clientId' => self::CLIENT_ID,
				'authLoginUrl' => 'https://platform.example/auth',
				'jwksUri' => 'https://platform.example/jwks.json',
				// Present to prove validateLaunch() ignores these entirely.
				'identityPolicy' => 'autoProvisionAsRole',
				'defaultProvisionGroup' => 'scholiq-lti-learners',
			]
		);

		$deployment = new ObjectEntity();
		$deployment->setUuid(self::DEPLOYMENT_UUID);
		$deployment->setObject(
			[
				'deploymentId' => self::DEPLOYMENT_ID_CLAIM,
				'ltiPlatformId' => self::PLATFORM_UUID,
				'launchTargetUrl' => 'https://tool.example/app',
			]
		);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findPlatformByIssuer')->willReturn($platform);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			fn ($uuid) => ($uuid === self::DEPLOYMENT_UUID) ? $deployment : null
		);
		$resolver->method('findDeployment')->willReturn($deployment);

		$jwksResolver = $this->makeJwksResolver($this->platformKey->toPublic());
		$service = $this->makeService($resolver, $jwksResolver);

		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		$algorithmManager = new AlgorithmManager([new RS256()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);
		$serializer = new CompactSerializer();
		$jws = $jwsBuilder->create()
			->withPayload(json_encode($this->baseLaunchClaims($login['nonce'])))
			->addSignature($wrongKey, ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'platform-kid-1'])
			->build();
		$forgedToken = $serializer->serialize($jws, 0);

		$this->expectException(LtiValidationException::class);
		try {
			$service->validateLaunch($forgedToken, self::DEPLOYMENT_UUID, $login['state'], $login['state']);
		} catch (LtiValidationException $exception) {
			$this->assertSame(401, $exception->getHttpStatus());
			throw $exception;
		}

	}//end testForgedSignatureRejectedRegardlessOfIdentityPolicy()

	// =========================================================================
	// REQ-LTI-013 — resource-link-to-consuming-app-object mapping seam
	// =========================================================================

	/**
	 * An exact `resourceLinkId` match resolves to its configured target.
	 *
	 * @return void
	 */
	public function testResolveResourceMappingExactMatch(): void {
		$deployment = new ObjectEntity();
		$deployment->setUuid('dep-rlm-1');
		$deployment->setObject(
			[
				'resourceLinkMappings' => [
					['resourceLinkId' => 'course-101', 'targetType' => 'register/schema', 'targetId' => '20/145'],
				],
			]
		);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			fn ($uuid) => ($uuid === 'dep-rlm-1') ? $deployment : null
		);

		$service = $this->makeService($resolver, $this->makeJwksResolver());
		$result = $service->resolveResourceMapping('dep-rlm-1', 'course-101');

		$this->assertSame(['targetType' => 'register/schema', 'targetId' => '20/145'], $result);

	}//end testResolveResourceMappingExactMatch()

	/**
	 * An unmapped `resourceLinkId` falls back to the deployment-default
	 * (empty-`resourceLinkId`) entry when one is configured.
	 *
	 * @return void
	 */
	public function testResolveResourceMappingFallsBackToDeploymentDefault(): void {
		$deployment = new ObjectEntity();
		$deployment->setUuid('dep-rlm-2');
		$deployment->setObject(
			[
				'resourceLinkMappings' => [
					['resourceLinkId' => '', 'targetType' => 'register/schema', 'targetId' => '20/999'],
				],
			]
		);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			fn ($uuid) => ($uuid === 'dep-rlm-2') ? $deployment : null
		);

		$service = $this->makeService($resolver, $this->makeJwksResolver());
		$result = $service->resolveResourceMapping('dep-rlm-2', 'course-777');

		$this->assertSame(['targetType' => 'register/schema', 'targetId' => '20/999'], $result);

	}//end testResolveResourceMappingFallsBackToDeploymentDefault()

	/**
	 * A deployment with no `resourceLinkMappings[]` configured at all
	 * resolves to `null` for any `resourceLinkId`.
	 *
	 * @return void
	 */
	public function testResolveResourceMappingReturnsNullWhenUnconfigured(): void {
		$deployment = new ObjectEntity();
		$deployment->setUuid('dep-rlm-3');
		$deployment->setObject([]);

		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			fn ($uuid) => ($uuid === 'dep-rlm-3') ? $deployment : null
		);

		$service = $this->makeService($resolver, $this->makeJwksResolver());

		$this->assertNull($service->resolveResourceMapping('dep-rlm-3', 'course-anything'));

	}//end testResolveResourceMappingReturnsNullWhenUnconfigured()

	/**
	 * An unknown deployment uuid resolves to `null` rather than throwing.
	 *
	 * @return void
	 */
	public function testResolveResourceMappingReturnsNullForUnknownDeployment(): void {
		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findDeploymentByUuid')->willReturn(null);

		$service = $this->makeService($resolver, $this->makeJwksResolver());

		$this->assertNull($service->resolveResourceMapping('not-a-real-deployment', 'course-101'));

	}//end testResolveResourceMappingReturnsNullForUnknownDeployment()
}//end class
