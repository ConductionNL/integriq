<?php

/**
 * Integriq OidcRelyingPartyAdapter.
 *
 * One organisation's `oidc-rp` registration as a government identity provider
 * adapter (portal-idp-broker-config D1): the authorization code flow with
 * PKCE, `state` and `nonce`, the client authenticated by `private_key_jwt` or
 * `client_secret_basic`, and the ID token verified against the issuer's JWKS.
 * Nothing here names a broker: the issuer, the client and the claim names are
 * what an administrator configured on the registration.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp\Adapter;

use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * The OIDC Relying Party adapter of one registration.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
class OidcRelyingPartyAdapter implements GovernmentIdpAdapterInterface {
	use RegistrationAdapterSupport;


	/**
	 * Client authentication with a JWT signed by the `signing` credential.
	 *
	 * @var string
	 */
	public const AUTH_PRIVATE_KEY_JWT = 'private_key_jwt';

	/**
	 * Client authentication with the `clientSecret` credential over HTTP Basic.
	 *
	 * @var string
	 */
	public const AUTH_CLIENT_SECRET_BASIC = 'client_secret_basic';

	/**
	 * The subject fields in the order they are looked for, with the subject type each yields.
	 *
	 * @var array<string, string>
	 */
	public const SUBJECT_FIELDS = IdpRegistration::SUBJECT_TYPES;

	/**
	 * How long a client assertion is valid, in seconds.
	 *
	 * @var integer
	 */
	private const CLIENT_ASSERTION_SECONDS = 60;

	/**
	 * The HTTP timeout toward the issuer, in seconds.
	 *
	 * @var integer
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * The issuer's discovery document, once read for this adapter.
	 *
	 * @var array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string}|null
	 */
	private ?array $discovery = null;

	/**
	 * Constructor.
	 *
	 * @param IdpRegistration  $registration The organisation's ready `oidc-rp` registration.
	 * @param IdpSecretSeam    $secrets      Reads the `signing` or `clientSecret` credential, for this request only.
	 * @param IClientService   $http         Reaches the issuer.
	 * @param OidcLoginStore   $logins       Holds each login's nonce and code verifier between start and callback.
	 * @param TrustLevelMapper $trust        Says which spellings of the registration reach the floor.
	 * @param string           $redirectUri  The shared callback of the registration's provider.
	 * @param integer|null     $now          The clock, injectable for tests.
	 */
	public function __construct(
		private readonly IdpRegistration $registration,
		private readonly IdpSecretSeam $secrets,
		private readonly IClientService $http,
		private readonly OidcLoginStore $logins,
		private readonly TrustLevelMapper $trust,
		private readonly string $redirectUri,
		private readonly ?int $now = null,
	) {

	}//end __construct()



	/**
	 * Start an authorization code login at the issuer.
	 *
	 * @param array<string,mixed> $context `{organisation, consumer, trust, relayState}`; the relay state is the `state`.
	 *                                     An attribute login adds `claims`, the claim names to ask for, each as an
	 *                                     essential claim of the ID token.
	 *
	 * @return array{requestId: string, redirectUrl: string} The nonce, which the ID token answers, and the issuer's address.
	 *
	 * @throws IdpAssertionException When there is no state, or the issuer or the cache cannot be used.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function beginAuthentication(array $context): array {
		$state = trim((string)($context['relayState'] ?? ''));
		if ($state === '') {
			throw new IdpAssertionException(message: 'An OIDC login needs a state, so no login starts.');
		}

		$endpoint = $this->discovery()['authorization_endpoint'];
		$nonce = self::randomToken();
		$verifier = self::randomToken();
		$this->logins->remember(state: $state, nonce: $nonce, verifier: $verifier);

		$query = [
			'response_type' => 'code',
			'client_id' => $this->registration->text(key: 'clientId'),
			'redirect_uri' => $this->redirectUri,
			'scope' => 'openid',
			'state' => $state,
			'nonce' => $nonce,
			'code_challenge' => self::base64Url(bytes: hash('sha256', $verifier, true)),
			'code_challenge_method' => 'S256',
		];
		$acrValues = $this->floorSpellings();
		if ($acrValues !== []) {
			$query['acr_values'] = implode(' ', $acrValues);
		}

		$claims = self::claimsRequest(claims: ($context['claims'] ?? []));
		if ($claims !== '') {
			$query['claims'] = $claims;
		}

		$separator = '?';
		if (str_contains($endpoint, '?') === true) {
			$separator = '&';
		}

		return [
			'requestId' => $nonce,
			'redirectUrl' => $endpoint.$separator.http_build_query($query, '', '&', PHP_QUERY_RFC3986),
		];

	}//end beginAuthentication()

	/**
	 * Redeem the code and turn the verified ID token into a normalised assertion.
	 *
	 * @param array<string,mixed> $callback What arrived on the callback: `code` and `state`, or `error`.
	 *
	 * @return array<string,mixed> The assertion {@see \OCA\Integriq\Auth\Idp\AssertionGuard} reads.
	 *
	 * @throws IdpAssertionException When the callback, the token response or the ID token does not hold.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function readAssertion(array $callback): array {
		if (isset($callback['error']) === true) {
			// The description is the issuer's text; it is not repeated.
			throw new IdpAssertionException(message: 'The identity provider answered with an error, so no login results.');
		}

		$code = self::param(callback: $callback, name: 'code');
		$state = self::param(callback: $callback, name: 'state');
		$login = $this->logins->take(state: $state);

		$idToken = $this->redeem(code: $code, verifier: $login['verifier']);
		$claims = $this->verifiedClaims(idToken: $idToken);
		if (hash_equals($login['nonce'], (string)($claims['nonce'] ?? '')) === false) {
			throw new IdpAssertionException(message: 'The ID token answers another login (nonce), so it is refused.');
		}

		return $this->normalised(claims: $claims, idToken: $idToken, nonce: $login['nonce']);

	}//end readAssertion()

	/**
	 * The OIDC `claims` request parameter asking for each claim as essential in the ID token.
	 *
	 * @param mixed $claims The claim names an attribute login asks for.
	 *
	 * @return string The JSON request, or '' when no claim is asked for.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	private static function claimsRequest(mixed $claims): string {
		if (is_array($claims) === false) {
			return '';
		}

		$idToken = [];
		foreach ($claims as $claim) {
			if (is_string($claim) === true && trim($claim) !== '') {
				$idToken[trim($claim)] = ['essential' => true];
			}
		}

		if ($idToken === []) {
			return '';
		}

		return (string)json_encode(['id_token' => $idToken], JSON_UNESCAPED_SLASHES);

	}//end claimsRequest()


	/**
	 * The issuer's discovery document, checked against the registration.
	 *
	 * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string}
	 *
	 * @throws IdpAssertionException When it cannot be read, names another issuer, or lacks an https endpoint.
	 */
	private function discovery(): array {
		if ($this->discovery !== null) {
			return $this->discovery;
		}

		$issuer = rtrim($this->registration->text(key: 'issuer'), '/');
		$document = $this->getJson(url: $issuer.'/.well-known/openid-configuration', what: 'discovery document');
		if (rtrim((string)($document['issuer'] ?? ''), '/') !== $issuer) {
			throw new IdpAssertionException(message: 'The discovery document names another issuer, so it is not used.');
		}

		$discovery = ['issuer' => (string)$document['issuer']];
		foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
			$url = ($document[$field] ?? null);
			if (is_string($url) === false || str_starts_with($url, 'https://') === false) {
				throw new IdpAssertionException(message: 'The discovery document has no https '.$field.', so it is not used.');
			}

			$discovery[$field] = $url;
		}

		$this->discovery = $discovery;

		return $discovery;

	}//end discovery()

	/**
	 * Exchange the code for tokens and answer the ID token.
	 *
	 * @param string $code     The authorization code.
	 * @param string $verifier The PKCE code verifier.
	 *
	 * @return string The compact ID token.
	 *
	 * @throws IdpAssertionException When the token request fails or answers no ID token.
	 */
	private function redeem(string $code, string $verifier): string {
		$tokenEndpoint = $this->discovery()['token_endpoint'];
		$form = [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $this->redirectUri,
			'code_verifier' => $verifier,
			'client_id' => $this->registration->text(key: 'clientId'),
		];
		$headers = ['Accept' => 'application/json'];
		if ($this->registration->text(key: 'clientAuth') === self::AUTH_CLIENT_SECRET_BASIC) {
			$headers['Authorization'] = 'Basic '.base64_encode(
				rawurlencode($this->registration->text(key: 'clientId')).':'.rawurlencode($this->credential(role: 'clientSecret'))
			);
		} else {
			$form['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
			$form['client_assertion'] = $this->clientAssertion(audience: $tokenEndpoint);
		}

		try {
			$response = $this->http->newClient()->post(
				$tokenEndpoint,
				['form_params' => $form, 'headers' => $headers, 'timeout' => self::TIMEOUT_SECONDS]
			);
			$tokens = json_decode(self::body(body: $response->getBody()), true);
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The token request failed ('.$exception::class.'), so no login results.');
		}

		$idToken = ($tokens['id_token'] ?? null);
		if (is_string($idToken) === false || $idToken === '') {
			throw new IdpAssertionException(message: 'The token response carries no ID token, so no login results.');
		}

		return $idToken;

	}//end redeem()

	/**
	 * A client assertion for the token endpoint, signed with the `signing` credential.
	 *
	 * @param string $audience The token endpoint.
	 *
	 * @return string The compact JWS.
	 *
	 * @throws IdpAssertionException When the credential holds no usable private key.
	 */
	private function clientAssertion(string $audience): string {
		$secret = $this->credential(role: 'signing');
		if (preg_match('/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----.+?-----END (?:RSA |EC )?PRIVATE KEY-----/s', $secret, $match) !== 1) {
			throw new IdpAssertionException(message: 'The signing credential holds no private key, so no client assertion is made.');
		}

		try {
			$key = JWKFactory::createFromKey(key: $match[0]);
			$algorithm = match ($key->get('kty')) {
				'RSA' => 'RS256',
				'EC' => 'ES256',
				default => throw new IdpAssertionException(message: 'The signing key is neither RSA nor EC.'),
			};
			$now = $this->clock();
			$clientId = $this->registration->text(key: 'clientId');
			$payload = json_encode(
				[
					'iss' => $clientId,
					'sub' => $clientId,
					'aud' => $audience,
					'jti' => self::randomToken(),
					'iat' => $now,
					'exp' => $now + self::CLIENT_ASSERTION_SECONDS,
				],
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
			);
			$jws = (new JWSBuilder(self::algorithms()))->create()
				->withPayload($payload)
				->addSignature($key, ['alg' => $algorithm, 'typ' => 'JWT'])
				->build();

			return (new CompactSerializer())->serialize($jws, 0);
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The client assertion could not be signed ('.$exception::class.'), so no login results.');
		}

	}//end clientAssertion()

	/**
	 * The ID token's claims, once its signature verifies against the issuer's JWKS and its claims hold.
	 *
	 * @param string $idToken The compact ID token.
	 *
	 * @return array<string, mixed> The claims.
	 *
	 * @throws IdpAssertionException When the signature, the issuer, the audience or the expiry does not hold.
	 */
	private function verifiedClaims(string $idToken): array {
		$discovery = $this->discovery();
		$keys = $this->getJson(url: $discovery['jwks_uri'], what: 'JWKS');
		try {
			$jws = (new CompactSerializer())->unserialize($idToken);
			$verified = (new JWSVerifier(self::algorithms()))->verifyWithKeySet(
				$jws,
				JWKSet::createFromKeyData($keys),
				0
			);
			$claims = json_decode((string)$jws->getPayload(), true);
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The ID token cannot be read ('.$exception::class.'), so it is refused.');
		}

		if ($verified !== true || is_array($claims) === false) {
			throw new IdpAssertionException(message: 'The ID token signature does not verify, so it is refused.');
		}

		$this->assertClaims(claims: $claims, issuer: $discovery['issuer']);

		return $claims;

	}//end verifiedClaims()

	/**
	 * Refuse an ID token for another issuer or client, or one that expired.
	 *
	 * @param array<string, mixed> $claims The claims.
	 * @param string               $issuer The issuer the discovery document named.
	 *
	 * @return void
	 *
	 * @throws IdpAssertionException When a claim does not hold.
	 */
	private function assertClaims(array $claims, string $issuer): void {
		if (($claims['iss'] ?? null) !== $issuer) {
			throw new IdpAssertionException(message: 'The ID token names another issuer, so it is refused.');
		}

		$clientId = $this->registration->text(key: 'clientId');
		$audiences = ($claims['aud'] ?? []);
		if (is_string($audiences) === true) {
			$audiences = [$audiences];
		}

		if (is_array($audiences) === false || in_array($clientId, $audiences, true) === false
			|| (count($audiences) > 1 && ($claims['azp'] ?? null) !== $clientId)
		) {
			throw new IdpAssertionException(message: 'The ID token is meant for another client (aud), so it is refused.');
		}

		$expires = ($claims['exp'] ?? null);
		if (is_int($expires) === false || $this->clock() >= $expires) {
			throw new IdpAssertionException(message: 'The ID token has expired, so it is refused.');
		}

	}//end assertClaims()

	/**
	 * The normalised assertion of verified claims, read through the registration's subject mapping only.
	 *
	 * An attribute login (Yivi) also answers `disclosed`: the claim name and
	 * value of each claim on the registration's attribute allowlist that the
	 * token carries. A claim the allowlist does not name is never read.
	 *
	 * @param array<string, mixed> $claims  The verified claims.
	 * @param string               $idToken The compact ID token, for an id when it carries no `jti`.
	 * @param string               $nonce   The nonce the token answers.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	private function normalised(array $claims, string $idToken, string $nonce): array {
		$mapping = $this->registration->stringMap(key: 'subjectMapping');
		[$subject, $subType] = self::subject(claims: $claims, mapping: $mapping);

		$assertion = [
			'id' => self::claimText(claims: $claims, name: 'jti'),
			'inResponseTo' => $nonce,
			'audience' => $this->registration->text(key: 'clientId'),
			'notBefore' => ($claims['nbf'] ?? $claims['iat'] ?? null),
			'notOnOrAfter' => $claims['exp'],
			'subject' => $subject,
			'subType' => $subType,
			'assuranceLevel' => self::claimText(claims: $claims, name: 'acr'),
			'organisation' => $this->registration->organisation(),
		];
		if ($assertion['id'] === '') {
			$assertion['id'] = hash('sha256', $idToken);
		}

		$branchClaim = ($mapping['branch'] ?? '');
		if ($branchClaim !== '' && self::claimText(claims: $claims, name: $branchClaim) !== '') {
			$assertion['branch'] = self::claimText(claims: $claims, name: $branchClaim);
		}

		if ($this->registration->provider() === TrustLevelMapper::PROVIDER_YIVI) {
			$assertion['disclosed'] = $this->disclosed(claims: $claims);
		}

		return $assertion;

	}//end normalised()

	/**
	 * The allowlisted claims the token carries, by claim name.
	 *
	 * @param array<string, mixed> $claims The verified claims.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	private function disclosed(array $claims): array {
		$disclosed = [];
		foreach ($this->registration->attributeAllowlist() as $attribute) {
			$value = self::claimText(claims: $claims, name: $attribute['claim']);
			if ($value !== '') {
				$disclosed[$attribute['claim']] = $value;
			}
		}

		return $disclosed;

	}//end disclosed()

	/**
	 * The first mapped subject field the claims carry, with its subject type.
	 *
	 * @param array<string, mixed>  $claims  The verified claims.
	 * @param array<string, string> $mapping The registration's subject mapping.
	 *
	 * @return array{0: string, 1: string} The subject and its type; both empty when none is carried.
	 */
	private static function subject(array $claims, array $mapping): array {
		foreach (self::SUBJECT_FIELDS as $field => $subType) {
			$claim = ($mapping[$field] ?? '');
			if ($claim === '') {
				continue;
			}

			$value = self::claimText(claims: $claims, name: $claim);
			if ($value !== '') {
				return [$value, $subType];
			}
		}

		return ['', ''];

	}//end subject()

	/**
	 * A scalar claim as trimmed text, or ''.
	 *
	 * @param array<string, mixed> $claims The claims.
	 * @param string               $name   The claim name.
	 *
	 * @return string
	 */
	private static function claimText(array $claims, string $name): string {
		$value = ($claims[$name] ?? '');
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);

	}//end claimText()


	/**
	 * A JSON document from the issuer.
	 *
	 * @param string $url  The address.
	 * @param string $what What it is, for the message.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws IdpAssertionException When it cannot be fetched or is not a JSON object.
	 */
	private function getJson(string $url, string $what): array {
		try {
			$response = $this->http->newClient()->get(
				$url,
				['headers' => ['Accept' => 'application/json'], 'timeout' => self::TIMEOUT_SECONDS]
			);
			$document = json_decode(self::body(body: $response->getBody()), true);
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The issuer\'s '.$what.' could not be read ('.$exception::class.').');
		}

		if (is_array($document) === false) {
			throw new IdpAssertionException(message: 'The issuer\'s '.$what.' is not a JSON object.');
		}

		return $document;

	}//end getJson()

	/**
	 * A required callback parameter.
	 *
	 * @param array<string, mixed> $callback The callback.
	 * @param string               $name     The parameter.
	 *
	 * @return string
	 *
	 * @throws IdpAssertionException When it is absent or empty.
	 */
	private static function param(array $callback, string $name): string {
		$value = ($callback[$name] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			throw new IdpAssertionException(message: 'The callback carries no '.$name.', so it is refused.');
		}

		return trim($value);

	}//end param()


	/**
	 * The signature algorithms an ID token or a client assertion may use; never `none` or a MAC.
	 *
	 * @return AlgorithmManager
	 */
	private static function algorithms(): AlgorithmManager {
		return new AlgorithmManager([new RS256(), new PS256(), new ES256()]);

	}//end algorithms()

	/**
	 * An unguessable url-safe token.
	 *
	 * @return string
	 */
	private static function randomToken(): string {
		return self::base64Url(bytes: random_bytes(32));

	}//end randomToken()

	/**
	 * Bytes as url-safe base64 without padding.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string
	 */
	private static function base64Url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

	}//end base64Url()

	/**
	 * The clock.
	 *
	 * @return integer
	 */
	private function clock(): int {
		return ($this->now ?? time());

	}//end clock()

}//end class
