<?php

/**
 * Integriq ConsumerKeySourceResolver.
 *
 * Turns a consumer whose tokens are signed with keys it publishes (key source
 * `jwks`) or with keys of an outside OpenID Connect provider (key source
 * `oidc`) into the pinned algorithm and public key OpenRegister's JWT check
 * verifies against.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\RSAKey;
use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Jwks\JwksLookup;
use OCA\Integriq\Service\Jwks\JwksResolver;
use OCA\Integriq\Service\Jwks\JwksResolverProfile;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a consumer's verification key from a published key set.
 *
 * Every refusal throws before OpenRegister verifies anything, so a token is
 * never accepted on a key that could not be found. A published key set is
 * public by definition, so HMAC and `none` are refused for both key sources:
 * only the RSA algorithms OpenRegister verifies are accepted, and the key's
 * own `alg`, when it names one, must equal the token header's.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
 */
class ConsumerKeySourceResolver {

	/**
	 * The key source of a consumer that stores its public key itself.
	 *
	 * @var string
	 */
	public const STATIC = 'static';

	/**
	 * The key source of a consumer that publishes a JWKS.
	 *
	 * @var string
	 */
	public const JWKS = 'jwks';

	/**
	 * The key source of a consumer signing in through an outside OpenID Connect provider.
	 *
	 * @var string
	 */
	public const OIDC = 'oidc';

	/**
	 * The algorithms accepted from a published key set.
	 *
	 * @var array<int,string>
	 */
	public const ACCEPTED_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512'];

	/**
	 * The distributed cache namespace of consumer key sets and discovery documents.
	 *
	 * @var string
	 */
	public const CACHE_NAMESPACE = 'integriq.consumer.jwks';

	/**
	 * How long a discovery document is cached.
	 *
	 * @var integer
	 */
	public const DISCOVERY_TTL_SECONDS = 3600;

