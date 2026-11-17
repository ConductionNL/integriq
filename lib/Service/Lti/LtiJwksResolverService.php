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
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Jwks\JwksLookup;
use OCA\Integriq\Service\Jwks\JwksResolver;
use OCA\Integriq\Service\Jwks\JwksResolverProfile;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

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
 * The resolution itself lives in {@see JwksResolver}, shared with the gateway
 * consumer path; this class keeps the LTI cache namespace (`integriq.lti.jwks`),
 * cache keys, log lines and ad-hoc source identity unchanged.
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-external-jwks-resolution-with-kid-lookup-per-registration-caching-and-rate-limited-refetch-req-lti-003
 */
class LtiJwksResolverService {

	/**
	 * Default cache TTL for a resolved JWKS document (1 hour).
	 *
	 * @var integer
	 */
	public const DEFAULT_TTL_SECONDS = JwksResolver::DEFAULT_TTL_SECONDS;

	/**
	 * Minimum interval between refetches for the same registration when a
	 * presented `kid` is not in the cached set.
	 *
	 * @var integer
	 */
	public const REFETCH_GUARD_SECONDS = JwksResolver::REFETCH_GUARD_SECONDS;

	/**
	 * The distributed cache namespace of LTI key sets.
	 *
	 * @var string
	 */
	public const CACHE_NAMESPACE = 'integriq.lti.jwks';

	/**
	 * The shared resolver, bound to the LTI cache namespace and log label.
	 *
	 * @var JwksResolver
	 */
	private readonly JwksResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory   $cacheFactory Cache factory for the distributed JWKS cache.
	 * @param CallService     $callService  Outbound HTTP call machinery.
	 * @param LoggerInterface $logger       Logger.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
	 */
	public function __construct(
		ICacheFactory $cacheFactory,
		CallService $callService,
		LoggerInterface $logger,
	) {
		$this->resolver = new JwksResolver(
			cache: $cacheFactory->createDistributed(self::CACHE_NAMESPACE),
			callService: $callService,
			logger: $logger,
			profile: new JwksResolverProfile(
				logLabel: 'LtiJwksResolverService',
				sourceUuidPrefix: 'lti-jwks-adhoc-',
				sourceName: 'LTI JWKS resolver (ad-hoc, not persisted)'
			)
		);

	}//end __construct()

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
	 * @spec openspec/changes/access-oauth-and-token-validation/tasks.md#task-1
	 */
	public function resolveKey(string $registrationType, string $registrationUuid, string $jwksUri, string $kid): ?JWK {
		return $this->resolver->resolveKey(
			lookup: new JwksLookup(ownerType: $registrationType, ownerUuid: $registrationUuid, jwksUri: $jwksUri, kid: $kid)
		);
	}//end resolveKey()
}//end class
