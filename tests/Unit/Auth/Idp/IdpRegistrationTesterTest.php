<?php

/**
 * The Test action of a registration: the provider document and each required credential,
 * reported by name and never with a value.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationTester;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
#[CoversClass(IdpRegistrationTester::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(IdpSecretRefusedException::class)]
class IdpRegistrationTesterTest extends TestCase {

	private const METADATA = '<?xml version="1.0"?><md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://idp.example"/>';

	/** @var array<string, string|\Throwable> */
	private array $bodies = [];

	/** @var IdpSecretSeam&MockObject */
	private IdpSecretSeam $seam;

	/** @var list<string> */
	private array $refusedRoles = [];

	/** @var list<string> */
	private array $fetched = [];

	protected function setUp(): void {
		parent::setUp();
		$this->seam = $this->createMock(IdpSecretSeam::class);
		$this->seam->method('read')->willReturnCallback(
			function (string $registration, string $organisation, string $role, string $reference): string {
				if (in_array($role, $this->refusedRoles, true) === true) {
					throw new IdpSecretRefusedException(reason: IdpSecretSeam::REASON_SECRET_MISSING, detail: 'no credential is referenced');
				}

				return 'top-secret-value';
			}
		);
	}

	private function tester(): IdpRegistrationTester {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri): IResponse {
				$this->fetched[] = $uri;
				$body = ($this->bodies[$uri] ?? new RuntimeException('unreachable'));
				if ($body instanceof \Throwable) {
					throw $body;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($body);

				return $response;
			}
		);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		return new IdpRegistrationTester(http: $http, secrets: $this->seam);
	}

	private static function saml(array $data = []): IdpRegistration {
		return new IdpRegistration(
			id: 'reg-1',
			data: $data + [
				'organisation' => 'org-x',
				'provider' => 'digid',
				'kind' => 'saml-sp',
				'idpMetadataUrl' => 'https://idp.example/metadata',
				'pseudonymisation' => 'hmac',
				'credentials' => ['signing' => 'cred-sign', 'salt' => 'cred-salt'],
			]
		);
	}

	private static function oidc(array $data = []): IdpRegistration {
		return new IdpRegistration(
			id: 'reg-2',
			data: $data + [
				'organisation' => 'org-x',
				'provider' => 'eherkenning',
				'kind' => 'oidc-rp',
				'issuer' => 'https://issuer.example/',
				'clientId' => 'c-1',
				'clientAuth' => 'client_secret_basic',
				'credentials' => ['clientSecret' => 'cred-secret', 'salt' => 'cred-salt'],
			]
		);
	}

	public function testASamlRegistrationWithReadableMetadataAndCredentialsPassesEveryCheck(): void {
		$this->bodies['https://idp.example/metadata'] = self::METADATA;

		$checks = $this->tester()->run(self::saml());

		$this->assertSame(
			[
				['check' => 'metadata', 'passed' => true, 'reason' => ''],
				['check' => 'credential:signing', 'passed' => true, 'reason' => ''],
				['check' => 'credential:salt', 'passed' => true, 'reason' => ''],
			],
			$checks
		);
		$this->assertStringNotContainsString('top-secret-value', json_encode($checks));
	}

	public function testAMissingCredentialIsReportedByItsRole(): void {
		$this->bodies['https://idp.example/metadata'] = self::METADATA;
		$this->refusedRoles = ['salt'];

		$checks = $this->tester()->run(self::saml());

		$this->assertSame(
			['check' => 'credential:salt', 'passed' => false, 'reason' => 'The salt credential cannot be read (secret-missing).'],
			$checks[2]
		);
		$this->assertTrue($checks[1]['passed']);
	}

	/**
	 * @return array<string, array{0: string|\Throwable|null}>
	 */
	public static function unreadableMetadata(): array {
		return [
			'not reachable' => [null],
			'not xml' => ['<html>login</html'],
			'another root' => ['<?xml version="1.0"?><EntityDescriptor entityID="x"/>'],
			'a doctype' => ['<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'.substr(self::METADATA, 21)],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('unreadableMetadata')]
	public function testMetadataThatIsNotSamlMetadataFails(?string $body): void {
		if ($body !== null) {
			$this->bodies['https://idp.example/metadata'] = $body;
		}

		$checks = $this->tester()->run(self::saml());

		$this->assertSame(['check' => 'metadata', 'passed' => false, 'reason' => 'The metadata cannot be read as SAML metadata.'], $checks[0]);
	}

	public function testAPlainHttpMetadataAddressIsNotFetched(): void {
		$checks = $this->tester()->run(self::saml(['idpMetadataUrl' => 'http://idp.example/metadata']));

		$this->assertFalse($checks[0]['passed']);
		$this->assertSame([], $this->fetched);
	}

	public function testAnOidcRegistrationChecksDiscoveryAndItsClientSecret(): void {
		$this->bodies['https://issuer.example/.well-known/openid-configuration'] = json_encode(['issuer' => 'https://issuer.example']);

		$checks = $this->tester()->run(self::oidc());

		$this->assertSame(['discovery', 'credential:clientSecret', 'credential:salt'], array_column($checks, 'check'));
		$this->assertSame([true, true, true], array_column($checks, 'passed'));
	}

	public function testADiscoveryDocumentNamingAnotherIssuerFails(): void {
		$this->bodies['https://issuer.example/.well-known/openid-configuration'] = json_encode(['issuer' => 'https://elsewhere.example']);

		$this->assertFalse($this->tester()->run(self::oidc())[0]['passed']);
	}

	public function testARegistrationWithoutAnAddressFailsWithoutAFetch(): void {
		$saml = $this->tester()->run(self::saml(['idpMetadataUrl' => '']));
		$oidc = $this->tester()->run(self::oidc(['issuer' => '']));

		$this->assertSame('No metadata address is set.', $saml[0]['reason']);
		$this->assertSame('No issuer is set.', $oidc[0]['reason']);
		$this->assertSame([], $this->fetched);
	}
}
