<?php

/**
 * Unit tests for consumers whose JWT keys come from a published key set
 * (key source `jwks`) or an outside OpenID Connect provider (key source
 * `oidc`), run through the credential bridge and OpenRegister's real JWT
 * check with RS256 tokens signed here. Only the outbound fetch is faked.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\ConsumerKeySourceResolver;
use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\Jwks\JwksLookup;
use OCA\Integriq\Service\Jwks\JwksResolver;
use OCA\Integriq\Service\Jwks\JwksResolverProfile;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * JWKS and OIDC key sources, end to end through the bridge.
 */
#[CoversClass(ConsumerKeySourceResolver::class)]
#[CoversClass(IntegriqConsumerSource::class)]
#[CoversClass(OpenRegisterCredentialBridge::class)]
#[UsesClass(JwksResolver::class)]
#[UsesClass(JwksLookup::class)]
#[UsesClass(JwksResolverProfile::class)]
#[UsesClass(AuthenticationException::class)]
class ConsumerKeySourceJwtTest extends TestCase {

	private const ISSUER = 'https://login.example.nl/realms/gemeente';

	/**
	 * The signing keys by kid.
	 *
	 * @var array<string,\OpenSSLAsymmetricKey>
	 */
	private static array $keys = [];

	/**
	 * The consumer objects the register holds.
	 *
	 * @var array<int,ObjectEntity>
	 */
	private array $consumers = [];

	/**
	 * The endpoints fetched, in order.
	 *
	 * @var array<int,string>
	 */
	private array $fetched = [];

	/**
	 * The published key set: kid => alg (null leaves alg out).
	 *
	 * @var array<string,string|null>
	 */
	private array $published = ['k1' => 'RS256'];

