<?php

/**
 * Integriq LtiJwksResolverService.
 *
 * Resolves an external LTI Platform/Tool `jwks_uri` to a `JWKSet` and looks
 * up a presented token's `kid`, with per-registration caching and a
 * rate-limited refetch guard (SSRF-amplification defence — design.md D4).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetch-and-cache JWKS resolution for LTI launch/service-token signature
 * verification.
 *
 * The outbound fetch goes through the existing {@see CallService} HTTP call
 * machinery (inheriting timeout/CallLog observability) against an ephemeral,
 * never-persisted `ObjectEntity` `Source` — no `Source` row is created per
 * registration (design.md D4: "that would leak into the Bronnen list for
 * something that isn't a wired connection").
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 */
class LtiJwksResolverService {

	/**
	 * Default cache TTL for a resolved JWKS document (1 hour).
	 *
	 * @var integer
	 */
	public const DEFAULT_TTL_SECONDS = 3600;

	/**
	 * Minimum interval between refetches for the same registration when a
	 * presented `kid` is not found in the cached set (60s — design.md D4).
	 *
	 * @var integer
	 */
	public const REFETCH_GUARD_SECONDS = 60;

	/**
	 * Distributed cache used for resolved JWKS documents + the refetch guard.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Cache factory (same distributed-cache mechanism as jti replay).
	 * @param CallService $callService Outbound HTTP call engine (timeout/CallLog observability).
	 * @param LoggerInterface $logger Logger for resolution failures.
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('integriq.lti.jwks');

	}//end __construct()

	/**
	 * Cache key for a registration's resolved JWKS document.
	 *
	 * Namespaced by registration id — not by the raw `jwks_uri` string — so
	 * two registrations sharing a `jwks_uri` cannot cross-poison each
	 * other's cache entry (design.md D4).
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return string
	 */
	private function cacheKey(string $registrationType, string $registrationUuid): string {
		return 'jwks:' . $registrationType . ':' . $registrationUuid;
	}//end cacheKey()

	/**
	 * Cache key for the per-registration refetch guard.
	 *
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return string
	 */
	private function refetchGuardKey(string $registrationUuid): string {
		return 'jwks:refetch:' . $registrationUuid;
	}//end refetchGuardKey()

	/**
	 * Resolve a `kid` for a registration's `jwks_uri`, using the cache and
	 * refetching (guarded) on a miss.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 * @param string $jwksUri The external JWKS URI to resolve.
	 * @param string $kid The `kid` from the presented token's header.
	 *
	 * @return JWK|null The resolved key, or null when it cannot be resolved
	 *                  (unknown after refetch, or the refetch guard is active).
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
	 */
	public function resolveKey(string $registrationType, string $registrationUuid, string $jwksUri, string $kid): ?JWK {
		return $this->resolveLookup(
			lookup: new JwksKeyLookup(registrationType: $registrationType, registrationUuid: $registrationUuid, jwksUri: $jwksUri, kid: $kid)
		);
	}//end resolveKey()

	/**
	 * Resolve a lookup's kid: from the cached set, else by one guarded refetch.
	 *
	 * @param JwksKeyLookup $lookup The key to resolve.
	 *
	 * @return JWK|null The resolved key, or null when it cannot be resolved.
	 */
	private function resolveLookup(JwksKeyLookup $lookup): ?JWK {
		$cacheKey = $this->cacheKey(registrationType: $lookup->registrationType, registrationUuid: $lookup->registrationUuid);

		$cachedKey = $this->keyFromCachedSet(cacheKey: $cacheKey, kid: $lookup->kid);
		if ($cachedKey !== null) {
			return $cachedKey;
		}

		// Cache miss OR the cached set does not contain this kid — refetch,
		// but never more than once per REFETCH_GUARD_SECONDS per registration.
		if ($this->claimRefetch(lookup: $lookup) === false) {
			return null;
		}

		return $this->refetchKey(lookup: $lookup, cacheKey: $cacheKey);

	}//end resolveLookup()

