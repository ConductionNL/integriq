<?php

/**
 * OpenConnector — inbound rate-limit decision value object.
 *
 * The immutable outcome of an inbound rate-limit / quota evaluation for a
 * single request. Carries everything the endpoint response needs to emit the
 * IETF RateLimit headers and, on rejection, the 429 + Retry-After.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\RateLimit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\RateLimit;

/**
 * Immutable result of an inbound rate-limit + quota check.
 *
 * @spec openspec/specs/consumer-management/spec.md
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
final class RateLimitDecision
{

    /**
     * Reason for rejection when a short-window rate limit is exceeded.
     *
     * @var string
     */
    public const REASON_RATE_LIMIT = 'rate_limit';

    /**
     * Reason for rejection when a longer-horizon quota is exceeded.
     *
     * @var string
     */
    public const REASON_QUOTA = 'quota';

    /**
     * Constructor.
     *
     * @param bool        $allowed      Whether the request is permitted.
     * @param bool        $hasRateLimit Whether the consumer has a rateLimit configured (drives header emission).
     * @param int|null    $limit        RateLimit-Limit value (requests per window), or null when unlimited.
     * @param int|null    $remaining    RateLimit-Remaining value, or null when unlimited.
     * @param int|null    $resetSeconds RateLimit-Reset value (seconds until the window/period resets).
     * @param int|null    $retryAfter   Retry-After value in seconds (only set when rejected).
     * @param string|null $reason       Rejection reason (REASON_RATE_LIMIT|REASON_QUOTA), or null when allowed.
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $hasRateLimit=false,
        public readonly ?int $limit=null,
        public readonly ?int $remaining=null,
        public readonly ?int $resetSeconds=null,
        public readonly ?int $retryAfter=null,
        public readonly ?string $reason=null
    ) {
    }//end __construct()

    /**
     * Build the IETF RateLimit response headers for this decision.
     *
     * Emits `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` when a
     * rateLimit is configured, and `Retry-After` when the request was rejected.
     * Returns an empty array when the consumer has no rateLimit configured and
     * the request is allowed (unlimited consumers receive no headers).
     *
     * @return array<string, string> Header name => value.
     *
     * @spec openspec/specs/consumer-management/spec.md — Requirement: IETF RateLimit response headers (REQ-CON-RL-003)
     */
    public function toHeaders(): array
    {
        $headers = [];

        if ($this->hasRateLimit === true && $this->limit !== null) {
            $headers['RateLimit-Limit']     = (string) $this->limit;
            $headers['RateLimit-Remaining'] = (string) ($this->remaining ?? 0);
            $headers['RateLimit-Reset']     = (string) ($this->resetSeconds ?? 0);
        }

        if ($this->allowed === false && $this->retryAfter !== null) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return $headers;
    }//end toHeaders()
}//end class
