<?php

/**
 * Golden test pinning every outcome of LtiLaunchService's id_token verification and launch validation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-launch-id-token-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\Integriq\Tests\Helpers\OpenRegisterCredentials;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the accepted outcome and every refusal (message, HTTP status, details) of
 * verifyIdTokenSignature() and validateLaunch(), in the order the checks run.
 */
class LtiLaunchVerificationGoldenTest extends TestCase {

	private const PLATFORM_ISS = 'https://platform.example';
	private const CLIENT_ID = 'client-abc';
	private const TOOL_CLIENT_ID = 'tool-client-1';
	private const DEPLOYMENT_UUID = 'dep-uuid-1';
	private const OTHER_DEPLOYMENT_UUID = 'dep-uuid-2';
	private const DEPLOYMENT_ID_CLAIM = 'deploy-claim-1';

	/** @var JWK The platform's signing key. */
	private JWK $platformKey;

	/** @var JWK|null The key the JWKS resolver double hands back. */
	private ?JWK $resolvedKey;

	/** @var array<string, mixed> The platform registration's object. */
	private array $platformObject;

	/** @var string The launch target URL of the deployment. */
	private string $launchTarget = 'https://tool.example/app';

	/** @var string The uuid the deployment_id claim resolves to. */
	private string $claimDeploymentUuid = self::DEPLOYMENT_UUID;

	/** @var array<int, array<int, mixed>> The arguments resolveKey() was called with. */
	private array $resolveKeyCalls = [];

	/**
	 * Set up the keys and registration data.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->platformKey = JWKFactory::createRSAKey(2048, ['kid' => 'platform-kid-1', 'alg' => 'RS256', 'use' => 'sig']);
		$this->resolvedKey = $this->platformKey->toPublic();
		$this->platformObject = [
			'issuer' => self::PLATFORM_ISS,
			'clientId' => self::CLIENT_ID,
			'authLoginUrl' => 'https://platform.example/auth',
			'jwksUri' => 'https://platform.example/jwks.json',
		];

	}//end setUp()

	/**
	 * Build an ObjectEntity with a uuid and object.
	 *
	 * @param string $uuid   The uuid.
	 * @param array  $object The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $object): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($object);

		return $entity;

	}//end entity()

	/**
	 * Build the service under test with resolver and JWKS doubles reading this test's state.
	 *
	 * @return LtiLaunchService
	 */
	private function service(): LtiLaunchService {
		$resolver = $this->createMock(LtiRegistrationResolverService::class);
		$resolver->method('findPlatformByIssuer')->willReturnCallback(
			fn ($issuer, $clientId = null) => ($issuer === self::PLATFORM_ISS && ($clientId === null || $clientId === self::CLIENT_ID))
				? $this->entity('plat-uuid-1', $this->platformObject) : null
		);
		$resolver->method('findToolByClientId')->willReturnCallback(
			fn ($clientId) => ($clientId === self::TOOL_CLIENT_ID)
				? $this->entity('tool-uuid-1', ['clientId' => self::TOOL_CLIENT_ID, 'jwksUri' => 'https://tool.example/jwks']) : null
		);
		$resolver->method('findDeploymentByUuid')->willReturnCallback(
			fn ($uuid) => ($uuid === self::DEPLOYMENT_UUID)
				? $this->entity(self::DEPLOYMENT_UUID, ['deploymentId' => self::DEPLOYMENT_ID_CLAIM, 'ltiPlatformId' => 'plat-uuid-1', 'launchTargetUrl' => $this->launchTarget])
				: null
		);
		$resolver->method('findDeployment')->willReturnCallback(
			fn ($type, $registrationUuid, $claim) => ($type === 'lti_platform' && $registrationUuid === 'plat-uuid-1' && $claim === self::DEPLOYMENT_ID_CLAIM)
				? $this->entity($this->claimDeploymentUuid, ['launchTargetUrl' => $this->launchTarget]) : null
		);

		$jwksResolver = $this->createMock(LtiJwksResolverService::class);
		$jwksResolver->method('resolveKey')->willReturnCallback(
			function (...$arguments) {
				$this->resolveKeyCalls[] = $arguments;
				return $this->resolvedKey;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$stores = [];
		$cacheFactory->method('createDistributed')->willReturnCallback(
			function ($namespace) use (&$stores) {
				$stores[$namespace] = ($stores[$namespace] ?? new ArrayCache());
				return $stores[$namespace];
			}
		);
		$credentialCache = $this->createMock(ICacheFactory::class);
		$credentialCache->method('createDistributed')->willReturn(new ArrayCache());
		$bridge = OpenRegisterCredentials::bridge(
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(\OCA\OpenRegister\Service\ObjectService::class),
			$this->createMock(IGroupManager::class),
			$credentialCache,
			$this->createMock(IRequest::class)
		);
		$keyService = new LtiKeyService($this->createMock(\OCA\OpenRegister\Service\ObjectService::class), new NullLogger(), new AesTestCrypto());

		return new LtiLaunchService($resolver, $bridge, $jwksResolver, $keyService, $cacheFactory, new NullLogger());

	}//end service()

	/**
	 * Sign claims with a key, RS256.
	 *
	 * @param array    $claims The payload claims.
	 * @param JWK|null $key    The signing key; the platform key when null.
	 *
	 * @return string The compact JWS.
	 */
	private function sign(array $claims, ?JWK $key = null): string {
		$key = ($key ?? $this->platformKey);
		$jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))->create()
			->withPayload((string)json_encode($claims))
			->addSignature($key, ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'platform-kid-1'])
			->build();