	/**
	 * The kid from the cached JWKS, when a set is cached and holds it.
	 *
	 * @param string $cacheKey The registration's JWKS cache key.
	 * @param string $kid      The kid to find.
	 *
	 * @return JWK|null The cached key, or null on a miss.
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
	 * Claim the registration's refetch slot; refused while the guard window is active.
	 *
	 * @param JwksKeyLookup $lookup The key being resolved.
	 *
	 * @return boolean True when a refetch may run (the guard is now set), false when the guard is active.
	 */
	private function claimRefetch(JwksKeyLookup $lookup): bool {
		$guardKey = $this->refetchGuardKey(registrationUuid: $lookup->registrationUuid);
		if ($this->cache->get($guardKey) !== null) {
			// Guard active: fail closed. Never fall back to accepting an
			// unverified token (design.md D4 — SSRF-amplification defence).
			$this->logger->info(
				'LtiJwksResolverService: refetch guard active, rejecting unresolved kid',
				['registrationType' => $lookup->registrationType, 'registrationUuid' => $lookup->registrationUuid, 'kid' => $lookup->kid]
			);
			return false;
		}

		$this->cache->set($guardKey, true, self::REFETCH_GUARD_SECONDS);

		return true;

	}//end claimRefetch()

	/**
	 * Fetch the registration's JWKS, cache it, and pick the kid from it.
	 *
	 * @param JwksKeyLookup $lookup   The key being resolved.
	 * @param string        $cacheKey The registration's JWKS cache key.
	 *
	 * @return JWK|null The key, or null when the fetch failed or the set lacks the kid.
	 */
	private function refetchKey(JwksKeyLookup $lookup, string $cacheKey): ?JWK {
		$fetched = $this->fetchJwks(jwksUri: $lookup->jwksUri, registrationUuid: $lookup->registrationUuid);
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
	 * Deserialize a cached JWKS JSON string into a `JWKSet`.
	 *
	 * @param string $cached The cached JSON payload.
	 *
	 * @return JWKSet|null Null on malformed cache content (fail closed, never throw into a launch).
	 */
	private function deserializeJwkSet(string $cached): ?JWKSet {
		try {
			return JWKSet::createFromJson($cached);
		} catch (Throwable $exception) {
			$this->logger->warning('LtiJwksResolverService: malformed cached JWKS, treating as miss', ['error' => $exception->getMessage()]);
			return null;
		}

	}//end deserializeJwkSet()

	/**
	 * Fetch a `jwks_uri` via the existing outbound call machinery.
	 *
	 * Uses an ephemeral, non-persisted `ObjectEntity` `Source` so the fetch
	 * inherits `CallService`'s timeout/retry/CallLog observability without
	 * creating a real `Source` row (design.md D4).
	 *
	 * @param string $jwksUri The JWKS URI to fetch.
	 * @param string $registrationUuid The registration id (for CallLog traceability only).
	 *
	 * @return array|null The decoded `{"keys": [...]}` JWKS document, or null on any failure.
	 */
	private function fetchJwks(string $jwksUri, string $registrationUuid): ?array {
		$parts = parse_url($jwksUri);
		if ($parts === false || isset($parts['scheme'], $parts['host']) === false) {
			$this->logger->warning('LtiJwksResolverService: invalid jwks_uri', ['registrationUuid' => $registrationUuid]);
			return null;
		}

		$logData = $this->callJwksEndpoint(parts: $parts, registrationUuid: $registrationUuid);
		if ($logData === null) {
			return null;
		}

		return $this->decodeJwksResponse(logData: $logData, registrationUuid: $registrationUuid);
	}//end fetchJwks()

	/**
	 * Call the JWKS endpoint through an ephemeral, non-persisted source.
	 *
	 * @param array  $parts            The parsed `jwks_uri` (scheme and host present).
	 * @param string $registrationUuid The registration id (for traceability only).
	 *
	 * @return array|null The call log object, or null when the call failed.
	 */
	private function callJwksEndpoint(array $parts, string $registrationUuid): ?array {
		$source = $this->adHocJwksSource(parts: $parts, registrationUuid: $registrationUuid);

		try {
			// The source is ad hoc and never persisted, so its uuid is not a uuid.
			// A persisted call log would name it in `call_log.source` (format: uuid)
			// and the register refuses that row, which failed every JWKS fetch after
			// the key set had already arrived. The fetch is therefore not logged as
			// a call log: persistLog false returns the response in memory and
			// writes nothing, neither the call log nor the source's rate-limit state.
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $this->jwksEndpoint(parts: $parts),
				method: 'GET',
				read: true,
				persistLog: false
			);
		} catch (Throwable $exception) {
			$this->logFetchFailure(registrationUuid: $registrationUuid, exception: $exception);
			return null;
		}

		return $callLog->getObject();

	}//end callJwksEndpoint()

