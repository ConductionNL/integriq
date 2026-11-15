<?php

/**
 * Integriq rate-limit check.
 *
 * One inbound request's limits as InboundRateLimitService checks them: the
 * consumer the counters are kept for, its short-window burst limit and its
 * longer-horizon quota, each with whether it is configured at all.
 *
 * @category Service
 * @package  OCA\Integriq\Service\RateLimit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/consumer-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\RateLimit;

/**
 * Check one request against a consumer's limits.
 *
 * @spec openspec/specs/consumer-management/spec.md
 */
final class RateLimitCheck {

	/**
	 * Constructor.
	 *
	 * @param string     $consumerKey The consumer the counters are kept for.
	 * @param array|null $rateLimit   `{requestsPerWindow, windowSeconds}`, or null.
	 * @param array|null $quota       `{limit, period}`, or null.
	 */
	public function __construct(
		public readonly string $consumerKey,
		public readonly ?array $rateLimit,
		public readonly ?array $quota,
	) {

	}//end __construct()

	/**
	 * Whether a burst limit is configured: a positive request count and window.
	 *
	 * @return bool True when configured.
	 *
	 * @spec openspec/specs/consumer-management/spec.md
	 */
	public function hasRateLimit(): bool {
		return is_array($this->rateLimit) === true
			&& (int)($this->rateLimit['requestsPerWindow'] ?? 0) > 0
			&& (int)($this->rateLimit['windowSeconds'] ?? 0) > 0;

	}//end hasRateLimit()

	/**
	 * Whether a quota is configured: a positive limit over an hour, a day or a month.
	 *
	 * @return bool True when configured.
	 *
	 * @spec openspec/specs/consumer-management/spec.md
	 */
	public function hasQuota(): bool {
		return is_array($this->quota) === true
			&& (int)($this->quota['limit'] ?? 0) > 0
			&& in_array(($this->quota['period'] ?? ''), ['hour', 'day', 'month'], true) === true;

	}//end hasQuota()

	/**
	 * The burst limit's request count, or null when none is configured.
	 *
	 * @return int|null The limit.
	 *
	 * @spec openspec/specs/consumer-management/spec.md
	 */
	public function windowLimit(): ?int {
		if ($this->hasRateLimit() === false) {
			return null;
		}

		return (int)$this->rateLimit['requestsPerWindow'];

	}//end windowLimit()
}//end class
