<?php

/**
 * The SAML Service Provider adapter against a stub identity provider: a signed
 * AuthnRequest over the Redirect binding on the way out; a verified, decrypted
 * assertion read through the subject mapping on the way back; and the
 * Responses it must refuse before reading any attribute.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp\Adapter;

use DOMDocument;
use OCA\Integriq\Auth\Idp\Adapter\SamlCurrentAddress;
use OCA\Integriq\Auth\Idp\Adapter\SamlServiceProviderAdapter;
use OCA\Integriq\Auth\Idp\Adapter\SamlServiceProviderAdapterFactory;
use OCA\Integriq\Auth\Idp\Adapter\SamlVerifiedAssertion;
use OCA\Integriq\Auth\Idp\AssertionGuard;
use OCA\Integriq\Auth\Idp\AtomicCache;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\IdpMetadataService;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCA\Integriq\Tests\Fixtures\Idp\SamlStubIdentityProvider;
use OCA\Integriq\Tests\Unit\Auth\Idp\FakeMemcache;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
#[CoversClass(SamlServiceProviderAdapter::class)]
#[CoversClass(SamlServiceProviderAdapterFactory::class)]
#[CoversClass(SamlCurrentAddress::class)]
#[CoversClass(SamlVerifiedAssertion::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(TrustLevelMapper::class)]
#[UsesClass(AssertionGuard::class)]
#[UsesClass(EnvelopeReplayGuard::class)]
#[UsesClass(AtomicCache::class)]
class SamlServiceProviderAdapterTest extends TestCase {

	private const ENTITY_ID = 'https://nc.example/index.php/apps/integriq/api/idp/metadata/org-x/digid';

	private const ACS = 'https://nc.example/index.php/apps/integriq/api/idp/digid/callback';

	private const METADATA_URL = 'https://broker.example/metadata.xml';

	/** The registration's subject mapping: a BSN attribute under a broker-specific name. */
	private const BSN_ATTRIBUTE = 'urn:broker:attribute:bsn';

	private static ?SamlStubIdentityProvider $sharedIdp = null;

	private SamlStubIdentityProvider $idp;

	/** @var IdpSecretSeam&MockObject */
	private IdpSecretSeam $seam;

	/** @var list<string> The addresses the adapter fetched. */
	private array $fetched = [];

	private string $metadata;

	protected function setUp(): void {
		parent::setUp();
		// Key generation is the slow part; one stub per test class is enough.
		self::$sharedIdp ??= new SamlStubIdentityProvider(spEntityId: self::ENTITY_ID, acsUrl: self::ACS);
		$this->idp = self::$sharedIdp;
		$this->metadata = $this->idp->metadata();
		$this->seam = $this->createMock(IdpSecretSeam::class);
		$this->seam->method('read')->willReturnCallback(
			fn (string $registration, string $organisation, string $role, string $reference): string => match ($role) {
				'signing' => $this->idp->spSigningCredential(),
				'decryption' => $this->idp->spDecryptionCredential(),
				default => throw new IdpSecretRefusedException(reason: 'secret-missing', detail: 'missing'),
			}
		);
	}

	private function registration(array $data = []): IdpRegistration {
		return new IdpRegistration(
			id: 'reg-1',
			data: array_merge(
				[
					'organisation' => 'org-x',
					'provider' => 'digid',
					'kind' => 'saml-sp',
					'enabled' => true,
					'idpMetadataUrl' => self::METADATA_URL,
					'subjectMapping' => ['bsn' => self::BSN_ATTRIBUTE],
					'assuranceMapping' => [
						'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport' => 'basis',
						'urn:oasis:names:tc:SAML:2.0:ac:classes:Smartcard' => 'substantieel',
					],
					'credentials' => ['signing' => 'cred-signing', 'decryption' => 'cred-decryption', 'salt' => 'cred-salt'],
				],
				$data
			)
		);
	}

	private function http(): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri): IResponse {
				$this->fetched[] = $uri;
				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($this->metadata);

				return $response;
			}
		);
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		return $http;
	}

	private function adapter(?IdpRegistration $registration = null): SamlServiceProviderAdapter {
		return new SamlServiceProviderAdapter(
			registration: ($registration ?? $this->registration()),
			secrets: $this->seam,
			http: $this->http(),
			trust: new TrustLevelMapper(),
			entityId: self::ENTITY_ID,
			acsUrl: self::ACS
		);
	}

	/**
	 * The query parameters of the redirect, still url-encoded, in order.
	 *
	 * @return array<string, string>
	 */
	private static function rawQuery(string $url): array {
		$query = [];
		foreach (explode('&', (string)parse_url($url, PHP_URL_QUERY)) as $pair) {
			[$name, $value] = explode('=', $pair, 2);
			$query[$name] = $value;
		}

		return $query;
	}

	private static function authnRequest(string $url): DOMDocument {
		$document = new DOMDocument();
		$document->loadXML((string)gzinflate(base64_decode(urldecode(self::rawQuery($url)['SAMLRequest']))));

		return $document;
	}

	public function testTheAuthnRequestIsSignedAndAnsweredWithItsId(): void {
		$begun = $this->adapter()->beginAuthentication(['relayState' => 'state-1']);

		$this->assertStringStartsWith(SamlStubIdentityProvider::SSO_URL.'?', $begun['redirectUrl']);
		$query = self::rawQuery($begun['redirectUrl']);
		$this->assertSame('state-1', urldecode($query['RelayState']));
		$this->assertSame('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', urldecode($query['SigAlg']));

		$signed = 'SAMLRequest='.$query['SAMLRequest'].'&RelayState='.$query['RelayState'].'&SigAlg='.$query['SigAlg'];
		$this->assertSame(
			1,
			openssl_verify($signed, base64_decode(urldecode($query['Signature'])), $this->idp->spSigningCertificate(), OPENSSL_ALGO_SHA256),
			'The redirect signature verifies with the SP signing certificate.'
		);

		$request = self::authnRequest($begun['redirectUrl'])->documentElement;
		$this->assertSame($begun['requestId'], $request->getAttribute('ID'));
		$this->assertSame(self::ACS, $request->getAttribute('AssertionConsumerServiceURL'));
		$this->assertSame(SamlStubIdentityProvider::SSO_URL, $request->getAttribute('Destination'));
		$this->assertSame(self::ENTITY_ID, $request->getElementsByTagNameNS(SamlStubIdentityProvider::NS_SAML, 'Issuer')->item(0)?->textContent);
		$this->assertSame([self::METADATA_URL], $this->fetched);
	}

	public function testTheRequestAsksOnlyForTheSpellingsAtOrAboveTheFloor(): void {
		$begun = $this->adapter()->beginAuthentication(['relayState' => 'state-1']);

		$classRefs = [];
		foreach (self::authnRequest($begun['redirectUrl'])->getElementsByTagNameNS(SamlStubIdentityProvider::NS_SAML, 'AuthnContextClassRef') as $classRef) {
			$classRefs[] = $classRef->textContent;
		}

		$this->assertSame(['urn:oasis:names:tc:SAML:2.0:ac:classes:Smartcard'], $classRefs);
	}

	public function testALoginWithoutRelayStateDoesNotStart(): void {
		$this->expectException(IdpAssertionException::class);
		$this->adapter()->beginAuthentication([]);
	}

	public function testAnEncryptedSignedAssertionDecryptsAndPassesTheGuard(): void {
		$adapter = $this->adapter();
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);
		$response = $this->idp->response(
			inResponseTo: $begun['requestId'],
			attributes: [self::BSN_ATTRIBUTE => '123456782'],
			options: ['encrypt' => true]
		);

		$assertion = $adapter->readAssertion(['SAMLResponse' => $response, 'RelayState' => 'state-1']);

		$this->assertSame($begun['requestId'], $assertion['inResponseTo']);
		$this->assertSame(self::ENTITY_ID, $assertion['audience']);
		$this->assertSame('123456782', $assertion['subject']);
		$this->assertSame('bsn', $assertion['subType']);
		$this->assertSame('urn:oasis:names:tc:SAML:2.0:ac:classes:Smartcard', $assertion['assuranceLevel']);
		$this->assertSame('org-x', $assertion['organisation']);
		$this->assertNotSame('', $assertion['id']);
		$this->assertNotSame($begun['requestId'], $assertion['id']);

		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('isAvailable')->willReturn(true);
		$caches->method('createDistributed')->willReturn(new FakeMemcache());
		$guard = new AssertionGuard(replayGuard: new EnvelopeReplayGuard(cacheFactory: $caches));
		$guard->assertAcceptable(assertion: $assertion, outstandingRequestId: $begun['requestId'], expectedAudience: self::ENTITY_ID);
		$this->addToAssertionCount(1);
	}

	public function testASignedPlainAssertionIsReadWhenNoDecryptionKeyIsNamed(): void {
		$registration = $this->registration(['credentials' => ['signing' => 'cred-signing', 'salt' => 'cred-salt']]);
		$adapter = $this->adapter($registration);
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$assertion = $adapter->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: $begun['requestId'], attributes: [self::BSN_ATTRIBUTE => '123456782'])]
		);

		$this->assertSame('123456782', $assertion['subject']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function forgedResponses(): array {
		return [
			'a value changed after signing' => [['encrypt' => true, 'tamper' => '999999990']],
			'a wrapped assertion' => [['wrap' => true]],
			'an entity declaration' => [['doctype' => true]],
			'an unsigned assertion' => [['sign' => false]],
			'an assertion for another SP' => [['audience' => 'https://other.example/sp']],
			'a Response sent to another address' => [['destination' => 'https://evil.example/acs']],
		];
	}

	/**
	 * @param array<string, mixed> $options
	 */
	#[DataProvider('forgedResponses')]
	public function testAForgedResponseIsRefusedBeforeAnyAttributeIsRead(array $options): void {
		$registration = $this->registration();
		if (($options['encrypt'] ?? false) === false) {
			$registration = $this->registration(['credentials' => ['signing' => 'cred-signing', 'salt' => 'cred-salt']]);
		}

		$adapter = $this->adapter($registration);
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);
		$response = $this->idp->response(inResponseTo: $begun['requestId'], attributes: [self::BSN_ATTRIBUTE => '123456782'], options: $options);

		try {
			$adapter->readAssertion(['SAMLResponse' => $response]);
			$this->fail('The forged Response was accepted.');
		} catch (IdpAssertionException $refused) {
			$this->assertStringNotContainsString('123456782', $refused->getMessage());
			$this->assertStringNotContainsString('999999990', $refused->getMessage());
		}
	}

	public function testAPlainAssertionIsRefusedWhenEncryptionIsExpected(): void {
		$adapter = $this->adapter();
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$this->expectException(IdpAssertionException::class);
		$adapter->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: $begun['requestId'], attributes: [self::BSN_ATTRIBUTE => '123456782'])]
		);
	}

	public function testAResponseWithoutInResponseToIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('InResponseTo');
		$this->adapter()->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: '', attributes: [self::BSN_ATTRIBUTE => '123456782'], options: ['encrypt' => true])]
		);
	}

	public function testACallbackWithoutAResponseIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('SAMLResponse');
		$this->adapter()->readAssertion(['RelayState' => 'state-1']);
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: string, 3: string}>
	 */
	public static function mappedSubjects(): array {
		return [
			'bsn' => [['bsn' => 'urn:a:bsn'], ['urn:a:bsn' => '123456782'], '123456782', 'bsn'],
			'pseudonym' => [['pseudonym' => 'urn:a:pp'], ['urn:a:pp' => 'pp-1'], 'pp-1', 'bsn-pseudonym'],
			'kvk' => [['kvk' => 'urn:a:kvk'], ['urn:a:kvk' => '12345678'], '12345678', 'kvk'],
			'rsin' => [['rsin' => 'urn:a:rsin'], ['urn:a:rsin' => '001234567'], '001234567', 'rsin'],
			'eidas' => [['eidasPersonIdentifier' => 'urn:a:eidas'], ['urn:a:eidas' => 'DE/NL/abc'], 'DE/NL/abc', 'eidas-person-identifier'],
			'only the mapped name is read' => [['bsn' => 'urn:a:bsn'], ['bsn' => '111222333', 'urn:a:bsn' => '123456782'], '123456782', 'bsn'],
			'an unmapped attribute is no subject' => [['kvk' => 'urn:a:kvk'], ['bsn' => '123456782', 'kvk' => '12345678'], '', ''],
		];
	}

	/**
	 * @param array<string, string> $mapping
	 * @param array<string, string> $attributes
	 */
	#[DataProvider('mappedSubjects')]
	public function testTheSubjectIsReadFromTheMappedAttributeOnly(array $mapping, array $attributes, string $subject, string $subType): void {
		$adapter = $this->adapter($this->registration(['subjectMapping' => $mapping]));
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$assertion = $adapter->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: $begun['requestId'], attributes: $attributes, options: ['encrypt' => true])]
		);

		$this->assertSame($subject, $assertion['subject']);
		$this->assertSame($subType, $assertion['subType']);
	}

	public function testTheBranchIsReadFromItsMappedAttribute(): void {
		$registration = $this->registration(
			['provider' => 'eherkenning', 'subjectMapping' => ['kvk' => 'urn:a:kvk', 'branch' => 'urn:a:vestiging']]
		);
		$adapter = $this->adapter($registration);
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$assertion = $adapter->readAssertion(
			[
				'SAMLResponse' => $this->idp->response(
					inResponseTo: $begun['requestId'],
					attributes: ['urn:a:kvk' => '12345678', 'urn:a:vestiging' => '000012345678'],
					options: ['encrypt' => true, 'classRef' => 'urn:etoegang:core:assurance-class:loa3']
				),
			]
		);

		$this->assertSame('000012345678', $assertion['branch']);
		$this->assertSame('urn:etoegang:core:assurance-class:loa3', $assertion['assuranceLevel']);
	}

	public function testMetadataWithoutAnHttpsRedirectSignOnServiceIsRefused(): void {
		$this->metadata = $this->idp->metadata(ssoUrl: 'http://idp.example/sso');

		$this->expectException(IdpAssertionException::class);
		$this->adapter()->beginAuthentication(['relayState' => 'state-1']);
	}

	public function testMetadataWithAnEntityDeclarationIsRefused(): void {
		$this->metadata = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]>'.substr($this->idp->metadata(), strlen('<?xml version="1.0"?>'));

		$this->expectException(IdpAssertionException::class);
		$this->adapter()->beginAuthentication(['relayState' => 'state-1']);
	}

	public function testASigningCredentialWithoutAPrivateKeyIsRefused(): void {
		$seam = $this->createMock(IdpSecretSeam::class);
		$seam->method('read')->willReturn($this->idp->spSigningCertificate());
		$adapter = new SamlServiceProviderAdapter(
			registration: $this->registration(),
			secrets: $seam,
			http: $this->http(),
			trust: new TrustLevelMapper(),
			entityId: self::ENTITY_ID,
			acsUrl: self::ACS
		);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('signing credential holds no certificate and private key');
		$adapter->beginAuthentication(['relayState' => 'state-1']);
	}

	public function testARefusedCredentialRefusesTheLogin(): void {
		$seam = $this->createMock(IdpSecretSeam::class);
		$seam->method('read')->willThrowException(new IdpSecretRefusedException(reason: 'keepiq-missing', detail: 'no keepiq'));
		$adapter = new SamlServiceProviderAdapter(
			registration: $this->registration(),
			secrets: $seam,
			http: $this->http(),
			trust: new TrustLevelMapper(),
			entityId: self::ENTITY_ID,
			acsUrl: self::ACS
		);

		$this->expectException(IdpAssertionException::class);
		$adapter->beginAuthentication(['relayState' => 'state-1']);
	}

	public function testTheCurrentAddressIsPutBackAfterAResponseIsRead(): void {
		$_SERVER['REQUEST_URI'] = '/index.php/apps/other';
		$_SERVER['QUERY_STRING'] = 'a=b';
		$adapter = $this->adapter();
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);
		$adapter->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: $begun['requestId'], attributes: [self::BSN_ATTRIBUTE => '123456782'], options: ['encrypt' => true])]
		);

		$this->assertSame('/index.php/apps/other', $_SERVER['REQUEST_URI']);
		$this->assertSame('a=b', $_SERVER['QUERY_STRING']);
		unset($_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']);
	}

	public function testTheFactoryBuildsTheAdapterOfARegistrationWithItsCallback(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $params): string => match ($route) {
				'integriq.idpBroker.callback' => self::ACS,
				default => self::ENTITY_ID,
			}
		);
		$metadata = $this->createMock(IdpMetadataService::class);
		$metadata->method('entityId')->willReturn(self::ENTITY_ID);
		$factory = new SamlServiceProviderAdapterFactory(
			secrets: $this->seam,
			http: $this->http(),
			trust: new TrustLevelMapper(),
			urlGenerator: $urls,
			metadata: $metadata
		);

		$adapter = $factory->build($this->registration());
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$this->assertSame('digid', $adapter->getProviderId());
		$this->assertTrue($adapter->isConfigured());
		$this->assertSame(self::ACS, self::authnRequest($begun['redirectUrl'])->documentElement->getAttribute('AssertionConsumerServiceURL'));
	}
}
