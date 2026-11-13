<?php

/**
 * Integriq JwksResolver.
 *
 * Resolves a published key set (`jwks_uri`) to a `JWKSet` and looks up a
 * presented token's `kid`, with per-owner caching and a rate-limited refetch
 * guard (SSRF-amplification defence). Shared by the LTI launch path and the
 * gateway consumer path.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Jwks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Jwks;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetch-and-cache key set resolution.
 *
 * The outbound fetch goes through {@see CallService} against an ephemeral,
 * never-persisted source, so no source row is created per owner. The cache
 * namespace is the caller's (it hands in its own ICache), the cache keys are
 * per owner, and a refused or failed fetch answers null: the caller refuses
 * the token, it never accepts one it could not verify.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
 */
class JwksResolver {

	/**
	 * Default cache TTL for a resolved key set (1 hour).
	 *
	 * @var integer
	 */
	public const DEFAULT_TTL_SECONDS = 3600;

	/**
	 * Minimum interval between refetches for the same owner when a token names
	 * a `kid` the cached set does not hold.
	 *
	 * @var integer
	 */
	public const REFETCH_GUARD_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param ICache              $cache       The caller's cache namespace.
	 * @param CallService         $callService The outbound call machinery.
	 * @param LoggerInterface     $logger      The logger.
	 * @param JwksResolverProfile $profile     The caller's log label and ad-hoc source identity.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
	 */
	public function __construct(
		private readonly ICache $cache,
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
		private readonly JwksResolverProfile $profile,
	) {

	}//end __construct()

	/**
	 * Resolve the key a token names from its owner's key set.
	 *
	 * @param JwksLookup $lookup Whose key set, where, and which key.
	 *
	 * @return JWK|null The key, or null when it cannot be found or fetched.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
	 */
	public function resolveKey(JwksLookup $lookup): ?JWK {
		$cacheKey = $this->cacheKey(lookup: $lookup);

		$cachedKey = $this->keyFromCachedSet(cacheKey: $cacheKey, kid: $lookup->kid);
		if ($cachedKey !== null) {
			return $cachedKey;
		}

		// Cache miss OR the cached set does not contain this kid: refetch,
		// but never more than once per REFETCH_GUARD_SECONDS per owner.
		if ($this->claimRefetch(lookup: $lookup) === false) {
			return null;
		}

		return $this->refetchKey(lookup: $lookup, cacheKey: $cacheKey);

	}//end resolveKey()

	/**
	 * Fetch a JSON document (e.g. an OpenID Connect discovery document) through the same ad-hoc source.
	 *
	 * @param string $url       The document's address.
	 * @param string $ownerUuid The owner, for the logs and the ad-hoc source id.
	 *
	 * @return array<string,mixed>|null The decoded document, or null when it cannot be fetched or is not a JSON object.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-2
	 */
	public function fetchDocument(string $url, string $ownerUuid): ?array {
		$parts = parse_url($url);
		if ($parts === false || isset($parts['scheme'], $parts['host']) === false) {
			$this->logger->warning($this->profile->logLabel . ': invalid document url', ['registrationUuid' => $ownerUuid]);
			return null;
		}

		$body = $this->fetchBody(parts: $parts, ownerUuid: $ownerUuid);
		if ($body === null) {
			return null;
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || array_is_list($decoded) === true) {
			$this->logger->info($this->profile->logLabel . ': response is not a JSON object', ['registrationUuid' => $ownerUuid]);
			return null;
		}

		return $decoded;

	}//end fetchDocument()

	/**
	 * The cache key of an owner's key set.
	 *
	 * @param JwksLookup $lookup The lookup.
	 *
	 * @return string The key.
	 */
	private function cacheKey(JwksLookup $lookup): string {
		return 'jwks:' . $lookup->ownerType . ':' . $lookup->ownerUuid;
	}//end cacheKey()

	/**
	 * The cache key of an owner's refetch guard.
	 *
	 * @param string $ownerUuid The owner.
	 *
	 * @return string The key.
	 */
	private function refetchGuardKey(string $ownerUuid): string {
		return 'jwks:refetch:' . $ownerUuid;
	}//end refetchGuardKey()

	/**
	 * The key from the cached set, if the set is cached and holds it.
	 *
	 * @param string $cacheKey The set's cache key.
	 * @param string $kid      The key id.
	 *
	 * @return JWK|null The key.
	 */
	private function keyFromCachedSet(string $cacheKey, string $kid): ?JWK {
		$cached = $this->cache->get($cacheKey);
		if (is_string($cached) === false) {
			return null;
		}

		$jwkSet = $this->deserializeJwkSet(cached: $cached);
		if ($jwkSet !== null && $jwkSet->has($kid) === true) {
			return $jwkSet->get($kid);
		}

		return null;

	}//end keyFromCachedSet()

	/**
	 * Claim the owner's refetch slot; false while the guard is active.
	 *
	 * @param JwksLookup $lookup The lookup.
	 *
	 * @return bool Whether a refetch may run now.
	 */
	private function claimRefetch(JwksLookup $lookup): bool {
		$guardKey = $this->refetchGuardKey(ownerUuid: $lookup->ownerUuid);
		if ($this->cache->get($guardKey) !== null) {
			// Guard active: fail closed. Never fall back to accepting an
			// unverified token.
			$this->logger->info(
				$this->profile->logLabel . ': refetch guard active, rejecting unresolved kid',
				['registrationType' => $lookup->ownerType, 'registrationUuid' => $lookup->ownerUuid, 'kid' => $lookup->kid]
			);
			return false;
		}

		$this->cache->set($guardKey, true, self::REFETCH_GUARD_SECONDS);

		return true;

	}//end claimRefetch()