		return (new CompactSerializer())->serialize($jws, 0);

	}//end sign()

	/**
	 * Build an unsigned compact token from a raw header and payload.
	 *
	 * @param array  $header  The protected header.
	 * @param string $payload The raw payload.
	 *
	 * @return string The compact token.
	 */
	private function raw(array $header, string $payload): string {
		$encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

		return $encode((string)json_encode($header)) . '.' . $encode($payload) . '.' . $encode('sig');

	}//end raw()

	/**
	 * The outcome of a call: the returned value, or the refusal's message, status and details.
	 *
	 * @param callable $call The call.
	 *
	 * @return array The outcome.
	 */
	private function outcome(callable $call): array {
		try {
			return ['ok' => $call()];
		} catch (LtiValidationException $exception) {
			return [$exception->getMessage(), $exception->getHttpStatus(), $exception->getDetails()];
		}

	}//end outcome()

	/**
	 * Every outcome of verifyIdTokenSignature(), in check order.
	 *
	 * @return void
	 */
	public function testVerifyIdTokenSignatureOutcomes(): void {
		$header = ['alg' => 'RS256', 'kid' => 'platform-kid-1'];
		$claims = ['iss' => self::PLATFORM_ISS, 'sub' => 'u-1'];
		$verify = fn (string $token, string $type = 'lti_platform') => $this->outcome(
			fn () => $this->service()->verifyIdTokenSignature($token, $type)
		);

		$this->assertSame(['Malformed id_token', 400, []], $verify('not-a-jws'));
		$this->assertSame(['id_token header missing kid/alg', 400, []], $verify($this->raw(['alg' => 'RS256'], '{}')));
		$this->assertSame(['id_token header missing kid/alg', 400, []], $verify($this->raw(['kid' => 'k'], '{}')));
		$this->assertSame(['id_token payload is not valid JSON', 400, []], $verify($this->raw($header, 'nope')));
		$this->assertSame(['id_token missing iss claim', 400, []], $verify($this->raw($header, '{"iss":""}')));
		$this->assertSame(
			['Unregistered id_token issuer', 400, ['iss' => 'https://other.example']],
			$verify($this->raw($header, '{"iss":"https://other.example"}'))
		);
		$this->assertSame(
			['Unregistered id_token issuer', 400, ['iss' => self::PLATFORM_ISS]],
			$verify($this->raw($header, '{"iss":"https://platform.example"}'), 'lti_tool')
		);

		$this->platformObject['jwksUri'] = '';
		$this->assertSame(['Registration has no jwks_uri configured', 400, []], $verify($this->sign($claims)));
		$this->platformObject['jwksUri'] = 'https://platform.example/jwks.json';

		$this->resolveKeyCalls = [];
		$this->resolvedKey = null;
		$this->assertSame(['Could not resolve a JWK for the presented kid', 401, ['kid' => 'platform-kid-1']], $verify($this->sign($claims)));
		$this->assertSame([['lti_platform', 'plat-uuid-1', 'https://platform.example/jwks.json', 'platform-kid-1']], $this->resolveKeyCalls);

		$this->resolvedKey = new JWK(array_merge($this->platformKey->toPublic()->all(), ['alg' => 'RS512']));
		$this->assertSame(['Token algorithm does not match the resolved key\'s algorithm', 401, []], $verify($this->sign($claims)));

		$keyWithoutAlg = $this->platformKey->toPublic()->all();
		unset($keyWithoutAlg['alg']);
		$this->resolvedKey = new JWK($keyWithoutAlg);
		$this->assertSame(['Unsupported id_token algorithm', 401, ['alg' => 'HS256']], $verify($this->raw(['alg' => 'HS256', 'kid' => 'k'], '{"iss":"https://platform.example"}')));
		$this->assertSame(['id_token signature verification failed', 401, []], $verify($this->raw(['alg' => 'RS256', 'kid' => 'k'], '{"iss":"https://platform.example"}')));

		$this->resolvedKey = $this->platformKey->toPublic();
		$otherKey = JWKFactory::createRSAKey(2048, ['alg' => 'RS256']);
		$this->assertSame(['id_token signature verification failed', 401, []], $verify($this->sign($claims, $otherKey)));

		$accepted = $this->service()->verifyIdTokenSignature($this->sign($claims), 'lti_platform');
		$this->assertSame($claims, $accepted[0]);
		$this->assertSame('plat-uuid-1', $accepted[1]->getUuid());

		$this->resolveKeyCalls = [];
		$tool = $this->service()->verifyIdTokenSignature($this->sign(['iss' => self::TOOL_CLIENT_ID]), 'lti_tool');
		$this->assertSame('tool-uuid-1', $tool[1]->getUuid());
		$this->assertSame([['lti_tool', 'tool-uuid-1', 'https://tool.example/jwks', 'platform-kid-1']], $this->resolveKeyCalls);

	}//end testVerifyIdTokenSignatureOutcomes()

	/**
	 * Start a login and return the service with the state and a claims builder for its nonce.
	 *
	 * @return array{0: LtiLaunchService, 1: string, 2: string}
	 */
	private function login(): array {
		$service = $this->service();
		$login = $service->initiateLogin(
			self::DEPLOYMENT_UUID,
			['iss' => self::PLATFORM_ISS, 'client_id' => self::CLIENT_ID, 'login_hint' => 'user-1', 'target_link_uri' => 'https://tool.example/'],
			'https://tool.example/launch'
		);

		return [$service, $login['state'], $login['nonce']];

	}//end login()

	/**
	 * Valid launch claims for a nonce, with overrides.
	 *
	 * @param string $nonce     The nonce.
	 * @param array  $overrides Claim overrides; a null value removes the claim.
	 *
	 * @return array The claims.
	 */
	private function launchClaims(string $nonce, array $overrides = []): array {
		$claims = array_merge(
			[
				'iss' => self::PLATFORM_ISS,
				'aud' => self::CLIENT_ID,
				'iat' => time(),
				'exp' => (time() + 300),
				'nonce' => $nonce,
				'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => self::DEPLOYMENT_ID_CLAIM,
				'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
				'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
			],
			$overrides
		);

		return array_filter($claims, fn ($value) => $value !== null);

	}//end launchClaims()

	/**
	 * Every refusal of validateLaunch() before and after the signature, and the accepted outcome.
	 *
	 * @return void
	 */
	public function testValidateLaunchOutcomes(): void {
		[$service, $state, $nonce] = $this->login();
		$token = $this->sign($this->launchClaims($nonce));
		$launch = function (string $idToken, string $deployment = self::DEPLOYMENT_UUID, ?string $cookie = 'S', ?string $posted = 'S') use (&$service, &$state) {
			return $this->outcome(
				fn () => $service->validateLaunch($idToken, $deployment, ($cookie === 'S') ? $state : $cookie, ($posted === 'S') ? $state : $posted)
			);
		};

		$this->assertSame(['Unknown LTI deployment', 400, []], $launch($token, 'nope', null, null));
		$this->assertSame(['Missing state', 401, []], $launch($token, self::DEPLOYMENT_UUID, '', 'S'));
		$this->assertSame(['Missing state', 401, []], $launch($token, self::DEPLOYMENT_UUID, 'S', null));
		$this->assertSame(['State mismatch', 401, []], $launch($token, self::DEPLOYMENT_UUID, 'S', 'other-state'));
		$this->assertSame(['Malformed id_token', 400, []], $launch('garbage'));
		$this->assertSame(['Missing nonce claim', 400, []], $launch($this->sign($this->launchClaims($nonce, ['nonce' => null]))));
		$this->assertSame(['Nonce already consumed or unknown (replay)', 401, []], $launch($this->sign($this->launchClaims('unknown-nonce'))));

		$claim = 'https://purl.imsglobal.org/spec/lti/claim/deployment_id';
		[$service, $state, $nonce] = $this->login();
		$this->assertSame(['Missing deployment_id claim', 400, []], $launch($this->sign($this->launchClaims($nonce, [$claim => null]))));

		[$service, $state, $nonce] = $this->login();
		$this->assertSame(
			['deployment_id claim not registered under the resolved platform', 400, ['deploymentId' => 'unknown-claim']],
			$launch($this->sign($this->launchClaims($nonce, [$claim => 'unknown-claim'])))
		);

		[$service, $state, $nonce] = $this->login();
		$this->claimDeploymentUuid = self::OTHER_DEPLOYMENT_UUID;
		$this->assertSame(
			['deployment_id claim not registered under the resolved platform', 400, ['deploymentId' => self::DEPLOYMENT_ID_CLAIM]],
			$launch($this->sign($this->launchClaims($nonce)))
		);
		$this->claimDeploymentUuid = self::DEPLOYMENT_UUID;

		[$service, $state, $nonce] = $this->login();
		$result = $service->validateLaunch($this->sign($this->launchClaims($nonce)), self::DEPLOYMENT_UUID, $state, $state);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $result['launchReference']);
		$this->assertSame('https://tool.example/app?lti_launch=' . $result['launchReference'], $result['redirectUrl']);
		$this->assertSame(['Nonce already consumed or unknown (replay)', 401, []], $launch($this->sign($this->launchClaims($nonce))));

		$this->launchTarget = 'https://tool.example/app?tenant=1';
		[$service, $state, $nonce] = $this->login();
		$result = $service->validateLaunch($this->sign($this->launchClaims($nonce)), self::DEPLOYMENT_UUID, $state, $state);
		$this->assertSame('https://tool.example/app?tenant=1&lti_launch=' . $result['launchReference'], $result['redirectUrl']);

	}//end testValidateLaunchOutcomes()
}//end class
