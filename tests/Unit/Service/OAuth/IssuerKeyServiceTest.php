<?php

/**
 * IssuerKeyService keeps the signing key in the credential broker and publishes only its public half.
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

use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialMint;
use OCA\OpenRegister\Service\Credential\CredentialMintOptions;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Key creation, the key set, signing and their refusals.
 *
 * @covers \OCA\Integriq\Service\OAuth\IssuerKeyService
 */
final class IssuerKeyServiceTest extends TestCase {

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * What the broker was asked to mint: the arguments, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $minted = [];

	/**
	 * What the broker was asked to resolve: the arguments, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $resolved = [];

	/**
	 * Per broker read, whether it ran inside OpenRegister's system context.
	 *
	 * @var list<bool>
	 */
	public array $inSystemContext = [];

	/**
	 * The secret the broker holds.
	 *
	 * @var string|null
	 */
	public ?string $secret = null;

	/**
	 * Whether the broker refuses to resolve.
	 *
	 * @var bool
	 */
	public bool $refuse = false;

	/**
	 * The default organisation.
	 *
	 * @var OrganisationBridgeService&MockObject
	 */
	private OrganisationBridgeService&MockObject $organisations;

	/**
	 * The DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	private IssuerKeyService $service;

	/**
	 * An in-memory app config, a recording broker and org-1 as the default organisation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$app . '/' . $key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$app . '/' . $key] = $value;
			return true;
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.nl' . $path . '/');
		$this->organisations = $this->createMock(OrganisationBridgeService::class);
		$this->organisations->method('getDefaultOrganisationUuid')->willReturn('org-1');

		$test   = $this;
		$broker = new class($test) {
			/**
			 * Constructor.
			 *
			 * @param IssuerKeyServiceTest $test The test recording the calls.
			 */
			public function __construct(private readonly IssuerKeyServiceTest $test) {
			}//end __construct()

			/**
			 * The broker's mintCredential() shape on OpenRegister main.
			 *
			 * @return ObjectEntity
			 */
			public function mintCredential(CredentialMint $mint, CredentialMintOptions $options): ObjectEntity {
				$this->test->minted[] = [$mint->name, $mint->provider, $mint->owner, $options->allowedApps, $options->scope, $options->organisation];
				$this->test->secret   = $options->secret();
				$entity = (new \ReflectionClass(ObjectEntity::class))->newInstanceWithoutConstructor();
				$entity->setUuid('cred-1');
				return $entity;
			}//end mintCredential()

