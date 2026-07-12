<?php
/**
 * OpenConnector LtiJwksResolverService.
 *
 * Resolves an external LTI Platform/Tool `jwks_uri` to a `JWKSet` and looks
 * up a presented token's `kid`, with per-registration caching and a
 * rate-limited refetch guard (SSRF-amplification defence — design.md D4).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Lti;

use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use OCA\OpenConnector\Service\CallService;
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
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 */
class LtiJwksResolverService
{

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
     * @param ICacheFactory   $cacheFactory Cache factory (same distributed-cache mechanism as jti replay).
     * @param CallService     $callService  Outbound HTTP call engine (timeout/CallLog observability).
     * @param LoggerInterface $logger       Logger for resolution failures.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly CallService $callService,
        private readonly LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed('openconnector.lti.jwks');

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
    private function cacheKey(string $registrationType, string $registrationUuid): string
    {
        return 'jwks:'.$registrationType.':'.$registrationUuid;

    }//end cacheKey()

    /**
     * Cache key for the per-registration refetch guard.
     *
     * @param string $registrationUuid The registration's UUID.
     *
     * @return string
     */
    private function refetchGuardKey(string $registrationUuid): string
    {
        return 'jwks:refetch:'.$registrationUuid;

    }//end refetchGuardKey()

    /**
     * Resolve a `kid` for a registration's `jwks_uri`, using the cache and
     * refetching (guarded) on a miss.
     *
     * @param string $registrationType `lti_platform` or `lti_tool`.
     * @param string $registrationUuid The registration's UUID.
     * @param string $jwksUri          The external JWKS URI to resolve.
     * @param string $kid              The `kid` from the presented token's header.
     *
     * @return JWK|null The resolved key, or null when it cannot be resolved
     *                   (unknown after refetch, or the refetch guard is active).
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
     */
    public function resolveKey(string $registrationType, string $registrationUuid, string $jwksUri, string $kid): ?JWK
    {
        $cacheKey = $this->cacheKey(registrationType: $registrationType, registrationUuid: $registrationUuid);

        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) === true) {
            $jwkSet = $this->deserializeJwkSet(cached: $cached);
            if ($jwkSet !== null && $jwkSet->has($kid) === true) {
                return $jwkSet->get($kid);
            }
        }

        // Cache miss OR the cached set does not contain this kid — refetch,
        // but never more than once per REFETCH_GUARD_SECONDS per registration.
        $guardKey = $this->refetchGuardKey(registrationUuid: $registrationUuid);
        if ($this->cache->get($guardKey) !== null) {
            // Guard active: fail closed. Never fall back to accepting an
            // unverified token (design.md D4 — SSRF-amplification defence).
            $this->logger->info(
                'LtiJwksResolverService: refetch guard active, rejecting unresolved kid',
                ['registrationType' => $registrationType, 'registrationUuid' => $registrationUuid, 'kid' => $kid]
            );
            return null;
        }

        $this->cache->set($guardKey, true, self::REFETCH_GUARD_SECONDS);

        $fetched = $this->fetchJwks(jwksUri: $jwksUri, registrationUuid: $registrationUuid);
        if ($fetched === null) {
            return null;
        }

        $this->cache->set($cacheKey, json_encode($fetched), self::DEFAULT_TTL_SECONDS);

        $jwkSet = $this->deserializeJwkSet(cached: json_encode($fetched));
        if ($jwkSet !== null && $jwkSet->has($kid) === true) {
            return $jwkSet->get($kid);
        }

        return null;

    }//end resolveKey()

    /**
     * Deserialize a cached JWKS JSON string into a `JWKSet`.
     *
     * @param string $cached The cached JSON payload.
     *
     * @return JWKSet|null Null on malformed cache content (fail closed, never throw into a launch).
     */
    private function deserializeJwkSet(string $cached): ?JWKSet
    {
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
     * @param string $jwksUri          The JWKS URI to fetch.
     * @param string $registrationUuid The registration id (for CallLog traceability only).
     *
     * @return array|null The decoded `{"keys": [...]}` JWKS document, or null on any failure.
     */
    private function fetchJwks(string $jwksUri, string $registrationUuid): ?array
    {
        $parts = parse_url($jwksUri);
        if ($parts === false || isset($parts['scheme'], $parts['host']) === false) {
            $this->logger->warning('LtiJwksResolverService: invalid jwks_uri', ['registrationUuid' => $registrationUuid]);
            return null;
        }

        $location = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port']) === true) {
            $location .= ':'.$parts['port'];
        }

        $endpoint = ($parts['path'] ?? '');
        if (isset($parts['query']) === true) {
            $endpoint .= '?'.$parts['query'];
        }

        $source = new ObjectEntity();
        $source->setUuid('lti-jwks-adhoc-'.$registrationUuid);
        $source->setObject(
            [
                'name'      => 'LTI JWKS resolver (ad-hoc, not persisted)',
                'isEnabled' => true,
                'location'  => $location,
            ]
        );

        try {
            $callLog = $this->callService->call(
                source: $source,
                endpoint: $endpoint,
                method: 'GET',
                read: true
            );
        } catch (Throwable $exception) {
            $this->logger->warning(
                'LtiJwksResolverService: outbound JWKS fetch failed',
                ['registrationUuid' => $registrationUuid, 'error' => $exception->getMessage()]
            );
            return null;
        }

        $logData    = $callLog->getObject();
        $statusCode = ($logData['response']['statusCode'] ?? $logData['statusCode'] ?? 0);
        if ($statusCode !== 200) {
            $this->logger->info(
                'LtiJwksResolverService: JWKS fetch returned non-200',
                ['registrationUuid' => $registrationUuid, 'statusCode' => $statusCode]
            );
            return null;
        }

        $body = ($logData['response']['body'] ?? null);
        if (is_string($body) === false || $body === '') {
            return null;
        }

        if (($logData['response']['encoding'] ?? 'UTF-8') === 'base64') {
            $body = base64_decode($body);
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) === false || isset($decoded['keys']) === false || is_array($decoded['keys']) === false) {
            $this->logger->info('LtiJwksResolverService: JWKS response is not a valid JWKS document', ['registrationUuid' => $registrationUuid]);
            return null;
        }

        return $decoded;

    }//end fetchJwks()
}//end class
