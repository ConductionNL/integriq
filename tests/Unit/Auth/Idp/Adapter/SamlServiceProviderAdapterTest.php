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
use OCA\Integriq\Auth\Idp\Adapter\IdpAdapterServices;
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
#[CoversClass(IdpAdapterServices::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(TrustLevelMapper::class)]
#[UsesClass(AssertionGuard::class)]
#[UsesClass(EnvelopeReplayGuard::class)]
#[UsesClass(AtomicCache::class)]
class SamlServiceProviderAdapterTest extends TestCase {

	private const ENTITY_ID = 'https://nc.example/index.php/apps/integriq/api/idp/metadata/org-x/digid';

	private const ACS = 'https://nc.example/index.php/apps/integriq/api/idp/digid/callback';

	private const METADATA_URL = 'https://broker.example/metadata.xml';

	/**
	 * The registration's subject mapping: a BSN attribute under a broker-specific name.
	 */
	private const BSN_ATTRIBUTE = 'urn:broker:attribute:bsn';

	/**
	 * The stub, kept for the class: generating its keys is the slow part.
	 *
	 * @var SamlStubIdentityProvider|null
	 */
	private static ?SamlStubIdentityProvider $sharedIdp = null;

	/**
	 * The stub identity provider.
	 *
	 * @var SamlStubIdentityProvider
	 */
	private SamlStubIdentityProvider $idp;

	/** @var IdpSecretSeam&MockObject */
	private IdpSecretSeam $seam;

	/** @var list<string> The addresses the adapter fetched. */
	private array $fetched = [];

	/**
	 * The metadata the stub answers; a test may replace it.
	 *
	 * @var string
	 */
	private string $metadata;