			/**
			 * The broker's resolveInjectable() shape.
			 *
			 * @return string|null
			 */
			public function resolveInjectable(string $credentialId, string $appId, ?string $actingUserId = null, ?string $actingOrganisationId = null): ?string {
				$this->test->resolved[] = [$credentialId, $appId, $actingUserId, $actingOrganisationId];
				$this->test->inSystemContext[] = \OCA\OpenRegister\Service\SystemOperationContext::isActive();
				if ($this->test->refuse === true) {
					throw new RuntimeException('denied');
				}

				return $this->test->secret;
			}//end resolveInjectable()
		};
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->with(InlineSecretMigrationExecutor::BROKER_CLASS)->willReturn($broker);

		$this->service = new IssuerKeyService(appConfig: $appConfig, urlGenerator: $urls, container: $this->container, organisations: $this->organisations);
	}//end setUp()

	/**
	 * The issuer is the app's absolute URL without a trailing slash.
	 *
	 * @return void
	 */
	public function testTheIssuerUrlHasNoTrailingSlash(): void {
		$this->assertSame('https://cloud.example.nl/apps/integriq', $this->service->issuerUrl());
	}//end testTheIssuerUrlHasNoTrailingSlash()

	/**
	 * Without a key the key set is empty, there is no key and signing is refused without asking the broker.
	 *
	 * @return void
	 */
	public function testWithoutAKeyNothingIsPublishedAndSigningIsRefused(): void {
		$this->assertSame(['keys' => []], $this->service->jwks());
		$this->assertFalse($this->service->hasKey());
		$this->assertSame('', $this->service->publicPem());

		try {
			$this->service->sign(claims: ['sub' => 'c-partner']);
			$this->fail('signing without a key must be refused');
		} catch (RuntimeException $e) {
			$this->assertSame('No issuer key has been created.', $e->getMessage());
		}

		$this->assertSame([], $this->resolved);
	}//end testWithoutAKeyNothingIsPublishedAndSigningIsRefused()

	/**
	 * Creating mints the private key into the broker for the organisation and stores only public material.
	 *
	 * @return void
	 */
	public function testCreateMintsIntoTheBrokerAndStoresOnlyThePublicHalf(): void {
		$jwk = $this->service->create(owner: 'admin');

		$this->assertSame(['RSA', 'sig', 'RS256'], [$jwk['kty'], $jwk['use'], $jwk['alg']]);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $jwk['kid']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['n']);
		$this->assertSame('AQAB', $jwk['e']);
		$this->assertCount(1, $this->minted);
		$this->assertSame(
			['Integriq token issuer key ' . $jwk['kid'], 'generic-apikey', 'admin', ['openconnector'], 'organisation', 'org-1'],
			$this->minted[0]
		);
		$this->assertStringContainsString('PRIVATE KEY', (string) $this->secret);

		$this->assertSame('cred-1', $this->config['integriq/' . IssuerKeyService::CONFIG_CREDENTIAL_REF]);
		$this->assertSame('org-1', $this->config['integriq/' . IssuerKeyService::CONFIG_ORGANISATION]);
		$this->assertSame($jwk, json_decode($this->config['integriq/' . IssuerKeyService::CONFIG_PUBLIC_JWK], true));
		$this->assertStringContainsString('PUBLIC KEY', $this->service->publicPem());
		foreach ($this->config as $value) {
			$this->assertStringNotContainsString('PRIVATE KEY', $value);
		}

		$this->assertTrue($this->service->hasKey());
		$this->assertSame(['keys' => [$jwk]], $this->service->jwks());
	}//end testCreateMintsIntoTheBrokerAndStoresOnlyThePublicHalf()

	/**
	 * Without a default organisation no key is generated or minted.
	 *
	 * @return void
	 */
	public function testCreateWithoutADefaultOrganisationIsRefused(): void {
		$organisations = $this->createMock(OrganisationBridgeService::class);
		$organisations->method('getDefaultOrganisationUuid')->willReturn(null);
		$service = new IssuerKeyService(
			appConfig: $this->createMock(IAppConfig::class),
			urlGenerator: $this->createMock(IURLGenerator::class),
			container: $this->container,
			organisations: $organisations
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No default organisation to hold the issuer key.');
		try {
			$service->create(owner: 'admin');
		} finally {
			$this->assertSame([], $this->minted);
		}
	}//end testCreateWithoutADefaultOrganisationIsRefused()

	/**
	 * Without the broker no key is created and nothing is stored.
	 *
	 * @return void
	 */
	public function testCreateWithoutTheBrokerIsRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueString');
		$service = new IssuerKeyService(appConfig: $appConfig, urlGenerator: $this->createMock(IURLGenerator::class), container: $container, organisations: $this->organisations);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister\'s credential broker is not available.');
		$service->create(owner: 'admin');
	}//end testCreateWithoutTheBrokerIsRefused()

	/**
	 * A signed token carries the key's kid, resolves the key for openconnector in the organisation and verifies with the public key.
	 *
	 * @return void
	 */
	public function testSignProducesATokenVerifiableWithThePublishedKey(): void {
		$jwk = $this->service->create(owner: 'admin');

		$token = $this->service->sign(claims: ['sub' => 'c-partner', 'exp' => 1794000300]);

		[$header, $payload, $signature] = explode('.', $token);
		$decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4));
		$this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $jwk['kid']], json_decode($decode($header), true));
		$this->assertSame(['sub' => 'c-partner', 'exp' => 1794000300], json_decode($decode($payload), true));
		$this->assertSame(1, openssl_verify($header . '.' . $payload, $decode($signature), $this->service->publicPem(), OPENSSL_ALGO_SHA256));
		$this->assertSame([['cred-1', 'openconnector', null, 'org-1']], $this->resolved);
	}//end testSignProducesATokenVerifiableWithThePublishedKey()

	/**
	 * The token endpoint is anonymous: no session holds the organisation the key belongs to, so
	 * OpenRegister's lookup would not find it. The key is read in the system context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function testSignReadsTheKeyInTheSystemContext(): void {
		$this->service->create(owner: 'admin');

		$this->service->sign(claims: ['sub' => 'c-partner']);

		$this->assertSame([true], $this->inSystemContext);
	}//end testSignReadsTheKeyInTheSystemContext()

	/**
	 * A broker that refuses to hand out the key makes signing fail without leaking its reason.
	 *
	 * @return void
	 */
	public function testSignWhenTheBrokerRefusesFails(): void {
		$this->service->create(owner: 'admin');
		$this->refuse = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The issuer key could not be read from the credential broker.');
		$this->service->sign(claims: ['sub' => 'c-partner']);
	}//end testSignWhenTheBrokerRefusesFails()

	/**
	 * A key the broker returns that is not a private key cannot sign.
	 *
	 * @return void
	 */
	public function testSignWithAnUnusableKeyFails(): void {
		$this->service->create(owner: 'admin');
		$this->secret = 'not a key';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The token could not be signed.');
		@$this->service->sign(claims: ['sub' => 'c-partner']);
	}//end testSignWithAnUnusableKeyFails()

	/**
	 * A stored public key without a kid counts as no key.
	 *
	 * @return void
	 */
	public function testAPublicKeyWithoutAKidCountsAsNoKey(): void {
		$this->config['integriq/' . IssuerKeyService::CONFIG_CREDENTIAL_REF] = 'cred-1';
		$this->config['integriq/' . IssuerKeyService::CONFIG_PUBLIC_JWK]     = (string) json_encode(['kty' => 'RSA']);

		$this->assertFalse($this->service->hasKey());
		$this->assertSame(['keys' => []], $this->service->jwks());
	}//end testAPublicKeyWithoutAKidCountsAsNoKey()
}//end class