	/**
	 * Fetch the set, cache it and look up the key.
	 *
	 * @param JwksLookup $lookup   The lookup.
	 * @param string     $cacheKey The set's cache key.
	 *
	 * @return JWK|null The key.
	 */
	private function refetchKey(JwksLookup $lookup, string $cacheKey): ?JWK {
		$fetched = $this->fetchJwks(jwksUri: $lookup->jwksUri, ownerUuid: $lookup->ownerUuid);
		if ($fetched === null) {
			return null;
		}

		$this->cache->set($cacheKey, json_encode($fetched), self::DEFAULT_TTL_SECONDS);

		$jwkSet = $this->deserializeJwkSet(cached: json_encode($fetched));
		if ($jwkSet !== null && $jwkSet->has($lookup->kid) === true) {
			return $jwkSet->get($lookup->kid);
		}

		return null;

	}//end refetchKey()

	/**
	 * Parse a cached key set; a malformed one is a miss.
	 *
	 * @param string $cached The cached JSON.
	 *
	 * @return JWKSet|null The set.
	 */
	private function deserializeJwkSet(string $cached): ?JWKSet {
		try {
			return JWKSet::createFromJson($cached);
		} catch (Throwable $exception) {
			$this->logger->warning($this->profile->logLabel . ': malformed cached JWKS, treating as miss', ['error' => $exception->getMessage()]);
			return null;
		}

	}//end deserializeJwkSet()

	/**
	 * Fetch and decode a key set document.
	 *
	 * @param string $jwksUri   Where the set is published.
	 * @param string $ownerUuid The owner.
	 *
	 * @return array<string,mixed>|null The document.
	 */
	private function fetchJwks(string $jwksUri, string $ownerUuid): ?array {
		$parts = parse_url($jwksUri);
		if ($parts === false || isset($parts['scheme'], $parts['host']) === false) {
			$this->logger->warning($this->profile->logLabel . ': invalid jwks_uri', ['registrationUuid' => $ownerUuid]);
			return null;
		}

		$body = $this->fetchBody(parts: $parts, ownerUuid: $ownerUuid);
		if ($body === null) {
			return null;
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || isset($decoded['keys']) === false || is_array($decoded['keys']) === false) {
			$this->logger->info($this->profile->logLabel . ': JWKS response is not a valid JWKS document', ['registrationUuid' => $ownerUuid]);
			return null;
		}

		return $decoded;

	}//end fetchJwks()

	/**
	 * GET a document through an ad-hoc source and answer its body on a 200.
	 *
	 * @param array<string,mixed> $parts     The parsed address.
	 * @param string              $ownerUuid The owner.
	 *
	 * @return string|null The body, or null on any failure.
	 */
	private function fetchBody(array $parts, string $ownerUuid): ?string {
		try {
			// The source is ad hoc and never persisted, so its uuid is not a
			// uuid; persistLog false keeps the fetch out of the call log.
			$callLog = $this->callService->call(
				source: $this->adHocSource(parts: $parts, ownerUuid: $ownerUuid),
				endpoint: $this->endpoint(parts: $parts),
				method: 'GET',
				read: true,
				persistLog: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				$this->profile->logLabel . ': outbound JWKS fetch failed',
				['registrationUuid' => $ownerUuid, 'error' => $exception->getMessage()]
			);
			return null;
		}

		$logData = $callLog->getObject();
		$statusCode = ($logData['response']['statusCode'] ?? $logData['statusCode'] ?? 0);
		if ($statusCode !== 200) {
			$this->logger->info(
				$this->profile->logLabel . ': JWKS fetch returned non-200',
				['registrationUuid' => $ownerUuid, 'statusCode' => $statusCode]
			);
			return null;
		}

		$response = ($logData['response'] ?? []);
		$body = ($response['body'] ?? null);
		if (is_string($body) === false || $body === '') {
			return null;
		}

		if (($response['encoding'] ?? 'UTF-8') === 'base64') {
			$body = base64_decode($body);
		}

		return $body;

	}//end fetchBody()

	/**
	 * The ephemeral source for one owner's fetch.
	 *
	 * @param array<string,mixed> $parts     The parsed address.
	 * @param string              $ownerUuid The owner.
	 *
	 * @return ObjectEntity The source.
	 */
	private function adHocSource(array $parts, string $ownerUuid): ObjectEntity {
		$location = $parts['scheme'] . '://' . $parts['host'];
		if (isset($parts['port']) === true) {
			$location .= ':' . $parts['port'];
		}

		$source = new ObjectEntity();
		$source->setUuid($this->profile->sourceUuidPrefix . $ownerUuid);
		$source->setObject(
			[
				'name' => $this->profile->sourceName,
				'isEnabled' => true,
				'location' => $location,
			]
		);

		return $source;

	}//end adHocSource()

	/**
	 * The path and query of the address.
	 *
	 * @param array<string,mixed> $parts The parsed address.
	 *
	 * @return string The endpoint.
	 */
	private function endpoint(array $parts): string {
		$endpoint = ($parts['path'] ?? '');
		if (isset($parts['query']) === true) {
			$endpoint .= '?' . $parts['query'];
		}

		return $endpoint;

	}//end endpoint()
}//end class
