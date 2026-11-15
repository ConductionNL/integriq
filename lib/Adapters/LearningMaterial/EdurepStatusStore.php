<?php

/**
 * Integriq Edurep status store.
 *
 * Remembers when Edurep last answered, why it last failed and the last
 * health check, in the distributed cache. Search results are never cached.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Cached Edurep status.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */
class EdurepStatusStore {
	public const HEALTH_TTL = 300;
	private const STATUS_TTL = 604800;

	/**
	 * The cache.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ICacheFactory $cacheFactory Builds the distributed cache.
	 */
	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createDistributed('integriq.edurep');
	}//end __construct()

	/**
	 * Record that Edurep answered now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function markSuccess(): void {
		$this->cache->set('lastSuccess', gmdate(DATE_ATOM), self::STATUS_TTL);
	}//end markSuccess()

	/**
	 * Record why Edurep did not answer.
	 *
	 * @param string $reason The transport error or status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function markFailure(string $reason): void {
		$this->cache->set('lastFailure', ['at' => gmdate(DATE_ATOM), 'reason' => $reason], self::STATUS_TTL);
	}//end markFailure()

	/**
	 * When Edurep last answered.
	 *
	 * @return string|null ISO 8601 time, or null.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function lastSuccess(): ?string {
		$value = $this->cache->get('lastSuccess');
		if (is_string($value) === false) {
			return null;
		}

		return $value;
	}//end lastSuccess()

	/**
	 * The last failure.
	 *
	 * @return array{at: string, reason: string}|null The failure, or null.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function lastFailure(): ?array {
		$value = $this->cache->get('lastFailure');
		if (is_array($value) === false) {
			return null;
		}

		return ['at' => (string)($value['at'] ?? ''), 'reason' => (string)($value['reason'] ?? '')];
	}//end lastFailure()

	/**
	 * The health check result while it is fresh.
	 *
	 * @return array<string,mixed>|null The result, or null when none is younger than five minutes.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function health(): ?array {
		$value = $this->cache->get('health');
		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end health()

	/**
	 * Keep a health check result for five minutes.
	 *
	 * @param array<string,mixed> $health The result.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function rememberHealth(array $health): void {
		$this->cache->set('health', $health, self::HEALTH_TTL);
	}//end rememberHealth()
}//end class