	/**
	 * The user the consumer acted as, if any.
	 *
	 * @var IUser|null
	 */
	private ?IUser $actedAs = null;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->consumers = [];
		$this->fetched = [];
		$this->published = ['k1' => 'RS256'];
		$this->actedAs = null;
	}//end setUp()

	/**
	 * An RSA key per kid, made once.
	 *
	 * @param string $kid The kid.
	 *
	 * @return \OpenSSLAsymmetricKey The private key.
	 */
	private static function key(string $kid): \OpenSSLAsymmetricKey {
		if (isset(self::$keys[$kid]) === false) {
			self::$keys[$kid] = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		}

		return self::$keys[$kid];
	}//end key()

	/**
	 * Base64url without padding.
	 *
	 * @param string $raw The bytes.
	 *
	 * @return string The encoding.
	 */
	private static function b64(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}//end b64()

	/**
	 * A token signed RS256 with the key of `signWith`, naming `kid`.
	 *
	 * @param array<string,mixed> $claims   Extra claims.
	 * @param string              $kid      The kid in the header.
	 * @param string              $alg      The alg in the header.
	 * @param string|null         $signWith Whose key signs; defaults to the kid.
	 *
	 * @return string The `Bearer` header.
	 */
	private static function bearer(array $claims, string $kid = 'k1', string $alg = 'RS256', ?string $signWith = null): string {
		$header = self::b64((string)json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
		$payload = self::b64((string)json_encode($claims + ['iss' => self::ISSUER, 'iat' => time(), 'exp' => (time() + 300)]));
		openssl_sign($header . '.' . $payload, $signature, self::key(kid: ($signWith ?? $kid)), OPENSSL_ALGO_SHA256);

		return 'Bearer ' . $header . '.' . $payload . '.' . self::b64($signature);
	}//end bearer()

	/**
	 * The published JWKS document.
	 *
	 * @return array<string,mixed> The document.
	 */
	private function jwks(): array {
		$keys = [];
		foreach ($this->published as $kid => $alg) {
			$rsa = openssl_pkey_get_details(self::key(kid: $kid))['rsa'];
			$jwk = ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])];
			if ($alg !== null) {
				$jwk['alg'] = $alg;
			}

			$keys[] = $jwk;
		}

		return ['keys' => $keys];
	}//end jwks()

	/**
	 * Hold a consumer in the register.
	 *
	 * @param string              $name          Its name.
	 * @param array<string,mixed> $configuration Its authorization configuration.
	 *
	 * @return void
	 */
	private function consumer(string $name, array $configuration): void {
		$entity = new ObjectEntity();
		$entity->setUuid('consumer-' . count($this->consumers));
		$entity->setObject(['name' => $name, 'authorizationType' => 'jwt', 'authorizationConfiguration' => $configuration, 'userId' => 'svc']);
		$this->consumers[] = $entity;
	}//end consumer()

	/**
	 * The bridge over OpenRegister's real AuthorizationService, with key sources.
	 *
	 * @return OpenRegisterCredentialBridge The bridge.
	 */
	private function bridge(): OpenRegisterCredentialBridge {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$wanted = ($config['filters']['name'] ?? null);

				return ['results' => array_values(array_filter($this->consumers, static fn (ObjectEntity $c): bool => $wanted === null || $c->getObject()['name'] === $wanted))];
			}
		);
		$users = $this->createMock(IUserManager::class);
		$user = $this->createMock(IUser::class);
		$users->method('get')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->actedAs = $user;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(static fn (): ArrayCache => new ArrayCache());

		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint): ObjectEntity {
				$this->fetched[] = $source->getObject()['location'] . $endpoint;
				$body = match ($endpoint) {
					'/realms/gemeente/.well-known/openid-configuration' => ['issuer' => self::ISSUER, 'jwks_uri' => self::ISSUER . '/protocol/openid-connect/certs'],
					default => $this->jwks(),
				};
				$log = new ObjectEntity();
				$log->setObject(['response' => ['statusCode' => 200, 'body' => json_encode($body)]]);

				return $log;
			}
		);

		return new OpenRegisterCredentialBridge(
			authorization: new AuthorizationService(
				userManager: $users,
				userSession: $session,
				consumerMapper: (new ReflectionClass(ConsumerMapper::class))->newInstanceWithoutConstructor(),
				cacheFactory: $cacheFactory,
				groupManager: $this->createMock(IGroupManager::class),
				request: $this->createMock(IRequest::class),
			),
			objectService: $objects,
			userSession: $session,
			keySources: new ConsumerKeySourceResolver(cacheFactory: $cacheFactory, callService: $callService, logger: new NullLogger())
		);
	}//end bridge()

	/**
	 * The refusal reason of a call, or "allowed".
	 *
	 * @param OpenRegisterCredentialBridge $bridge        The bridge.
	 * @param string                       $authorization The header.
	 *
	 * @return string The outcome.
	 */
	private static function outcome(OpenRegisterCredentialBridge $bridge, string $authorization): string {
		try {
			$bridge->authorizeJwt($authorization);
		} catch (AuthenticationException $e) {
			return (string)($e->getDetails()['reason'] ?? $e->getMessage());
		}

		return 'allowed';
	}//end outcome()

	/**
	 * A jwks consumer, named for people, is found by its configured issuer and its token passes on the published key.
	 *
	 * @return void
	 */
	public function testAJwksConsumerTokenPassesOnThePublishedKey(): void {
		$this->consumer(name: 'Zaaksysteem Gemeente', configuration: ['keySource' => 'jwks', 'issuer' => self::ISSUER . '/', 'jwksUri' => 'https://keys.example.nl:8443/jwks.json?v=1']);
		$bridge = $this->bridge();

		$this->assertSame('allowed', self::outcome(bridge: $bridge, authorization: self::bearer(claims: [])));
		$this->assertSame('consumer-0', $bridge->getResolvedConsumer()?->getUuid());
		$this->assertNotNull($this->actedAs);
		$this->assertSame(['https://keys.example.nl:8443/jwks.json?v=1'], $this->fetched);
	}//end testAJwksConsumerTokenPassesOnThePublishedKey()

	/**
	 * A token signed by another key than the one its kid names is refused by the signature check.
	 *
	 * @return void
	 */
	public function testATokenSignedWithAnotherKeyIsRefused(): void {
		$this->consumer(name: 'zs', configuration: ['keySource' => 'jwks', 'issuer' => self::ISSUER, 'jwksUri' => 'https://keys.example.nl/jwks.json']);

		$this->assertNotSame('allowed', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: [], kid: 'k1', signWith: 'intruder')));
		$this->assertNull($this->actedAs);
	}//end testATokenSignedWithAnotherKeyIsRefused()

	/**
	 * HMAC against a published key set is refused, naming the algorithm, before anything is fetched.
	 *
	 * @return void
	 */
	public function testAnHmacTokenAgainstAPublishedKeySetIsRefused(): void {
		$this->consumer(name: 'zs', configuration: ['keySource' => 'jwks', 'issuer' => self::ISSUER, 'jwksUri' => 'https://keys.example.nl/jwks.json']);

		$this->assertSame(
			'The token algorithm HS256 is not accepted for a published key set; only RS and PS algorithms are',
			self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: [], alg: 'HS256'))
		);
		$this->assertSame([], $this->fetched);
	}//end testAnHmacTokenAgainstAPublishedKeySetIsRefused()

	/**
	 * An unknown kid, or a key published for another algorithm, is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownKidOrAKeyForAnotherAlgorithmIsRefused(): void {
		$this->consumer(name: 'zs', configuration: ['keySource' => 'jwks', 'issuer' => self::ISSUER, 'jwksUri' => 'https://keys.example.nl/jwks.json']);

		$this->assertSame(
			'The key the token names was not found in the issuer\'s published key set',
			self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: [], kid: 'k9'))
		);

		$this->published = ['k1' => 'RS512'];
		$this->assertSame(
			'The token algorithm does not match the key\'s algorithm',
			self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: []))
		);
	}//end testAnUnknownKidOrAKeyForAnotherAlgorithmIsRefused()

	/**
	 * An OIDC consumer reads the discovery document; the right audience passes, another is refused.
	 *
	 * @return void
	 */
	public function testAnOidcConsumerChecksTheAudience(): void {
		$this->published = ['k1' => null];
		$this->consumer(name: 'Keycloak gemeente', configuration: ['keySource' => 'oidc', 'issuerUrl' => self::ISSUER, 'audience' => 'integriq']);

		$this->assertSame('allowed', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: ['aud' => ['account', 'integriq']])));
		$this->assertSame(
			[
				'https://login.example.nl/realms/gemeente/.well-known/openid-configuration',
				'https://login.example.nl/realms/gemeente/protocol/openid-connect/certs',
			],
			$this->fetched
		);

		$this->assertSame('The token audience does not include integriq', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: ['aud' => 'other'])));
	}//end testAnOidcConsumerChecksTheAudience()

	/**
	 * An OIDC consumer refuses a missing required claim, and refuses without a configured audience.
	 *
	 * @return void
	 */
	public function testAnOidcConsumerRequiresItsClaims(): void {
		$this->consumer(name: 'kc', configuration: ['keySource' => 'oidc', 'issuerUrl' => self::ISSUER, 'audience' => 'integriq', 'requiredClaims' => ['azp' => 'zaaksysteem', 0 => 'sub']]);

		$this->assertSame('The token lacks the required claim azp', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: ['aud' => 'integriq', 'sub' => 'x'])));
		$this->assertSame('The token lacks the required claim sub', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: ['aud' => 'integriq', 'azp' => 'zaaksysteem'])));
		$this->assertSame('allowed', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: ['aud' => 'integriq', 'azp' => 'zaaksysteem', 'sub' => 'x'])));

		$this->consumers = [];
		$this->consumer(name: 'kc', configuration: ['keySource' => 'oidc', 'issuerUrl' => self::ISSUER]);
		$this->assertSame(
			'The consumer has no audience configured for its OpenID Connect provider',
			self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: ['aud' => 'integriq']))
		);
	}//end testAnOidcConsumerRequiresItsClaims()

	/**
	 * A consumer that merely carries the issuer as its name but takes its key from a set is not matched by name.
	 *
	 * @return void
	 */
	public function testAKeySetConsumerIsNotMatchedByName(): void {
		$this->consumer(name: self::ISSUER, configuration: ['keySource' => 'jwks', 'issuer' => 'https://elsewhere.example', 'jwksUri' => 'https://keys.example.nl/jwks.json']);

		$this->assertSame('The issuer was not found', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: [])));
		$this->assertSame([], $this->fetched);
	}//end testAKeySetConsumerIsNotMatchedByName()

	/**
	 * A static consumer is still found by name and verified on its stored key.
	 *
	 * @return void
	 */
	public function testAStaticConsumerIsUnchanged(): void {
		$pem = openssl_pkey_get_details(self::key(kid: 'k1'))['key'];
		$this->consumer(name: self::ISSUER, configuration: ['algorithm' => 'RS256', 'publicKey' => $pem]);

		$this->assertSame('allowed', self::outcome(bridge: $this->bridge(), authorization: self::bearer(claims: [])));
		$this->assertSame([], $this->fetched);
	}//end testAStaticConsumerIsUnchanged()
}//end class
