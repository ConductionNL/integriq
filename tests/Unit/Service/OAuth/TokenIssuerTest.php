<?php

/**
 * Integriq issues client credentials tokens signed with a key held in the credential broker.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\OAuth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\OAuth;

use OCA\Integriq\Controller\OAuthTokenController;
use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OAuth\OAuthError;
use OCA\Integriq\Service\OAuth\TokenIssuer;
use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The client credentials grant, end to end over an in-memory broker and consumer store.
 *
 * @covers \OCA\Integriq\Service\OAuth\IssuerKeyService
 * @covers \OCA\Integriq\Service\OAuth\TokenIssuer
 * @covers \OCA\Integriq\Service\OAuth\OAuthError
 * @covers \OCA\Integriq\Controller\OAuthTokenController
 * @uses   \OCA\Integriq\Service\Consumer\IntegriqConsumerSource
 */
final class TokenIssuerTest extends TestCase {

	private const NOW = 1794000000;

	/**
	 * The broker's vault: credential uuid => [secret, allowedApps, organisation].
	 *
	 * @var array<string, array{0: string, 1: array<int, string>, 2: string}>
	 */
	public array $vault = [];

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Stored consumers by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $consumers = [];

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	private IssuerKeyService $issuerKey;

	private TokenIssuer $issuer;

	/**
	 * A consumer with scopes zaken:read and zaken:write, an in-memory broker and app config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$test = $this;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.nl' . $path);
		$organisations = $this->createMock(OrganisationBridgeService::class);
		$organisations->method('getDefaultOrganisationUuid')->willReturn('org-1');

		$broker = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param TokenIssuerTest $test The test holding the vault.
			 */
			public function __construct(private readonly TokenIssuerTest $test) {
			}//end __construct()

			/**
			 * The broker's mint() shape.
			 *
			 * @return ObjectEntity
			 */
			public function mint(string $name, string $provider, string $owner, array $allowedApps = [], ?string $secret = null, string $scope = 'personal', ?string $organisation = null): ObjectEntity {
				$uuid = 'cred-' . (count($this->test->vault) + 1);
				$this->test->vault[$uuid] = [(string) $secret, $allowedApps, (string) $organisation];
				$entity = (new \ReflectionClass(ObjectEntity::class))->newInstanceWithoutConstructor();
				$entity->setUuid($uuid);
				return $entity;
			}//end mint()

			/**
			 * The broker's resolveInjectable() shape, with its app and organisation guards.
			 *
			 * @return string|null
			 */
			public function resolveInjectable(string $credentialId, string $appId, ?string $actingUserId = null, ?string $actingOrganisationId = null): ?string {
				[$secret, $apps, $organisation] = $this->test->vault[$credentialId];
				if (in_array($appId, $apps, true) === false || $organisation !== $actingOrganisationId) {
					throw new RuntimeException('denied');
				}

				return $secret;
			}//end resolveInjectable()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($broker);