	/**
	 * A fresh seam, and the stub identity provider shared by the class.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// Key generation is the slow part; one stub per test class is enough.
		self::$sharedIdp ??= new SamlStubIdentityProvider(spEntityId: self::ENTITY_ID, acsUrl: self::ACS);
		$this->idp = self::$sharedIdp;
		$this->metadata = $this->idp->metadata();
		$this->seam = $this->createMock(originalClassName: IdpSecretSeam::class);
		$this->seam->method('read')->willReturnCallback(
			fn (string $registration, string $organisation, string $role, string $reference): string => match ($role) {
				'signing' => $this->idp->spSigningCredential(),
				'decryption' => $this->idp->spDecryptionCredential(),
				default => throw new IdpSecretRefusedException(reason: 'secret-missing', detail: 'missing'),
			}
		);
	}

	/**
	 * A ready saml-sp registration, with overrides.
	 *
	 * @param array<string, mixed> $data Property overrides.
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

	/**
	 * An HTTP client that answers the stub metadata and records the addresses read.
	 *
	 * @return IClientService
	 */
	private function http(): IClientService {
		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $uri): IResponse {
				$this->fetched[] = $uri;
				$response = $this->createMock(originalClassName: IResponse::class);
				$response->method('getBody')->willReturn($this->metadata);

				return $response;
			}
		);
		$http = $this->createMock(originalClassName: IClientService::class);
		$http->method('newClient')->willReturn($client);

		return $http;
	}

	/**
	 * The adapter under test, for a registration.
	 *
	 * @param ?IdpRegistration $registration The registration, or the default one.
	 *
	 * @return SamlServiceProviderAdapter
	 */
	private function adapter(?IdpRegistration $registration = null): SamlServiceProviderAdapter {
		return new SamlServiceProviderAdapter(
			registration: ($registration ?? $this->registration()),
			services: new IdpAdapterServices(secrets: $this->seam, http: $this->http(), trust: new TrustLevelMapper()),
			entityId: self::ENTITY_ID,
			acsUrl: self::ACS
		);
	}

	/**
	 * The query parameters of the redirect, still url-encoded, in order.
	 *
	 * @param string $url The redirect address.
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

	/**
	 * The AuthnRequest a redirect carries, inflated.
	 *
	 * @param string $url The redirect address.
	 *
	 * @return DOMDocument
	 */
	private static function authnRequest(string $url): DOMDocument {
		$document = new DOMDocument();
		$document->loadXML((string)gzinflate(base64_decode(urldecode(self::rawQuery(url: $url)['SAMLRequest']))));

		return $document;
	}

	/**
	 * The AuthnRequest is signed and answered with its ID.
	 *
	 * @return void
	 */
	public function testTheAuthnRequestIsSignedAndAnsweredWithItsId(): void {
		$begun = $this->adapter()->beginAuthentication(['relayState' => 'state-1']);

		$this->assertStringStartsWith(prefix: SamlStubIdentityProvider::SSO_URL.'?', string: $begun['redirectUrl']);
		$query = self::rawQuery(url: $begun['redirectUrl']);
		$this->assertSame(expected: 'state-1', actual: urldecode($query['RelayState']));
		$this->assertSame(expected: 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', actual: urldecode($query['SigAlg']));

		$signed = 'SAMLRequest='.$query['SAMLRequest'].'&RelayState='.$query['RelayState'].'&SigAlg='.$query['SigAlg'];
		$this->assertSame(
			expected: 1,
			actual: openssl_verify($signed, base64_decode(urldecode($query['Signature'])), $this->idp->spSigningCertificate(), OPENSSL_ALGO_SHA256),
			message: 'The redirect signature verifies with the SP signing certificate.'
		);

		$request = self::authnRequest(url: $begun['redirectUrl'])->documentElement;
		$this->assertSame(expected: $begun['requestId'], actual: $request->getAttribute('ID'));
		$this->assertSame(expected: self::ACS, actual: $request->getAttribute('AssertionConsumerServiceURL'));
		$this->assertSame(expected: SamlStubIdentityProvider::SSO_URL, actual: $request->getAttribute('Destination'));
		$issuer = $request->getElementsByTagNameNS(SamlStubIdentityProvider::NS_SAML, 'Issuer')->item(0);
		$this->assertSame(expected: self::ENTITY_ID, actual: $issuer?->textContent);
		$this->assertSame(expected: [self::METADATA_URL], actual: $this->fetched);
	}

	/**
	 * The request asks only for the spellings at or above the floor.
	 *
	 * @return void
	 */
	public function testTheRequestAsksOnlyForTheSpellingsAtOrAboveTheFloor(): void {
		$begun = $this->adapter()->beginAuthentication(['relayState' => 'state-1']);

		$classRefs = [];
		$request = self::authnRequest(url: $begun['redirectUrl']);
		foreach ($request->getElementsByTagNameNS(SamlStubIdentityProvider::NS_SAML, 'AuthnContextClassRef') as $classRef) {
			$classRefs[] = $classRef->textContent;
		}

		$this->assertSame(expected: ['urn:oasis:names:tc:SAML:2.0:ac:classes:Smartcard'], actual: $classRefs);
	}

	/**
	 * A login without relay state does not start.
	 *
	 * @return void
	 */
	public function testALoginWithoutRelayStateDoesNotStart(): void {
		$this->expectException(exception: IdpAssertionException::class);
		$this->adapter()->beginAuthentication([]);
	}

	/**
	 * An encrypted signed assertion decrypts and passes the guard.
	 *
	 * @return void
	 */
	public function testAnEncryptedSignedAssertionDecryptsAndPassesTheGuard(): void {
		$adapter = $this->adapter();
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);
		$response = $this->idp->response(
			inResponseTo: $begun['requestId'],
			attributes: [self::BSN_ATTRIBUTE => '123456782'],
			options: ['encrypt' => true]
		);

		$assertion = $adapter->readAssertion(['SAMLResponse' => $response, 'RelayState' => 'state-1']);

		$this->assertSame(expected: $begun['requestId'], actual: $assertion['inResponseTo']);
		$this->assertSame(expected: self::ENTITY_ID, actual: $assertion['audience']);
		$this->assertSame(expected: '123456782', actual: $assertion['subject']);
		$this->assertSame(expected: 'bsn', actual: $assertion['subType']);
		$this->assertSame(expected: 'urn:oasis:names:tc:SAML:2.0:ac:classes:Smartcard', actual: $assertion['assuranceLevel']);
		$this->assertSame(expected: 'org-x', actual: $assertion['organisation']);
		$this->assertNotSame(expected: '', actual: $assertion['id']);
		$this->assertNotSame(expected: $begun['requestId'], actual: $assertion['id']);

		$caches = $this->createMock(originalClassName: ICacheFactory::class);
		$caches->method('isAvailable')->willReturn(true);
		$caches->method('createDistributed')->willReturn(new FakeMemcache());
		$guard = new AssertionGuard(replayGuard: new EnvelopeReplayGuard(cacheFactory: $caches));
		$guard->assertAcceptable(assertion: $assertion, outstandingRequestId: $begun['requestId'], expectedAudience: self::ENTITY_ID);
		$this->addToAssertionCount(count: 1);
	}

	/**
	 * A signed plain assertion is read when no decryption key is named.
	 *
	 * @return void
	 */
	public function testASignedPlainAssertionIsReadWhenNoDecryptionKeyIsNamed(): void {
		$registration = $this->registration(data: ['credentials' => ['signing' => 'cred-signing', 'salt' => 'cred-salt']]);
		$adapter = $this->adapter(registration: $registration);
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$assertion = $adapter->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: $begun['requestId'], attributes: [self::BSN_ATTRIBUTE => '123456782'])]
		);

		$this->assertSame(expected: '123456782', actual: $assertion['subject']);
	}

	/**
	 * The Responses a stub can forge that the adapter must refuse.
	 *
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
	 * A forged Response is refused, and the refusal names no attribute value.
	 *
	 * @param array<string, mixed> $options How the stub forges the Response.
	 *
	 * @return void
	 */
	#[DataProvider('forgedResponses')]
	public function testAForgedResponseIsRefusedBeforeAnyAttributeIsRead(array $options): void {
		$registration = $this->registration();
		if (($options['encrypt'] ?? false) === false) {
			$registration = $this->registration(data: ['credentials' => ['signing' => 'cred-signing', 'salt' => 'cred-salt']]);
		}

		$adapter = $this->adapter(registration: $registration);
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);
		$response = $this->idp->response(inResponseTo: $begun['requestId'], attributes: [self::BSN_ATTRIBUTE => '123456782'], options: $options);

		try {
			$adapter->readAssertion(['SAMLResponse' => $response]);
			$this->fail(message: 'The forged Response was accepted.');
		} catch (IdpAssertionException $refused) {
			$this->assertStringNotContainsString(needle: '123456782', haystack: $refused->getMessage());
			$this->assertStringNotContainsString(needle: '999999990', haystack: $refused->getMessage());
		}
	}

	/**
	 * A plain assertion is refused when encryption is expected.
	 *
	 * @return void
	 */
	public function testAPlainAssertionIsRefusedWhenEncryptionIsExpected(): void {
		$adapter = $this->adapter();
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$this->expectException(exception: IdpAssertionException::class);
		$adapter->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: $begun['requestId'], attributes: [self::BSN_ATTRIBUTE => '123456782'])]
		);
	}

	/**
	 * A response without in response to is refused.
	 *
	 * @return void
	 */
	public function testAResponseWithoutInResponseToIsRefused(): void {
		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: 'InResponseTo');
		$this->adapter()->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: '', attributes: [self::BSN_ATTRIBUTE => '123456782'], options: ['encrypt' => true])]
		);
	}

	/**
	 * A callback without a response is refused.
	 *
	 * @return void
	 */
	public function testACallbackWithoutAResponseIsRefused(): void {
		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: 'SAMLResponse');
		$this->adapter()->readAssertion(['RelayState' => 'state-1']);
	}

	/**
	 * Mappings, the attributes asserted, and the subject and type expected.
	 *
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
	 * The subject is read from the attribute the mapping names, and from no other.
	 *
	 * @param array<string, string> $mapping    The registration's subject mapping.
	 * @param array<string, string> $attributes The attributes the stub asserts.
	 * @param string                $subject    The subject expected.
	 * @param string                $subType    The subject type expected.
	 *
	 * @return void
	 */
	#[DataProvider('mappedSubjects')]
	public function testTheSubjectIsReadFromTheMappedAttributeOnly(
		array $mapping,
		array $attributes,
		string $subject,
		string $subType
	): void {
		$adapter = $this->adapter(registration: $this->registration(data: ['subjectMapping' => $mapping]));
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$assertion = $adapter->readAssertion(
			['SAMLResponse' => $this->idp->response(inResponseTo: $begun['requestId'], attributes: $attributes, options: ['encrypt' => true])]
		);

		$this->assertSame(expected: $subject, actual: $assertion['subject']);
		$this->assertSame(expected: $subType, actual: $assertion['subType']);
	}

	/**
	 * The branch is read from its mapped attribute.
	 *
	 * @return void
	 */
	public function testTheBranchIsReadFromItsMappedAttribute(): void {
		$registration = $this->registration(
			data: ['provider' => 'eherkenning', 'subjectMapping' => ['kvk' => 'urn:a:kvk', 'branch' => 'urn:a:vestiging']]
		);
		$adapter = $this->adapter(registration: $registration);
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

		$this->assertSame(expected: '000012345678', actual: $assertion['branch']);
		$this->assertSame(expected: 'urn:etoegang:core:assurance-class:loa3', actual: $assertion['assuranceLevel']);
	}

	/**
	 * Metadata without an https redirect sign on service is refused.
	 *
	 * @return void
	 */
	public function testMetadataWithoutAnHttpsRedirectSignOnServiceIsRefused(): void {
		$this->metadata = $this->idp->metadata(ssoUrl: 'http://idp.example/sso');

		$this->expectException(exception: IdpAssertionException::class);
		$this->adapter()->beginAuthentication(['relayState' => 'state-1']);
	}

	/**
	 * Metadata with an entity declaration is refused.
	 *
	 * @return void
	 */
	public function testMetadataWithAnEntityDeclarationIsRefused(): void {
		$this->metadata = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "x">]>'.substr($this->idp->metadata(), strlen('<?xml version="1.0"?>'));

		$this->expectException(exception: IdpAssertionException::class);
		$this->adapter()->beginAuthentication(['relayState' => 'state-1']);
	}

	/**
	 * A signing credential without a private key is refused.
	 *
	 * @return void
	 */
	public function testASigningCredentialWithoutAPrivateKeyIsRefused(): void {
		$seam = $this->createMock(originalClassName: IdpSecretSeam::class);
		$seam->method('read')->willReturn($this->idp->spSigningCertificate());
		$adapter = new SamlServiceProviderAdapter(
			registration: $this->registration(),
			services: new IdpAdapterServices(secrets: $seam, http: $this->http(), trust: new TrustLevelMapper()),
			entityId: self::ENTITY_ID,
			acsUrl: self::ACS
		);

		$this->expectException(exception: IdpAssertionException::class);
		$this->expectExceptionMessage(message: 'signing credential holds no certificate and private key');
		$adapter->beginAuthentication(['relayState' => 'state-1']);
	}

	/**
	 * A refused credential refuses the login.
	 *
	 * @return void
	 */
	public function testARefusedCredentialRefusesTheLogin(): void {
		$seam = $this->createMock(originalClassName: IdpSecretSeam::class);
		$seam->method('read')->willThrowException(new IdpSecretRefusedException(reason: 'keepiq-missing', detail: 'no keepiq'));
		$adapter = new SamlServiceProviderAdapter(
			registration: $this->registration(),
			services: new IdpAdapterServices(secrets: $seam, http: $this->http(), trust: new TrustLevelMapper()),
			entityId: self::ENTITY_ID,
			acsUrl: self::ACS
		);

		$this->expectException(exception: IdpAssertionException::class);
		$adapter->beginAuthentication(['relayState' => 'state-1']);
	}

	/**
	 * The current address is put back after a response is read.
	 *
	 * @return void
	 */
	public function testTheCurrentAddressIsPutBackAfterAResponseIsRead(): void {
		$_SERVER['REQUEST_URI'] = '/index.php/apps/other';
		$_SERVER['QUERY_STRING'] = 'a=b';
		$adapter = $this->adapter();
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);
		$adapter->readAssertion(
			[
				'SAMLResponse' => $this->idp->response(
					inResponseTo: $begun['requestId'],
					attributes: [self::BSN_ATTRIBUTE => '123456782'],
					options: ['encrypt' => true]
				),
			]
		);

		$this->assertSame(expected: '/index.php/apps/other', actual: $_SERVER['REQUEST_URI']);
		$this->assertSame(expected: 'a=b', actual: $_SERVER['QUERY_STRING']);
		unset($_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING']);
	}

	/**
	 * The factory builds the adapter of a registration with its callback.
	 *
	 * @return void
	 */
	public function testTheFactoryBuildsTheAdapterOfARegistrationWithItsCallback(): void {
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $params): string => match ($route) {
				'integriq.idpBroker.callback' => self::ACS,
				default => self::ENTITY_ID,
			}
		);
		$metadata = $this->createMock(originalClassName: IdpMetadataService::class);
		$metadata->method('entityId')->willReturn(self::ENTITY_ID);
		$factory = new SamlServiceProviderAdapterFactory(
			services: new IdpAdapterServices(secrets: $this->seam, http: $this->http(), trust: new TrustLevelMapper()),
			urlGenerator: $urls,
			metadata: $metadata
		);

		$adapter = $factory->build($this->registration());
		$begun = $adapter->beginAuthentication(['relayState' => 'state-1']);

		$this->assertSame(expected: 'digid', actual: $adapter->getProviderId());
		$this->assertTrue(condition: $adapter->isConfigured());
		$request = self::authnRequest(url: $begun['redirectUrl'])->documentElement;
		$this->assertSame(expected: self::ACS, actual: $request->getAttribute('AssertionConsumerServiceURL'));
	}
}
