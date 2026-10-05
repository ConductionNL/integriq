<?php

/**
 * Inbound credential checks, pinned through their real callers.
 *
 * Drives the endpoint runtime's authentication rule
 * (EndpointService::processAuthenticationRule) and the LTI timing check
 * (LtiLaunchService::validateTiming) with REAL signed tokens: HS256 built
 * with a shared secret, RS256 and PS256 built with a freshly generated RSA
 * key. Every consumer is an object of integriq's real `consumer` schema and
 * is validated against that fragment before it is served. The jti replay
 * cache is a real in-memory cache, not a mock.
 *
 * What it pins is integriq's behaviour as it was before the gate 23
 * migration: who gets in, who is refused, with which error, and which
 * consumer the runtime keys rate limits on afterwards. The migration moved
 * the checks into OpenRegister's AuthorizationService; these tests are the
 * proof that the move kept every outcome.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\Lti\LtiJwksResolverService;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiRegistrationResolverService;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuthorizationService as OpenRegisterAuthorizationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Pins the inbound credential outcomes through EndpointService and LtiLaunchService.
 */
class InboundCredentialCallersTest extends TestCase {

	private const HMAC_SECRET = 'a-shared-hmac-secret-that-is-at-least-thirty-two-bytes-long';

	/** @var string PEM of the RSA private key the RS/PS consumers verify against. */
	private static string $privatePem = '';

	/** @var string PEM of the matching public key. */
	private static string $publicPem = '';

	/** @var string PEM of a second, unrelated RSA private key. */
	private static string $otherPrivatePem = '';

	/** @var array<string, ObjectEntity> Consumers by name. */
	private array $consumers = [];

	/** @var array<int, IUser|null> Every acting user set during the call, in order. */
	private array $actingUsers = [];

	private IUserManager $userManager;
	private IUserSession $userSession;
	private ObjectService $objectService;
	private IGroupManager $groupManager;
	private ICacheFactory $cacheFactory;
	private IRequest $request;
	private IUser $alice;

	/**
	 * Generate the RSA keys once: key generation is the slow part.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($key, $privatePem);
		self::$privatePem = $privatePem;
		self::$publicPem = openssl_pkey_get_details($key)['key'];

		$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($other, $otherPem);
		self::$otherPrivatePem = $otherPem;
	}//end setUpBeforeClass()

	/**
	 * Build the consumers and the Nextcloud doubles the checks read from.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->alice = $this->createMock(IUser::class);
		$this->alice->method('getUID')->willReturn('alice');

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(
			fn ($uid) => ($uid === 'alice' ? $this->alice : null)
		);

		$this->actingUsers = [];
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('setVolatileActiveUser')->willReturnCallback(
			function ($user): void {
				$this->actingUsers[] = $user;
			}
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);

		// One real in-memory store per cache namespace, shared by every
		// service built in this test, so a jti seen once is seen again.
		$stores = [];
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('createDistributed')->willReturnCallback(
			function ($namespace) use (&$stores) {
				if (isset($stores[$namespace]) === false) {
					$stores[$namespace] = new ArrayCache();
				}

				return $stores[$namespace];
			}
		);

		$publicKey = base64_encode(self::$publicPem);
		$this->consumers = [];
		$this->addConsumer(name: 'hs-consumer', type: 'jwt', configuration: ['algorithm' => 'HS256', 'publicKey' => self::HMAC_SECRET]);
		$this->addConsumer(name: 'rs-consumer', type: 'jwt', configuration: ['algorithm' => 'RS256', 'publicKey' => $publicKey]);
		$this->addConsumer(name: 'ps-consumer', type: 'jwt', configuration: ['algorithm' => 'PS256', 'publicKey' => $publicKey]);
		$this->addConsumer(name: 'key-consumer', type: 'apiKey', configuration: ['apiKey' => 'the-consumer-api-key']);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config = []) {
				$filters = ($config['filters'] ?? []);
				if (($filters['register'] ?? null) !== 'integriq' || ($filters['schema'] ?? null) !== 'consumer') {
					return ['results' => []];
				}

				if (isset($filters['name']) === true) {
					$match = ($this->consumers[$filters['name']] ?? null);
					return ['results' => ($match === null ? [] : [$match])];
				}

				return ['results' => array_values($this->consumers)];
			}
		);
	}//end setUp()

	/**
	 * Register a consumer object, validated against the real consumer schema.
	 *
	 * @param string $name          Consumer name (the JWT issuer).
	 * @param string $type          authorizationType.
	 * @param array  $configuration authorizationConfiguration.
	 *
	 * @return void
	 */
	private function addConsumer(string $name, string $type, array $configuration): void {
		$data = [
			'uuid' => 'uuid-' . $name,
			'name' => $name,
			'authorizationType' => $type,
			'authorizationConfiguration' => $configuration,
			'userId' => 'alice',
		];
		$this->assertSame([], RegisterSchemaValidator::errors('consumer', $data), 'consumer fixture must satisfy the real schema');

		$consumer = new ObjectEntity();
		$consumer->setUuid('uuid-' . $name);
		$consumer->setObject($data);
		$this->consumers[$name] = $consumer;
	}//end addConsumer()

