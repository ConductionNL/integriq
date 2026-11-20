<?php

/**
 * A stub OIDC issuer for the Relying Party adapter's tests: discovery, JWKS and
 * a token endpoint that answers an ID token signed with a key generated here,
 * in the test, and never committed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Fixtures\Idp
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

namespace OCA\Integriq\Tests\Fixtures\Idp;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use RuntimeException;

/**
 * Answers the three issuer addresses the adapter reads, and records the token requests.
 */
final class OidcStubIssuer {

	public const ISSUER = 'https://issuer.example';

	public const AUTHORIZE = 'https://issuer.example/authorize';

	public const TOKEN = 'https://issuer.example/token';

	public const JWKS = 'https://issuer.example/jwks';

	/**
	 * The issuer's signing key.
	 *
	 * @var JWK
	 */
	public readonly JWK $key;

	/**
	 * The nonce the next ID token carries; the test copies it from the authorization request.
	 *
	 * @var string
	 */
	public string $nonce = '';

	/**
	 * Claims that replace or add to the defaults of the next ID token; null removes one.
	 *
	 * @var array<string, mixed>
	 */
	public array $claims = [];

	/**
	 * A key other than the published one to sign the next ID token with.
	 *
	 * @var JWK|null
	 */
	public ?JWK $signWith = null;

	/**
	 * Overrides of the discovery document.
	 *
	 * @var array<string, mixed>
	 */
	public array $discovery = [];

	/**
	 * Each token request: its form fields and headers.
	 *
	 * @var list<array{form: array<string, string>, headers: array<string, string>}>
	 */
	public array $tokenRequests = [];

	/**
	 * Create the issuer with a fresh RSA key.
	 *
	 * @param string $clientId The client the ID tokens are for.
	 * @param int    $now      The clock the default claims are set from.
	 */
	public function __construct(
		public readonly string $clientId,
		public readonly int $now,
	) {
		$this->key = self::rsaKey(kid: 'stub-1');

	}//end __construct()

	/**
	 * A fresh RSA key as a JWK, private parts included.
	 *
	 * @param string $kid The key id.
	 *
	 * @return JWK
	 */
	public static function rsaKey(string $kid): JWK {
		return JWKFactory::createFromKey(key: self::rsaPem(), additional_values: ['kid' => $kid, 'use' => 'sig']);

	}//end rsaKey()

	/**
	 * A fresh RSA private key as PEM.
	 *
	 * @return string
	 */
	public static function rsaPem(): string {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($key === false || openssl_pkey_export($key, $pem) === false) {
			throw new RuntimeException('No RSA key could be generated for the test.');
		}

		return $pem;

	}//end rsaPem()

	/**
	 * A fresh EC P-256 private key as PEM.
	 *
	 * @return string
	 */
	public static function ecPem(): string {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		if ($key === false || openssl_pkey_export($key, $pem) === false) {
			throw new RuntimeException('No EC key could be generated for the test.');
		}

		return $pem;

	}//end ecPem()

	/**
	 * The body the issuer answers at an address.
	 *
	 * @param string               $method  GET or POST.
	 * @param string               $url     The address.
	 * @param array<string, mixed> $options The request options (form_params, headers).
	 *
	 * @return string The JSON body.
	 */
	public function answer(string $method, string $url, array $options): string {
		return match ([$method, $url]) {
			['GET', self::ISSUER.'/.well-known/openid-configuration'] => json_encode($this->discoveryDocument()),
			['GET', self::JWKS] => json_encode(['keys' => [$this->key->toPublic()->all()]]),
			['POST', self::TOKEN] => $this->tokenResponse(options: $options),
			default => throw new RuntimeException('The stub issuer does not answer '.$method.' '.$url),
		};

	}//end answer()

	/**
	 * An ID token with the default claims, the test's overrides applied.
	 *
	 * @return string The compact ID token.
	 */
	public function idToken(): string {
		$claims = array_merge(
			[
				'iss' => self::ISSUER,
				'sub' => 'subject-1',
				'aud' => $this->clientId,
				'exp' => $this->now + 300,
				'iat' => $this->now,
				'nonce' => $this->nonce,
				'jti' => 'token-1',
			],
			$this->claims
		);
		$claims = array_filter($claims, static fn (mixed $value): bool => $value !== null);
		$key = ($this->signWith ?? $this->key);
		$algorithm = 'RS256';
		if ($key->get('kty') === 'EC') {
			$algorithm = 'ES256';
		}

		$jws = (new JWSBuilder(new AlgorithmManager([new RS256(), new ES256()])))->create()
			->withPayload(json_encode($claims))
			->addSignature($key, ['alg' => $algorithm, 'kid' => $key->get('kid')])
			->build();

		return (new CompactSerializer())->serialize($jws, 0);

	}//end idToken()

	/**
	 * The discovery document.
	 *
	 * @return array<string, mixed>
	 */
	private function discoveryDocument(): array {
		return array_merge(
			[
				'issuer' => self::ISSUER,
				'authorization_endpoint' => self::AUTHORIZE,
				'token_endpoint' => self::TOKEN,
				'jwks_uri' => self::JWKS,
			],
			$this->discovery
		);

	}//end discoveryDocument()

	/**
	 * Record a token request and answer an ID token.
	 *
	 * @param array<string, mixed> $options The request options.
	 *
	 * @return string The JSON body.
	 */
	private function tokenResponse(array $options): string {
		$this->tokenRequests[] = ['form' => ($options['form_params'] ?? []), 'headers' => ($options['headers'] ?? [])];

		return json_encode(['access_token' => 'access-1', 'token_type' => 'Bearer', 'id_token' => $this->idToken()]);

	}//end tokenResponse()

}//end class
