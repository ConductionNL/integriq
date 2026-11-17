<?php

/**
 * The OIDC Relying Party adapter against a stub issuer: PKCE, state, nonce and
 * acr_values on the way out; client authentication, ID token verification and
 * the mapped subject on the way back.
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

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\Auth\Idp\Adapter\OidcLoginStore;
use OCA\Integriq\Auth\Idp\Adapter\OidcRelyingPartyAdapter;
use OCA\Integriq\Auth\Idp\AtomicCache;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Tests\Fixtures\Idp\OidcStubIssuer;
use OCA\Integriq\Tests\Unit\Auth\Idp\FakeMemcache;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
#[CoversClass(OidcRelyingPartyAdapter::class)]
#[UsesClass(OidcLoginStore::class)]
#[UsesClass(AtomicCache::class)]
#[UsesClass(IdpRegistration::class)]
#[UsesClass(TrustLevelMapper::class)]
class OidcRelyingPartyAdapterTest extends TestCase {

	private const NOW = 1_800_000_000;

	private const CLIENT = 'client-gemeente-x';

	private const CALLBACK = 'https://nc.example/apps/integriq/api/idp/eherkenning/callback';

	private OidcStubIssuer $issuer;

	/** @var IdpSecretSeam&MockObject */
	private IdpSecretSeam $seam;

	private OidcLoginStore $logins;

	/** @var array<string, string> */
	private array $secrets = [];

	protected function setUp(): void {
		parent::setUp();
		$this->issuer = new OidcStubIssuer(clientId: self::CLIENT, now: self::NOW);
		$this->seam = $this->createMock(IdpSecretSeam::class);
		$this->seam->method('read')->willReturnCallback(
			fn (string $registration, string $organisation, string $role, string $reference): string => $this->secrets[$role]
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn(new FakeMemcache());
		$this->logins = new OidcLoginStore($factory);
	}

	private function registration(array $data = []): IdpRegistration {
		return new IdpRegistration(
			id: 'reg-1',
			data: array_merge(
				[
					'organisation' => 'org-x',
					'provider' => 'eherkenning',
					'kind' => 'oidc-rp',
					'enabled' => true,
					'issuer' => OidcStubIssuer::ISSUER,
					'clientId' => self::CLIENT,
					'clientAuth' => 'private_key_jwt',
					'subjectMapping' => ['kvk' => 'urn:kvk', 'branch' => 'urn:vestiging'],
					'assuranceMapping' => ['loa2-x' => 'eh2', 'loa3-x' => 'eh3', 'loa4-x' => 'eh4'],
					'credentials' => ['signing' => 'cred-signing', 'clientSecret' => 'cred-secret'],
				],
				$data
			)
		);
	}

	private function adapter(?IdpRegistration $registration = null): OidcRelyingPartyAdapter {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $uri, array $options = []): IResponse => $this->response('GET', $uri, $options));
		$client->method('post')->willReturnCallback(fn (string $uri, array $options = []): IResponse => $this->response('POST', $uri, $options));
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);

		return new OidcRelyingPartyAdapter(
			registration: ($registration ?? $this->registration()),
			secrets: $this->seam,
			http: $http,
			logins: $this->logins,
			trust: new TrustLevelMapper(),
			redirectUri: self::CALLBACK,
			now: self::NOW
		);
	}

	private function response(string $method, string $uri, array $options): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($this->issuer->answer(method: $method, url: $uri, options: $options));

		return $response;
	}

	/**
	 * Start a login and point the stub at its nonce.
	 *
	 * @return array<string, string> The authorization request's query.
	 */
	private function start(OidcRelyingPartyAdapter $adapter, string $state = 'state-1'): array {
		$begun = $adapter->beginAuthentication(['organisation' => 'org-x', 'consumer' => 'c', 'trust' => 'substantial', 'relayState' => $state]);
		$this->assertStringStartsWith(OidcStubIssuer::AUTHORIZE.'?', $begun['redirectUrl']);
		parse_str((string)parse_url($begun['redirectUrl'], PHP_URL_QUERY), $query);
		$this->assertSame($begun['requestId'], $query['nonce']);
		$this->issuer->nonce = $query['nonce'];

		return $query;
	}

	private static function base64Url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	public function testTheAuthorizationRequestCarriesPkceStateNonceAndTheAcrValuesAtTheFloor(): void {
		$query = $this->start(adapter: $this->adapter());

		$this->assertSame('code', $query['response_type']);
		$this->assertSame(self::CLIENT, $query['client_id']);
		$this->assertSame(self::CALLBACK, $query['redirect_uri']);
		$this->assertSame('openid', $query['scope']);
		$this->assertSame('state-1', $query['state']);
		$this->assertSame('S256', $query['code_challenge_method']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['code_challenge']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $query['nonce']);
		// Substantial and high only: the EH2 spelling is never asked for.
		$this->assertSame('loa3-x loa4-x', $query['acr_values']);
	}

	public function testAPrivateKeyJwtLoginRedeemsTheCodeAndReadsTheMappedSubject(): void {
		$clientKey = OidcStubIssuer::rsaPem();
		$this->secrets['signing'] = $clientKey."-----BEGIN CERTIFICATE-----\nnot read here\n-----END CERTIFICATE-----\n";
		$adapter = $this->adapter();
		$query = $this->start(adapter: $adapter);
		$this->issuer->claims = ['acr' => 'loa3-x', 'urn:kvk' => '12345678', 'urn:vestiging' => '000012345678', 'bsn' => '999999999'];

		$assertion = $adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);

		$this->assertSame(
			[
				'id' => 'token-1',
				'inResponseTo' => $query['nonce'],
				'audience' => self::CLIENT,
				'notBefore' => self::NOW,
				'notOnOrAfter' => self::NOW + 300,
				'subject' => '12345678',
				'subType' => 'kvk',
				'assuranceLevel' => 'loa3-x',
				'organisation' => 'org-x',
				'branch' => '000012345678',
			],
			$assertion
		);

		[$request] = $this->issuer->tokenRequests;
		$form = $request['form'];
		$this->assertSame('authorization_code', $form['grant_type']);
		$this->assertSame('code-1', $form['code']);
		$this->assertSame(self::CALLBACK, $form['redirect_uri']);
		$this->assertSame($query['code_challenge'], self::base64Url(hash('sha256', $form['code_verifier'], true)));
		$this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
		$this->assertArrayNotHasKey('Authorization', $request['headers']);

		$jws = (new CompactSerializer())->unserialize($form['client_assertion']);
		$this->assertTrue(
			(new JWSVerifier(new AlgorithmManager([new RS256()])))->verifyWithKey($jws, JWKFactory::createFromKey($clientKey)->toPublic(), 0)
		);
		$claims = json_decode((string)$jws->getPayload(), true);
		$this->assertSame([self::CLIENT, self::CLIENT, OidcStubIssuer::TOKEN, self::NOW + 60], [$claims['iss'], $claims['sub'], $claims['aud'], $claims['exp']]);
	}

	public function testAnEcSigningKeySignsTheClientAssertionWithEs256(): void {
		$clientKey = OidcStubIssuer::ecPem();
		$this->secrets['signing'] = $clientKey;
		$adapter = $this->adapter();
		$this->start(adapter: $adapter);
		$this->issuer->claims = ['urn:kvk' => '12345678'];

		$adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);

		$jws = (new CompactSerializer())->unserialize($this->issuer->tokenRequests[0]['form']['client_assertion']);
		$this->assertSame('ES256', $jws->getSignature(0)->getProtectedHeader()['alg']);
		$this->assertTrue(
			(new JWSVerifier(new AlgorithmManager([new ES256()])))->verifyWithKey($jws, JWKFactory::createFromKey($clientKey)->toPublic(), 0)
		);
	}

	public function testClientSecretBasicSendsTheClientSecretAndNoAssertion(): void {
		$this->secrets['clientSecret'] = 's3cr:et';
		$adapter = $this->adapter(registration: $this->registration(['clientAuth' => 'client_secret_basic']));
		$this->start(adapter: $adapter);
		$this->issuer->claims = ['urn:kvk' => '12345678'];

		$adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);

		[$request] = $this->issuer->tokenRequests;
		$this->assertSame('Basic '.base64_encode(self::CLIENT.':s3cr%3Aet'), $request['headers']['Authorization']);
		$this->assertArrayNotHasKey('client_assertion', $request['form']);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedTokens(): array {
		return [
			'wrong nonce' => [['nonce' => 'another-login'], 'nonce'],
			'wrong audience' => [['aud' => 'another-client'], 'another client'],
			'audience list without azp' => [['aud' => [self::CLIENT, 'another-client']], 'another client'],
			'expired' => [['exp' => self::NOW], 'expired'],
			'no expiry' => [['exp' => null], 'expired'],
			'another issuer' => [['iss' => 'https://elsewhere.example'], 'another issuer'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedTokens')]
	public function testAnIdTokenThatDoesNotHoldIsRefused(array $claims, string $reason): void {
		$this->secrets['signing'] = OidcStubIssuer::rsaPem();
		$adapter = $this->adapter();
		$this->start(adapter: $adapter);
		$this->issuer->claims = ['urn:kvk' => '12345678'] + $claims;

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage($reason);

		$adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);
	}

	public function testAnIdTokenSignedByAnotherKeyIsRefused(): void {
		$this->secrets['signing'] = OidcStubIssuer::rsaPem();
		$adapter = $this->adapter();
		$this->start(adapter: $adapter);
		$this->issuer->signWith = OidcStubIssuer::rsaKey(kid: 'stub-1');

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('signature does not verify');

		$adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);
	}

	public function testACallbackIsReadOnce(): void {
		$this->secrets['signing'] = OidcStubIssuer::rsaPem();
		$adapter = $this->adapter();
		$this->start(adapter: $adapter);
		$this->issuer->claims = ['urn:kvk' => '12345678'];
		$adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('answers no outstanding login');

		$adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);
	}

	public function testAnErrorFromTheIssuerIsRefusedWithoutItsDescription(): void {
		try {
			$this->adapter()->readAssertion(['error' => 'access_denied', 'error_description' => 'bsn 999999999', 'state' => 'state-1']);
			$this->fail('An error callback was accepted.');
		} catch (IdpAssertionException $refused) {
			$this->assertStringNotContainsString('999999999', $refused->getMessage());
		}

		$this->assertSame([], $this->issuer->tokenRequests);
	}

	public function testACallbackWithoutACodeIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('carries no code');

		$this->adapter()->readAssertion(['state' => 'state-1']);
	}

	public function testADiscoveryDocumentForAnotherIssuerIsNotUsed(): void {
		$this->issuer->discovery = ['issuer' => 'https://elsewhere.example'];

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('names another issuer');

		$this->start(adapter: $this->adapter());
	}

	public function testAPlainHttpTokenEndpointIsNotUsed(): void {
		$this->issuer->discovery = ['token_endpoint' => 'http://issuer.example/token'];

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('no https token_endpoint');

		$this->start(adapter: $this->adapter());
	}

	public function testOnlyTheMappedClaimNamesAreRead(): void {
		// A `bsn` claim arrives, but the registration maps no bsn: the kvk mapping answers.
		$this->secrets['signing'] = OidcStubIssuer::rsaPem();
		$adapter = $this->adapter(registration: $this->registration(['subjectMapping' => ['bsn' => 'urn:bsn', 'kvk' => 'urn:kvk']]));
		$this->start(adapter: $adapter);
		$this->issuer->claims = ['bsn' => '999999999', 'kvk' => '87654321', 'urn:kvk' => '12345678'];

		$assertion = $adapter->readAssertion(['code' => 'code-1', 'state' => 'state-1']);

		$this->assertSame(['12345678', 'kvk'], [$assertion['subject'], $assertion['subType']]);
		$this->assertArrayNotHasKey('branch', $assertion);
	}

	public function testAStartWithoutAStateIsRefused(): void {
		$this->expectException(IdpAssertionException::class);

		$this->adapter()->beginAuthentication(['organisation' => 'org-x', 'relayState' => '']);
	}

	public function testItAnswersForTheRegistrationsProvider(): void {
		$adapter = $this->adapter();

		$this->assertSame('eherkenning', $adapter->getProviderId());
		$this->assertTrue($adapter->isConfigured());
	}
}