	/**
	 * The credential checks the callers are wired to: OpenRegister's real
	 * AuthorizationService behind integriq's bridge and consumer source.
	 *
	 * @return OpenRegisterCredentialBridge
	 */
	private function credentials(): OpenRegisterCredentialBridge {
		return new OpenRegisterCredentialBridge(
			authorization: new OpenRegisterAuthorizationService(
				userManager: $this->userManager,
				userSession: $this->userSession,
				consumerMapper: $this->createMock(ConsumerMapper::class),
				cacheFactory: $this->cacheFactory,
				groupManager: $this->groupManager,
				request: $this->request,
			),
			objectService: $this->objectService,
			userSession: $this->userSession,
		);
	}//end credentials()

	/**
	 * An EndpointService wired to the given credential checks.
	 *
	 * @param object $credentials The credential checks.
	 *
	 * @return EndpointService
	 */
	private function endpointService(object $credentials): EndpointService {
		$reflection = new ReflectionClass(EndpointService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('authorizationService')->setValue($service, $credentials);

		return $service;
	}//end endpointService()

	/**
	 * Run the endpoint runtime's authentication rule.
	 *
	 * @param EndpointService $service        The runtime.
	 * @param array           $authentication The rule's authentication block.
	 * @param array           $headers        The request headers.
	 *
	 * @return array|JSONResponse
	 */
	private function runRule(EndpointService $service, array $authentication, array $headers): array|JSONResponse {
		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => ['authentication' => $authentication]]);
		$method = (new ReflectionClass(EndpointService::class))->getMethod('processAuthenticationRule');