	/**
	 * The consumer key set and discovery cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * The shared key set resolver.
	 *
	 * @var JwksResolver
	 */
	private readonly JwksResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory   $cacheFactory The cache factory.
	 * @param CallService     $callService  The outbound call machinery.
	 * @param LoggerInterface $logger       The logger.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		CallService $callService,
		LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(self::CACHE_NAMESPACE);
		$this->resolver = new JwksResolver(
			cache: $this->cache,
			callService: $callService,
			logger: $logger,
			profile: new JwksResolverProfile(
				logLabel: 'ConsumerKeySourceResolver',
				sourceUuidPrefix: 'consumer-jwks-adhoc-',
				sourceName: 'Consumer JWKS resolver (ad-hoc, not persisted)'
			)
		);

	}//end __construct()

	/**
	 * The key source a consumer's authorization configuration names; `static` when it names none.
	 *
	 * @param array<string,mixed> $configuration The consumer's authorization configuration.
	 *
	 * @return string The key source, lower case.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
	 */
	public static function keySourceOf(array $configuration): string {
		$keySource = ($configuration['keySource'] ?? '');
		if (is_string($keySource) === false || trim($keySource) === '') {
			return self::STATIC;
		}

		return strtolower(trim($keySource));
	}//end keySourceOf()

	/**
	 * The issuer a `jwks` or `oidc` consumer's tokens carry, as configured; null for a static consumer.
	 *
	 * @param array<string,mixed> $configuration The consumer's authorization configuration.
	 *
	 * @return string|null The configured issuer, without a trailing slash.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
	 */
	public static function configuredIssuerOf(array $configuration): ?string {
		$field = self::issuerFieldOf(keySource: self::keySourceOf(configuration: $configuration));
		if ($field === null) {
			return null;
		}

		$issuer = ($configuration[$field] ?? '');
		if (is_string($issuer) === false || trim($issuer) === '') {
			return null;
		}

		return rtrim(trim($issuer), '/');
	}//end configuredIssuerOf()

	/**
	 * The configuration field that holds the issuer for a key source.
	 *
	 * @param string $keySource The key source.
	 *
	 * @return string|null The field, or null for a key source without an issuer.
	 */
	private static function issuerFieldOf(string $keySource): ?string {
		return match ($keySource) {
			self::JWKS => 'issuer',
			self::OIDC => 'issuerUrl',
			default => null,
		};
	}//end issuerFieldOf()

	/**
	 * The configuration OpenRegister verifies the token against.
	 *
	 * A `static` consumer's configuration is answered unchanged. For `jwks` and
	 * `oidc` the key the token names is resolved from the key set, the claims
	 * the consumer requires are checked, and the answer pins the token's
	 * algorithm and that key's public PEM.
	 *
	 * @param string              $consumerUuid  The consumer, for the cache and the logs.
	 * @param array<string,mixed> $configuration The consumer's authorization configuration.
	 * @param array<string,mixed> $header        The token's header.
	 * @param array<string,mixed> $payload       The token's claims.
	 *
	 * @return array<string,mixed> The configuration to verify against.
	 *
	 * @throws AuthenticationException When the token cannot be verified against the key source.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-token-is-checked-against-its-issuers-jwks-req-tokv-001
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-a-consumer-can-accept-tokens-from-an-outside-openid-connect-provider-req-tokv-002
	 */
	public function configurationFor(string $consumerUuid, array $configuration, array $header, array $payload): array {
		$keySource = self::keySourceOf(configuration: $configuration);
		if ($keySource === self::STATIC) {
			return $configuration;
		}

		self::assertPublishedKeySource(keySource: $keySource);

		$algorithm = self::acceptedAlgorithm(header: $header);
		$kid = self::keyIdOf(header: $header);

		$jwksUri = $this->jwksUriFor(consumerUuid: $consumerUuid, configuration: $configuration, keySource: $keySource, payload: $payload);
		$this->assertAudience(configuration: $configuration, keySource: $keySource, payload: $payload);
		$this->assertRequiredClaims(configuration: $configuration, payload: $payload);

		$key = $this->resolver->resolveKey(lookup: new JwksLookup(ownerType: 'consumer', ownerUuid: $consumerUuid, jwksUri: $jwksUri, kid: $kid));
		if ($key === null) {
			throw self::refused(reason: 'The key the token names was not found in the issuer\'s published key set', details: ['kid' => $kid]);
		}

		return array_merge(
			$configuration,
			['algorithm' => $algorithm, 'publicKey' => self::publicPem(key: $key, algorithm: $algorithm)]
		);
	}//end configurationFor()

	/**
	 * Refuse a key source other than `jwks` and `oidc`.
	 *
	 * @param string $keySource The key source.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the key source is unknown.
	 */
	private static function assertPublishedKeySource(string $keySource): void {
		if ($keySource !== self::JWKS && $keySource !== self::OIDC) {
			throw self::refused(reason: 'The consumer names an unknown key source', details: ['keySource' => $keySource]);
		}
	}//end assertPublishedKeySource()

	/**
	 * The key id the token's header names.
	 *
	 * @param array<string,mixed> $header The token's header.
	 *
	 * @return string The key id.
	 *
	 * @throws AuthenticationException When the header names no key id.
	 */
	private static function keyIdOf(array $header): string {
		$kid = ($header['kid'] ?? '');
		if (is_string($kid) === false || $kid === '') {
			throw self::refused(reason: 'The token names no key id (kid), so no key of the published set can be chosen');
		}

		return $kid;
	}//end keyIdOf()

	/**
	 * The header's algorithm, when it is one a published key set may sign with.
	 *
	 * @param array<string,mixed> $header The token's header.
	 *
	 * @return string The algorithm.
	 *
	 * @throws AuthenticationException When the algorithm is HMAC, `none` or not supported.
	 */
	private static function acceptedAlgorithm(array $header): string {
		$algorithm = ($header['alg'] ?? '');
		if (is_string($algorithm) === false) {
			$algorithm = '';
		}

		if (in_array($algorithm, self::ACCEPTED_ALGORITHMS, true) === false) {
			throw self::refused(
				reason: 'The token algorithm ' . $algorithm . ' is not accepted for a published key set; only RS and PS algorithms are',
				details: ['algorithm' => $algorithm]
			);
		}

		return $algorithm;
	}//end acceptedAlgorithm()

	/**
	 * Where the consumer's key set is published.
	 *
	 * @param string              $consumerUuid  The consumer.
	 * @param array<string,mixed> $configuration The configuration.
	 * @param string              $keySource     `jwks` or `oidc`.
	 * @param array<string,mixed> $payload       The token's claims.
	 *
	 * @return string The key set address.
	 *
	 * @throws AuthenticationException When no address is configured or discovered, or the issuer differs.
	 */
	private function jwksUriFor(string $consumerUuid, array $configuration, string $keySource, array $payload): string {
		if ($keySource === self::JWKS) {
			$jwksUri = ($configuration['jwksUri'] ?? '');
			if (is_string($jwksUri) === false || $jwksUri === '') {
				throw self::refused(reason: 'The consumer has no JWKS address configured');
			}

			return $jwksUri;
		}

		$discovery = $this->discovery(consumerUuid: $consumerUuid, configuration: $configuration);
		if (($payload['iss'] ?? null) !== $discovery['issuer']) {
			throw self::refused(reason: 'The token issuer does not match the provider\'s issuer', details: ['iss' => ($payload['iss'] ?? null)]);
		}

		return $discovery['jwks_uri'];
	}//end jwksUriFor()

	/**
	 * The provider's discovery document, cached per consumer.
	 *
	 * @param string              $consumerUuid  The consumer.
	 * @param array<string,mixed> $configuration The configuration.
	 *
	 * @return array{issuer:string,jwks_uri:string} The issuer and key set address.
	 *
	 * @throws AuthenticationException When no issuer URL is configured or the document cannot be read.
	 */
	private function discovery(string $consumerUuid, array $configuration): array {
		$issuerUrl = self::configuredIssuerOf(configuration: $configuration);
		if ($issuerUrl === null) {
			throw self::refused(reason: 'The consumer has no OpenID Connect issuer URL configured');
		}

		$cacheKey = 'oidc:' . $consumerUuid;
		$cached = $this->cachedDiscovery(cacheKey: $cacheKey, issuerUrl: $issuerUrl);
		if ($cached !== null) {
			return $cached;
		}

		$document = $this->resolver->fetchDocument(url: $issuerUrl . '/.well-known/openid-configuration', ownerUuid: $consumerUuid);
		$issuer = self::nonEmptyString(value: ($document['issuer'] ?? null));
		$jwksUri = self::nonEmptyString(value: ($document['jwks_uri'] ?? null));
		if ($issuer === null || $jwksUri === null) {
			throw self::refused(reason: 'The OpenID Connect provider\'s discovery document could not be read');
		}

		$this->cache->set(
			$cacheKey,
			json_encode(['configured' => $issuerUrl, 'issuer' => $issuer, 'jwks_uri' => $jwksUri]),
			self::DISCOVERY_TTL_SECONDS
		);

		return ['issuer' => $issuer, 'jwks_uri' => $jwksUri];
	}//end discovery()

	/**
	 * The cached discovery answer, when it was read for the issuer URL configured now.
	 *
	 * @param string $cacheKey  The cache key.
	 * @param string $issuerUrl The configured issuer URL.
	 *
	 * @return array{issuer:string,jwks_uri:string}|null The issuer and key set address, or null.
	 */
	private function cachedDiscovery(string $cacheKey, string $issuerUrl): ?array {
		$cached = $this->cache->get($cacheKey);
		if (is_string($cached) === false) {
			return null;
		}

		$document = json_decode($cached, true);
		if (is_array($document) === false || ($document['configured'] ?? null) !== $issuerUrl) {
			return null;
		}

		return ['issuer' => $document['issuer'], 'jwks_uri' => $document['jwks_uri']];
	}//end cachedDiscovery()

	/**
	 * The value when it is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null.
	 */
	private static function nonEmptyString(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end nonEmptyString()

	/**
	 * Refuse a token whose audience is not the configured one; `oidc` requires an audience.
	 *
	 * @param array<string,mixed> $configuration The configuration.
	 * @param string              $keySource     `jwks` or `oidc`.
	 * @param array<string,mixed> $payload       The token's claims.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the audience is missing or differs.
	 */
	private function assertAudience(array $configuration, string $keySource, array $payload): void {
		$audience = self::nonEmptyString(value: ($configuration['audience'] ?? ''));
		if ($audience === null) {
			if ($keySource === self::OIDC) {
				throw self::refused(reason: 'The consumer has no audience configured for its OpenID Connect provider');
			}

			return;
		}

		$claimed = self::claimedAudiences(payload: $payload);
		if (is_array($claimed) === false || in_array($audience, $claimed, true) === false) {
			throw self::refused(reason: 'The token audience does not include ' . $audience, details: ['audience' => $audience]);
		}
	}//end assertAudience()

	/**
	 * The token's `aud` claim, a single audience wrapped in a list.
	 *
	 * @param array<string,mixed> $payload The token's claims.
	 *
	 * @return mixed The claimed audiences; not an array when the claim is malformed.
	 */
	private static function claimedAudiences(array $payload): mixed {
		$claimed = ($payload['aud'] ?? []);
		if (is_string($claimed) === true) {
			return [$claimed];
		}

		return $claimed;
	}//end claimedAudiences()

	/**
	 * Refuse a token that lacks a required claim, or carries another value than the required one.
	 *
	 * `requiredClaims` is a list of claim names that must be present, or a map
	 * of claim name to the value it must have.
	 *
	 * @param array<string,mixed> $configuration The configuration.
	 * @param array<string,mixed> $payload       The token's claims.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When a required claim is missing or differs.
	 */
	private function assertRequiredClaims(array $configuration, array $payload): void {
		$required = ($configuration['requiredClaims'] ?? []);
		if (is_array($required) === false) {
			return;
		}

		foreach ($required as $name => $value) {
			self::assertClaim(name: $name, value: $value, payload: $payload);
		}
	}//end assertRequiredClaims()

	/**
	 * Refuse a token that lacks one required claim, or carries another value than the required one.
	 *
	 * @param int|string          $name    The claim name, or a list index whose value is the name.
	 * @param mixed               $value   The required value, or the claim name for a list entry.
	 * @param array<string,mixed> $payload The token's claims.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the claim is missing or differs.
	 */
	private static function assertClaim(int|string $name, mixed $value, array $payload): void {
		$presenceOnly = is_int($name);
		if ($presenceOnly === true) {
			$name = (string)$value;
		}

		if (array_key_exists($name, $payload) === false || ($presenceOnly === false && $payload[$name] !== $value)) {
			throw self::refused(reason: 'The token lacks the required claim ' . $name, details: ['claim' => $name]);
		}
	}//end assertClaim()

	/**
	 * The key's public PEM, after checking it is an RSA key for this algorithm.
	 *
	 * @param JWK    $key       The resolved key.
	 * @param string $algorithm The token's algorithm.
	 *
	 * @return string The PEM.
	 *
	 * @throws AuthenticationException When the key is not RSA or names another algorithm.
	 */
	private static function publicPem(JWK $key, string $algorithm): string {
		if ($key->has('alg') === true && $key->get('alg') !== $algorithm) {
			throw self::refused(
				reason: 'The token algorithm does not match the key\'s algorithm',
				details: ['algorithm' => $algorithm, 'keyAlgorithm' => $key->get('alg')]
			);
		}

		if ($key->get('kty') !== 'RSA') {
			throw self::refused(reason: 'The published key is not an RSA key', details: ['kty' => $key->get('kty')]);
		}

		try {
			return RSAKey::createFromJWK(jwk: $key->toPublic())->toPEM();
		} catch (Throwable $exception) {
			throw self::refused(reason: 'The published key could not be read');
		}
	}//end publicPem()

	/**
	 * A refusal in the shape every inbound check answers.
	 *
	 * @param string              $reason  Why.
	 * @param array<string,mixed> $details More detail.
	 *
	 * @return AuthenticationException The refusal.
	 */
	private static function refused(string $reason, array $details = []): AuthenticationException {
		return new AuthenticationException(message: 'The token could not be validated', details: array_merge(['reason' => $reason], $details));
	}//end refused()
}//end class
