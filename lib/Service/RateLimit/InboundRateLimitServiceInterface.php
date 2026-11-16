<?php

/**
 * Contract of the InboundRateLimitService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\RateLimit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\RateLimit;

/**
 * Public operations of {@see InboundRateLimitService} that other components call.
 */
interface InboundRateLimitServiceInterface {
	/**
	 * Evaluate a request against the consumer's rateLimit and quota.
	 *
	 * Ordering (D2): the short-window rate limit is checked first; if it
	 * rejects, the quota is NOT incremented (the request never ran). A request
	 * that passes the window is then counted against the quota.
	 *
	 * @param string $consumerKey Stable per-consumer key (consumer uuid, or client IP for anonymous).
	 * @param array|null $rateLimit `{requestsPerWindow:int, windowSeconds:int}` or null (unlimited).
	 * @param array|null $quota `{limit:int, period:"hour"|"day"|"month"}` or null (unlimited).
	 *
	 * @return RateLimitDecision The decision, including header values.
	 *
	 * @spec openspec/specs/consumer-management/spec.md — Requirement: Inbound rate-limit enforcement after authentication (REQ-CON-RL-002)
	 */
	public function enforce(string $consumerKey, ?array $rateLimit, ?array $quota): RateLimitDecision;
}