		return $method->invoke($service, $rule, ['headers' => $headers]);
	}//end runRule()

	/**
	 * Sign a JWT with web-token, independent of the code under test.
	 *
	 * @param string $algorithm HS256, RS256 or PS256.
	 * @param array  $payload   Claims.
	 * @param string $keyPem    RSA private key PEM (ignored for HS256).
	 *
	 * @return string Compact JWS.
	 */
	private function sign(string $algorithm, array $payload, string $keyPem = ''): string {
		$algorithmObject = match ($algorithm) {
			'HS256' => new HS256(),
			'RS256' => new RS256(),
			'PS256' => new PS256(),
		};
		if ($algorithm === 'HS256') {
			$jwk = JWKFactory::createFromSecret(self::HMAC_SECRET, ['alg' => 'HS256', 'use' => 'sig']);
		} else {
			$jwk = JWKFactory::createFromKey(($keyPem !== '' ? $keyPem : self::$privatePem), null, ['alg' => $algorithm, 'use' => 'sig']);
		}

		$jws = (new JWSBuilder(new AlgorithmManager([$algorithmObject])))
			->create()
			->withPayload(json_encode($payload, JSON_THROW_ON_ERROR))
			->addSignature($jwk, ['alg' => $algorithm, 'typ' => 'JWT'])
			->build();

		return (new CompactSerializer())->serialize($jws, 0);
	}//end sign()

	/**
	 * Claims for a token issued now.
	 *
	 * @param string $issuer The issuer.
	 * @param array  $extra  Extra or overriding claims.
	 *
	 * @return array
	 */
	private function claims(string $issuer, array $extra = []): array {
		return array_merge(['iss' => $issuer, 'iat' => time(), 'exp' => (time() + 600)], $extra);
	}//end claims()

	/**
	 * Assert a 401 refusal with the given error.
	 *
	 * @param mixed  $result The rule result.
	 * @param string $error  The expected error message.
	 *
	 * @return void
	 */
	private function assertRefused(mixed $result, string $error): void {
		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(401, $result->getStatus());
		$this->assertSame($error, $result->getData()['error']);
		$this->assertNotContains($this->alice, $this->actingUsers, 'a refused call must not leave the consumer\'s user acting');
	}//end assertRefused()

	/**
	 * Assert the call got in as the consumer's user and resolved the consumer.
	 *
	 * @param mixed  $result      The rule result.
	 * @param object $credentials The credential checks.
	 * @param string $consumer    The consumer name expected.
	 *
	 * @return void
	 */
	private function assertAdmitted(mixed $result, object $credentials, string $consumer): void {
		$this->assertIsArray($result, 'the rule returned a refusal: ' . json_encode(($result instanceof JSONResponse ? $result->getData() : $result)));
		$this->assertSame($this->alice, end($this->actingUsers));
		$resolved = $credentials->getResolvedConsumer();
		$this->assertInstanceOf(ObjectEntity::class, $resolved);
		$this->assertTrue($resolved === $this->consumers[$consumer], 'the runtime must key on the consumer object that authenticated');
	}//end assertAdmitted()

	/**
	 * Data: the three algorithm families integriq consumers are configured with.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function algorithms(): array {
		return [
			'HMAC HS256' => ['HS256', 'hs-consumer'],
			'RSA PKCS1 RS256' => ['RS256', 'rs-consumer'],
			'RSA-PSS PS256' => ['PS256', 'ps-consumer'],
		];
	}//end algorithms()

	/**
	 * A token signed with the consumer's key gets in as the consumer's user.
	 *
	 * @dataProvider algorithms
	 *
	 * @param string $algorithm The algorithm.
	 * @param string $consumer  The consumer.
	 *
	 * @return void
	 */
	public function testSignedTokenIsAdmittedAsItsConsumer(string $algorithm, string $consumer): void {
		$credentials = $this->credentials();
		$token = $this->sign($algorithm, $this->claims($consumer, ['jti' => 'jti-' . $algorithm]));

		$result = $this->runRule($this->endpointService($credentials), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertAdmitted($result, $credentials, $consumer);
	}//end testSignedTokenIsAdmittedAsItsConsumer()

	/**
	 * The jwt-zgw rule type takes the same path.
	 *
	 * @return void
	 */
	public function testJwtZgwRuleTypeIsAdmitted(): void {
		$credentials = $this->credentials();
		$token = $this->sign('HS256', $this->claims('hs-consumer'));

		$result = $this->runRule($this->endpointService($credentials), ['type' => 'jwt-zgw'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertAdmitted($result, $credentials, 'hs-consumer');
	}//end testJwtZgwRuleTypeIsAdmitted()

	/**
	 * A jti presented a second time is refused, also by a fresh service on the same cache.
	 *
	 * @return void
	 */
	public function testReusedJtiIsRefused(): void {
		$token = $this->sign('RS256', $this->claims('rs-consumer', ['jti' => 'once-only']));

		$first = $this->runRule($this->endpointService($this->credentials()), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);
		$this->assertIsArray($first);

		$this->actingUsers = [];
		$second = $this->runRule($this->endpointService($this->credentials()), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);
		$this->assertRefused($second, 'The token has already been used (jti replay)');
	}//end testReusedJtiIsRefused()

	/**
	 * A token issued in the future, beyond the clock skew, is refused.
	 *
	 * @return void
	 */
	public function testFutureIatIsRefused(): void {
		$token = $this->sign('HS256', $this->claims('hs-consumer', ['iat' => (time() + 3600), 'exp' => (time() + 3700)]));

		$result = $this->runRule($this->endpointService($this->credentials()), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertRefused($result, 'The token has an invalid issue time');
	}//end testFutureIatIsRefused()

	/**
	 * A token whose lifetime (exp - iat) exceeds 3600 seconds is refused.
	 *
	 * @dataProvider algorithms
	 *
	 * @param string $algorithm The algorithm.
	 * @param string $consumer  The consumer.
	 *
	 * @return void
	 */
	public function testOverLongLifetimeIsRefused(string $algorithm, string $consumer): void {
		$credentials = $this->credentials();
		$token = $this->sign($algorithm, $this->claims($consumer, ['exp' => (time() + 7200)]));

		$result = $this->runRule($this->endpointService($credentials), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertRefused($result, 'The token lifetime exceeds the maximum allowed duration');
		$this->assertNull($credentials->getResolvedConsumer(), 'a refused token must not leave a consumer for rate limits and call logs');
	}//end testOverLongLifetimeIsRefused()

	/**
	 * A lifetime of exactly 3600 seconds is still admitted.
	 *
	 * @return void
	 */
	public function testLifetimeOfExactlyAnHourIsAdmitted(): void {
		$credentials = $this->credentials();
		$iat = time();
		$token = $this->sign('HS256', $this->claims('hs-consumer', ['iat' => $iat, 'exp' => ($iat + 3600)]));

		$result = $this->runRule($this->endpointService($credentials), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertAdmitted($result, $credentials, 'hs-consumer');
	}//end testLifetimeOfExactlyAnHourIsAdmitted()

	/**
	 * An issuer that is no integriq consumer is refused.
	 *
	 * @return void
	 */
	public function testUnknownIssuerIsRefused(): void {
		$token = $this->sign('HS256', $this->claims('nobody-we-know'));

		$result = $this->runRule($this->endpointService($this->credentials()), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertRefused($result, 'The issuer was not found');
	}//end testUnknownIssuerIsRefused()

	/**
	 * A token naming one consumer but signed with another key is refused.
	 *
	 * @return void
	 */
	public function testTokenSignedWithAnotherKeyIsRefused(): void {
		$token = $this->sign('RS256', $this->claims('rs-consumer'), self::$otherPrivatePem);

		$result = $this->runRule($this->endpointService($this->credentials()), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertRefused($result, 'The token could not be validated');
	}//end testTokenSignedWithAnotherKeyIsRefused()

	/**
	 * A token whose header algorithm is not the consumer's configured one is refused.
	 *
	 * The HS256 token claims the RS256 consumer: algorithm confusion.
	 *
	 * @return void
	 */
	public function testAlgorithmOtherThanTheConsumersIsRefused(): void {
		$token = $this->sign('HS256', $this->claims('rs-consumer'));

		$result = $this->runRule($this->endpointService($this->credentials()), ['type' => 'jwt'], ['Authorization' => 'Bearer ' . $token]);

		$this->assertRefused($result, 'The token could not be validated');
	}//end testAlgorithmOtherThanTheConsumersIsRefused()

	/**
	 * A consumer API key gets in and resolves its consumer; a wrong key does not.
	 *
	 * @return void
	 */
	public function testConsumerApiKeyIsAdmittedAndAWrongOneRefused(): void {
		$credentials = $this->credentials();
		$result = $this->runRule(
			$this->endpointService($credentials),
			['type' => 'apikey', 'keys' => []],
			['Authorization' => 'the-consumer-api-key']
		);
		$this->assertAdmitted($result, $credentials, 'key-consumer');

		$this->actingUsers = [];
		$refused = $this->runRule(
			$this->endpointService($this->credentials()),
			['type' => 'apikey', 'keys' => []],
			['Authorization' => 'not-the-key']
		);
		$this->assertRefused($refused, 'Invalid API key');
	}//end testConsumerApiKeyIsAdmittedAndAWrongOneRefused()

	/**
	 * The LTI timing check refuses an over-long lifetime and a future iat.
	 *
	 * @return void
	 */
	public function testLtiTimingKeepsTheLifetimeCapAndTheIatCheck(): void {
		$launch = new LtiLaunchService(
			$this->createMock(LtiRegistrationResolverService::class),
			$this->credentials(),
			$this->createMock(LtiJwksResolverService::class),
			$this->createMock(LtiKeyService::class),
			$this->cacheFactory,
			new NullLogger()
		);

		$launch->validateTiming(['iat' => time(), 'exp' => (time() + 300)]);

		try {
			$launch->validateTiming(['iat' => time(), 'exp' => (time() + 7200)]);
			$this->fail('an over-long lifetime must be refused');
		} catch (LtiValidationException $exception) {
			$this->assertSame('The token lifetime exceeds the maximum allowed duration', $exception->getMessage());
			$this->assertSame(401, $exception->getHttpStatus());
		}

		try {
			$launch->validateTiming(['iat' => (time() + 3600)]);
			$this->fail('a future iat must be refused');
		} catch (LtiValidationException $exception) {
			$this->assertSame('The token has an invalid issue time', $exception->getMessage());
		}
	}//end testLtiTimingKeepsTheLifetimeCapAndTheIatCheck()
}//end class