	/**
	 * The ephemeral source whose location is the `jwks_uri`'s scheme, host and port.
	 *
	 * @param array  $parts            The parsed `jwks_uri`.
	 * @param string $registrationUuid The registration id.
	 *
	 * @return ObjectEntity The ad-hoc source, never persisted.
	 */
	private function adHocJwksSource(array $parts, string $registrationUuid): ObjectEntity {
		$location = $parts['scheme'] . '://' . $parts['host'];
		if (isset($parts['port']) === true) {
			$location .= ':' . $parts['port'];
		}

		$source = new ObjectEntity();
		$source->setUuid('lti-jwks-adhoc-' . $registrationUuid);
		$source->setObject(
			[
				'name' => 'LTI JWKS resolver (ad-hoc, not persisted)',
				'isEnabled' => true,
				'location' => $location,
			]
		);

		return $source;

	}//end adHocJwksSource()

	/**
	 * The `jwks_uri`'s path and query, the endpoint called on the ad-hoc source.
	 *
	 * @param array $parts The parsed `jwks_uri`.
	 *
	 * @return string The endpoint.
	 */
	private function jwksEndpoint(array $parts): string {
		$endpoint = ($parts['path'] ?? '');
		if (isset($parts['query']) === true) {
			$endpoint .= '?' . $parts['query'];
		}

		return $endpoint;

	}//end jwksEndpoint()

	/**
	 * Log a failed outbound JWKS fetch.
	 *
	 * @param string    $registrationUuid The registration id.
	 * @param Throwable $exception        The failure.
	 *
	 * @return void
	 */
	private function logFetchFailure(string $registrationUuid, Throwable $exception): void {
		$this->logger->warning(
			'LtiJwksResolverService: outbound JWKS fetch failed',
			['registrationUuid' => $registrationUuid, 'error' => $exception->getMessage()]
		);

	}//end logFetchFailure()

	/**
	 * Decode a fetched JWKS response: a 200 with a `{"keys": [...]}` JSON body.
	 *
	 * @param array  $logData          The call log object.
	 * @param string $registrationUuid The registration id.
	 *
	 * @return array|null The decoded JWKS document, or null when the response is refused.
	 */
	private function decodeJwksResponse(array $logData, string $registrationUuid): ?array {
		$statusCode = ($logData['response']['statusCode'] ?? $logData['statusCode'] ?? 0);
		if ($statusCode !== 200) {
			$this->logger->info(
				'LtiJwksResolverService: JWKS fetch returned non-200',
				['registrationUuid' => $registrationUuid, 'statusCode' => $statusCode]
			);
			return null;
		}

		$body = $this->responseBody(response: ($logData['response'] ?? []));
		if ($body === null) {
			return null;
		}

		return $this->decodeJwksDocument(body: $body, registrationUuid: $registrationUuid);

	}//end decodeJwksResponse()

	/**
	 * The response body, base64-decoded when so encoded.
	 *
	 * @param mixed $response The call log's `response` member.
	 *
	 * @return string|null The body, or null when it is missing, not a string or empty.
	 */
	private function responseBody(mixed $response): ?string {
		$body = ($response['body'] ?? null);
		if (is_string($body) === false || $body === '') {
			return null;
		}

		if (($response['encoding'] ?? 'UTF-8') === 'base64') {
			$body = base64_decode($body);
		}

		return $body;

	}//end responseBody()

	/**
	 * Decode a JWKS body; it must be a JSON object with a `keys` array.
	 *
	 * @param string $body             The response body.
	 * @param string $registrationUuid The registration id.
	 *
	 * @return array|null The decoded document, or null when it is not a JWKS document.
	 */
	private function decodeJwksDocument(string $body, string $registrationUuid): ?array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || isset($decoded['keys']) === false || is_array($decoded['keys']) === false) {
			$this->logger->info('LtiJwksResolverService: JWKS response is not a valid JWKS document', ['registrationUuid' => $registrationUuid]);
			return null;
		}

		return $decoded;

	}//end decodeJwksDocument()
}//end class