		$this->issuerKey = new IssuerKeyService(appConfig: $appConfig, urlGenerator: $urls, container: $container, organisations: $organisations);

		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(function (string|int $id): ?ObjectEntity {
			if (isset($this->consumers[$id]) === false) {
				return null;
			}

			$entity = $this->createMock(ObjectEntity::class);
			$entity->method('getObject')->willReturn($this->consumers[$id]);
			$entity->method('getUuid')->willReturn((string) $id);
			return $entity;
		});
		$this->objects->method('saveObject')->willReturnCallback(function (array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
			$this->consumers[(string) $uuid] = $object;
			return $this->createMock(ObjectEntity::class);
		});
		$this->consumers['c-partner'] = ['name' => 'Partner', 'authorizationType' => 'client_credentials', 'scopes' => ['zaken:read', 'zaken:write']];
		$this->consumers['c-apikey'] = ['name' => 'Other', 'authorizationType' => 'apiKey', 'scopes' => ['zaken:read']];

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->issuer = new TokenIssuer(objectService: $this->objects, issuerKey: $this->issuerKey, time: $time);
	}//end setUp()

	/**
	 * The key is created into the broker; only public material reaches the app config, nothing reaches an object.
	 *
	 * @return void
	 */
	public function testTheSigningKeyLivesOnlyInTheBroker(): void {
		$this->assertSame(['keys' => []], $this->issuerKey->jwks());

		$jwk = $this->issuerKey->create(owner: 'admin');

		$this->assertSame(['RSA', 'sig', 'RS256'], [$jwk['kty'], $jwk['use'], $jwk['alg']]);
		$this->assertCount(1, $this->vault);
		$stored = array_values($this->vault)[0];
		$this->assertStringContainsString('PRIVATE KEY', $stored[0]);
		$this->assertSame(['openconnector'], $stored[1]);
		$this->assertSame('org-1', $stored[2]);
		$this->assertSame(['keys' => [$jwk]], $this->issuerKey->jwks());
		foreach ($this->config as $value) {
			$this->assertStringNotContainsString('PRIVATE KEY', $value);
		}

		$this->objects->expects($this->never())->method('saveObject');
	}//end testTheSigningKeyLivesOnlyInTheBroker()

	/**
	 * A partner with the right secret gets a five-minute RS256 JWT for its uuid and scopes, verifiable with the published key.
	 *
	 * @return void
	 */
	public function testAPartnerGetsAVerifiableToken(): void {
		$this->issuerKey->create(owner: 'admin');
		$secret = $this->issuer->setClientSecret(consumerId: 'c-partner');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
		$this->assertNotSame($secret, $this->consumers['c-partner']['clientSecretHash']);
		$this->assertTrue(password_verify($secret, $this->consumers['c-partner']['clientSecretHash']));

		$granted = $this->issuer->issue(grantType: 'client_credentials', clientId: 'c-partner', clientSecret: $secret, scope: 'zaken:read', resource: 'https://cloud.example.nl/apps/integriq/api/endpoints/zaken');

		$this->assertSame(['Bearer', 300, 'zaken:read'], [$granted['token_type'], $granted['expires_in'], $granted['scope']]);
		[$header, $payload, $signature] = explode('.', $granted['access_token']);
		$decode = static fn (string $part): array => json_decode(base64_decode(strtr($part, '-_', '+/')), true);
		$this->assertSame('RS256', $decode($header)['alg']);
		$claims = $decode($payload);
		$this->assertSame('https://cloud.example.nl/apps/integriq', $claims['iss']);
		$this->assertSame('c-partner', $claims['sub']);
		$this->assertSame('https://cloud.example.nl/apps/integriq/api/endpoints/zaken', $claims['aud']);
		$this->assertSame(self::NOW + 300, $claims['exp']);
		$this->assertSame('zaken:read', $claims['scope']);
		$raw = base64_decode(strtr($signature, '-_', '+/') . str_repeat('=', (4 - strlen($signature) % 4) % 4));
		$this->assertSame(1, openssl_verify($header . '.' . $payload, $raw, $this->issuerKey->publicPem(), OPENSSL_ALGO_SHA256));
	}//end testAPartnerGetsAVerifiableToken()

	/**
	 * A wrong secret, an unknown client, another kind of consumer, a foreign scope, a wrong grant or no key is refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$secret = $this->issuer->setClientSecret(consumerId: 'c-partner');
		$cases = [
			['client_credentials', 'c-partner', 'wrong', null, 'invalid_client', 401],
			['client_credentials', 'c-gone', $secret, null, 'invalid_client', 401],
			['client_credentials', 'c-apikey', $secret, null, 'invalid_client', 401],
			['password', 'c-partner', $secret, null, 'unsupported_grant_type', 400],
			['client_credentials', 'c-partner', $secret, 'admin:all', 'invalid_scope', 400],
			['client_credentials', 'c-partner', $secret, null, 'temporarily_unavailable', 503],
		];
		foreach ($cases as [$grant, $client, $given, $scope, $error, $status]) {
			try {
				$this->issuer->issue(grantType: $grant, clientId: $client, clientSecret: $given, scope: $scope);
				$this->fail('refused: ' . $error);
			} catch (OAuthError $e) {
				$this->assertSame([$error, $status], [$e->error, $e->status]);
			}
		}

		$this->assertNull($this->issuer->setClientSecret(consumerId: 'c-apikey'));
	}//end testRefusals()

	/**
	 * The token endpoint takes HTTP Basic, answers no-store, and a bad secret is a 401 with an RFC 6749 body.
	 *
	 * @return void
	 */
	public function testTheTokenEndpoint(): void {
		$this->issuerKey->create(owner: 'admin');
		$secret = $this->issuer->setClientSecret(consumerId: 'c-partner');
		$controller = function (array $params, string $authorization): OAuthTokenController {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
			$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Authorization') ? $authorization : '');
			return new OAuthTokenController(appName: 'integriq', request: $request, issuer: $this->issuer, issuerKey: $this->issuerKey, userSession: $this->createMock(IUserSession::class));
		};

		$ok = $controller(['grant_type' => 'client_credentials'], 'Basic ' . base64_encode('c-partner:' . $secret))->token();
		$this->assertSame(200, $ok->getStatus());
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($ok);
		$this->assertSame('no-store', $headers['Cache-Control']);
		$this->assertSame('zaken:read zaken:write', $ok->getData()['scope']);

		$bad = $controller(['grant_type' => 'client_credentials', 'client_id' => 'c-partner', 'client_secret' => 'nope'], '')->token();
		$this->assertSame(401, $bad->getStatus());
		$this->assertSame('invalid_client', $bad->getData()['error']);
		$this->assertSame(['keys' => $this->issuerKey->jwks()['keys']], $controller([], '')->jwks()->getData());
	}//end testTheTokenEndpoint()

	/**
	 * Integriq accepts its own token: the consumer is its sub, checked against the published key only.
	 *
	 * @return void
	 */
	public function testIntegriqAcceptsItsOwnToken(): void {
		$this->issuerKey->create(owner: 'admin');
		$source = new IntegriqConsumerSource(objectService: $this->objects, ownIssuer: $this->issuerKey);

		$source->presentToken(header: ['alg' => 'RS256'], payload: ['iss' => 'https://cloud.example.nl/apps/integriq', 'sub' => 'c-partner']);
		$resolved = $source->findByIssuer(issuer: 'https://cloud.example.nl/apps/integriq');
		$this->assertSame('c-partner', $resolved->uuid);
		$this->assertSame(['algorithm' => 'RS256', 'publicKey' => $this->issuerKey->publicPem()], $resolved->configuration);

		$source->presentToken(header: ['alg' => 'RS256'], payload: ['iss' => 'https://cloud.example.nl/apps/integriq', 'sub' => 'c-apikey']);
		$this->assertNull($source->findByIssuer(issuer: 'https://cloud.example.nl/apps/integriq'));
	}//end testIntegriqAcceptsItsOwnToken()
}//end class
