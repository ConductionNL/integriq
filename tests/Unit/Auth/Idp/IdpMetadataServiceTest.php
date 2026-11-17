<?php

/**
 * The published metadata of a registration: SAML SP XML, the OIDC client
 * document and the JWKS, carrying public certificate material only.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use DOMDocument;
use DOMXPath;
use OCA\Integriq\Auth\Idp\IdpMetadataService;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpRegistrationService;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Exception\IdpMetadataUnavailableException;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * IdpMetadataService over a mocked registration store and secret seam.
 */
#[CoversClass(IdpMetadataService::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(IdpMetadataUnavailableException::class)]
#[UsesClass(IdpSecretRefusedException::class)]
class IdpMetadataServiceTest extends TestCase {

	/**
	 * A key pair and self-signed certificate made for the test run, never committed.
	 *
	 * @var array{key: string, certificate: string}|null
	 */
	private static ?array $pair = null;

	private IdpRegistrationService&MockObject $registrations;

	private IdpSecretSeam&MockObject $seam;

	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registrations = $this->createMock(IdpRegistrationService::class);
		$this->seam = $this->createMock(IdpSecretSeam::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The test key pair, generated once.
	 *
	 * @return array{key: string, certificate: string}
	 */
	private static function pair(): array {
		if (self::$pair === null) {
			$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
			$csr = openssl_csr_new(['commonName' => 'integriq test SP'], $key);
			$certificate = openssl_csr_sign($csr, null, $key, 1);
			openssl_x509_export($certificate, $certificatePem);
			openssl_pkey_export($key, $keyPem);
			self::$pair = ['key' => $keyPem, 'certificate' => $certificatePem];
		}

		return self::$pair;

	}//end pair()

	/**
	 * The certificate's base64 DER body.
	 *
	 * @return string
	 */
	private static function body(): string {
		return (string)preg_replace('/-----[A-Z ]+-----|\s+/', '', self::pair()['certificate']);

	}//end body()

	/**
	 * The service, with absolute route URLs built as `https://x/<route>?<params>`.
	 *
	 * @return IdpMetadataService
	 */
	private function service(): IdpMetadataService {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params = []): string => 'https://x/'.$route.'?'.http_build_query($params)
		);

		return new IdpMetadataService(registrations: $this->registrations, secretSeam: $this->seam, urlGenerator: $urls, logger: $this->logger);

	}//end service()

	/**
	 * A registration.
	 *
	 * @param array<string, mixed> $data Overrides.
	 *
	 * @return IdpRegistration
	 */
	private function registration(array $data = []): IdpRegistration {
		return new IdpRegistration(
			id: 'reg-1',
			data: array_merge(
				[
					'organisation' => 'org-x',
					'provider' => 'digid',
					'kind' => IdpRegistration::KIND_SAML,
					'enabled' => true,
					'label' => 'Gemeente X DigiD',
					'credentials' => ['signing' => 'cred-signing'],
					'subjectMapping' => ['bsn' => 'urn:nl-eid-gdi:1.0:id:BSN', 'pseudonym' => 'urn:nl-eid-gdi:1.0:id:Pseudonym'],
				],
				$data
			)
		);

	}//end registration()

	/**
	 * The XPath over a metadata document.
	 *
	 * @param string $xml The document.
	 *
	 * @return DOMXPath
	 */
	private static function xpath(string $xml): DOMXPath {
		$document = new DOMDocument();
		self::assertTrue($document->loadXML($xml));
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('md', IdpMetadataService::NS_MD);
		$xpath->registerNamespace('ds', IdpMetadataService::NS_DS);

		return $xpath;

	}//end xpath()

	/**
	 * The SAML metadata names the default EntityID, the certificate, the callback for both bindings
	 * and the mapped attributes, and carries no private key.
	 *
	 * @return void
	 */
	public function testTheSamlMetadataNamesTheEntityTheCertificateAndTheCallback(): void {
		$this->seam->expects($this->once())->method('read')->with('reg-1', 'org-x', 'signing', 'cred-signing')
			->willReturn(self::pair()['key'].self::pair()['certificate']);

		$xml = $this->service()->samlMetadata(registration: $this->registration());
		$xpath = self::xpath(xml: $xml);

		$this->assertSame(
			'https://x/integriq.idpMetadata.metadata?organisation=org-x&provider=digid',
			$xpath->evaluate('string(/md:EntityDescriptor/@entityID)')
		);
		$this->assertSame(self::body(), $xpath->evaluate('string(//md:KeyDescriptor[@use="signing"]//ds:X509Certificate)'));
		$this->assertSame(0.0, $xpath->evaluate('count(//md:KeyDescriptor[@use="encryption"])'));
		$services = $xpath->query('//md:AssertionConsumerService');
		$this->assertSame(2, $services->length);
		$this->assertSame(IdpMetadataService::BINDINGS, [$services->item(0)->getAttribute('Binding'), $services->item(1)->getAttribute('Binding')]);
		$this->assertSame('https://x/integriq.idpBroker.callback?provider=digid', $services->item(1)->getAttribute('Location'));
		$this->assertSame(
			['urn:nl-eid-gdi:1.0:id:BSN', 'urn:nl-eid-gdi:1.0:id:Pseudonym'],
			array_map(static fn ($node): string => $node->getAttribute('Name'), iterator_to_array($xpath->query('//md:RequestedAttribute')))
		);
		$this->assertStringNotContainsString('PRIVATE KEY', $xml);
		$this->assertStringNotContainsString(substr((string)preg_replace('/-----[A-Z ]+-----|\s+/', '', self::pair()['key']), 40, 40), $xml);

	}//end testTheSamlMetadataNamesTheEntityTheCertificateAndTheCallback()

	/**
	 * An assigned EntityID wins over the metadata address, and a decryption credential adds an encryption key.
	 *
	 * @return void
	 */
	public function testAnAssignedEntityIdAndAnEncryptionKeyArePublished(): void {
		$this->seam->method('read')->willReturn(self::pair()['certificate']);

		$xml = $this->service()->samlMetadata(
			registration: $this->registration(['spEntityId' => 'urn:gemeente-x:sp', 'credentials' => ['signing' => 'a', 'decryption' => 'b']])
		);
		$xpath = self::xpath(xml: $xml);

		$this->assertSame('urn:gemeente-x:sp', $xpath->evaluate('string(/md:EntityDescriptor/@entityID)'));
		$this->assertSame(self::body(), $xpath->evaluate('string(//md:KeyDescriptor[@use="encryption"]//ds:X509Certificate)'));

	}//end testAnAssignedEntityIdAndAnEncryptionKeyArePublished()

	/**
	 * A refused or certificate-less credential is unavailable, and the log names the reference, never its bytes.
	 *
	 * @return void
	 */
	public function testAnUnreadableCertificateIsUnavailableAndLogsNoBytes(): void {
		$this->seam->method('read')->willReturn(self::pair()['key']);
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = $message.json_encode($context);
			}
		);

		try {
			$this->service()->samlMetadata(registration: $this->registration());
			$this->fail('not refused');
		} catch (IdpMetadataUnavailableException $exception) {
			$this->assertStringContainsString('the signing certificate cannot be read, the credential holds no certificate', $exception->getMessage());
		}

		$this->assertCount(1, $logged);
		$this->assertStringContainsString('cred-signing', $logged[0]);
		$this->assertStringNotContainsString('PRIVATE', $logged[0]);

	}//end testAnUnreadableCertificateIsUnavailableAndLogsNoBytes()

	/**
	 * A secret the seam refuses is unavailable too.
	 *
	 * @return void
	 */
	public function testARefusedCredentialIsUnavailable(): void {
		$this->seam->method('read')->willThrowException(new IdpSecretRefusedException(reason: IdpSecretSeam::REASON_KEEPIQ_MISSING, detail: 'refused'));

		$this->expectException(IdpMetadataUnavailableException::class);
		$this->expectExceptionMessage('the credential is refused ('.IdpSecretSeam::REASON_KEEPIQ_MISSING.')');
		$this->service()->samlMetadata(registration: $this->registration());

	}//end testARefusedCredentialIsUnavailable()

	/**
	 * The OIDC client document carries the client id, the redirect URI and the JWKS URI, and no secret.
	 *
	 * @return void
	 */
	public function testTheOidcClientDocumentCarriesNoSecret(): void {
		$this->seam->expects($this->never())->method('read');
		$service = $this->service();

		$jwt = $service->oidcClient(registration: $this->registration(['kind' => IdpRegistration::KIND_OIDC, 'clientId' => 'client-x', 'clientAuth' => 'private_key_jwt']));
		$basic = $service->oidcClient(registration: $this->registration(['kind' => IdpRegistration::KIND_OIDC, 'clientId' => 'client-x', 'clientAuth' => 'client_secret_basic']));

		$this->assertSame('client-x', $jwt['client_id']);
		$this->assertSame(['https://x/integriq.idpBroker.callback?provider=digid'], $jwt['redirect_uris']);
		$this->assertSame('https://x/integriq.idpMetadata.jwks?organisation=org-x&provider=digid', $jwt['jwks_uri']);
		$this->assertSame('private_key_jwt', $jwt['token_endpoint_auth_method']);
		$this->assertArrayNotHasKey('jwks_uri', $basic);
		$this->assertArrayNotHasKey('client_secret', $basic);

	}//end testTheOidcClientDocumentCarriesNoSecret()

	/**
	 * The JWKS holds the signing certificate's public key and no private parameter.
	 *
	 * @return void
	 */
	public function testTheJwksHoldsThePublicKeyOnly(): void {
		$this->seam->method('read')->willReturn(self::pair()['key'].self::pair()['certificate']);

		$jwks = $this->service()->jwks(registration: $this->registration(['kind' => IdpRegistration::KIND_OIDC, 'clientAuth' => 'private_key_jwt']));

		$this->assertCount(1, $jwks['keys']);
		$key = $jwks['keys'][0];
		$this->assertSame('RSA', $key['kty']);
		$this->assertSame('sig', $key['use']);
		$this->assertSame(openssl_pkey_get_details(openssl_pkey_get_public(self::pair()['certificate']))['rsa']['n'], base64_decode(strtr($key['n'], '-_', '+/')));
		foreach (['d', 'p', 'q', 'dp', 'dq', 'qi'] as $private) {
			$this->assertArrayNotHasKey($private, $key);
		}

	}//end testTheJwksHoldsThePublicKeyOnly()

	/**
	 * An unknown provider, a missing registration and a disabled one are all unpublished.
	 *
	 * @return void
	 */
	public function testOnlyAnEnabledRegistrationOfAKnownProviderIsPublished(): void {
		$enabled = $this->registration();
		$this->registrations->method('find')->willReturnCallback(
			fn (string $organisation, string $provider): ?IdpRegistration => match ($organisation) {
				'org-x' => $enabled,
				'org-off' => $this->registration(['enabled' => false]),
				default => null,
			}
		);
		$service = $this->service();

		$this->assertSame($enabled, $service->published(organisation: 'org-x', provider: 'digid'));
		$this->assertNull($service->published(organisation: 'org-off', provider: 'digid'));
		$this->assertNull($service->published(organisation: 'org-none', provider: 'digid'));
		$this->assertNull($service->published(organisation: 'org-x', provider: 'facebook'));

	}//end testOnlyAnEnabledRegistrationOfAKnownProviderIsPublished()
}//end class
