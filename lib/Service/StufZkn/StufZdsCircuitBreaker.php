<?php

/**
 * Integriq StUF-ZDS Circuit Breaker.
 *
 * Counts consecutive failures per StUF-ZKN source and opens the circuit after
 * four, so a case system that is down is left alone for five minutes instead
 * of being hammered by every case change and retry. A success closes it. The
 * state lives in app config under keys hashed from the source id, which keeps
 * them inside Nextcloud's 64-character key limit.
 *
 * Ported from dossiq's CircuitBreakerService. Dossiq raised a needs-input
 * notice when the circuit opened; here the opening is logged and the
 * caller learns of it through the CIRCUIT_OPEN fout of the next request.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use OCA\Integriq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Per-source circuit breaker for StUF-ZDS case messages.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */
class StufZdsCircuitBreaker {

	/**
	 * Consecutive failures that open the circuit.
	 *
	 * @var int
	 */
	public const THRESHOLD = 4;

	/**
	 * Seconds an open circuit stays open.
	 *
	 * @var int
	 */
	public const COOLDOWN_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig The app config holding the state.
	 * @param LoggerInterface $logger    The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the source may be called now. A source without an id is never broken.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return bool True when the circuit is closed (or its cooldown has passed).
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function allows(string $sourceId): bool {
		if ($sourceId === '') {
			return true;
		}

		$openedAt = $this->appConfig->getValueInt(AppIdentity::APP_ID, $this->openKey(sourceId: $sourceId), 0);
		if ($openedAt === 0) {
			return true;
		}

		if ((time() - $openedAt) >= self::COOLDOWN_SECONDS) {
			$this->logger->info('StUF-ZDS circuit closed for {source}: cooldown passed', ['source' => $sourceId]);
			$this->reset(sourceId: $sourceId);
			return true;
		}

		return false;
	}//end allows()

	/**
	 * Count a failure; open the circuit at the threshold.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return bool True when this failure opened the circuit.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function recordFailure(string $sourceId): bool {
		if ($sourceId === '') {
			return false;
		}

		$count = ($this->failureCount(sourceId: $sourceId) + 1);
		$this->appConfig->setValueInt(AppIdentity::APP_ID, $this->countKey(sourceId: $sourceId), $count);
		if ($count < self::THRESHOLD) {
			return false;
		}

		$this->appConfig->setValueInt(AppIdentity::APP_ID, $this->openKey(sourceId: $sourceId), time());
		$this->logger->warning('StUF-ZDS circuit opened for {source} after {count} failures', ['source' => $sourceId, 'count' => $count]);
		return true;
	}//end recordFailure()

	/**
	 * Close the circuit and clear the count (after a success).
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function reset(string $sourceId): void {
		if ($sourceId === '') {
			return;
		}

		$this->appConfig->setValueInt(AppIdentity::APP_ID, $this->countKey(sourceId: $sourceId), 0);
		$this->appConfig->deleteKey(AppIdentity::APP_ID, $this->openKey(sourceId: $sourceId));
	}//end reset()

	/**
	 * The breaker state of a source, for health views.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array{state:string,failureCount:int,openedAt:int}
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function snapshot(string $sourceId): array {
		$count    = $this->failureCount(sourceId: $sourceId);
		$openedAt = $this->appConfig->getValueInt(AppIdentity::APP_ID, $this->openKey(sourceId: $sourceId), 0);

		$state = 'ok';
		if ($count > 0) {
			$state = 'degraded';
		}

		if ($openedAt > 0 && (time() - $openedAt) < self::COOLDOWN_SECONDS) {
			$state = 'circuit_open';
		}

		return ['state' => $state, 'failureCount' => $count, 'openedAt' => $openedAt];
	}//end snapshot()

	/**
	 * The failure count of a source.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return int The count.
	 */
	private function failureCount(string $sourceId): int {
		return $this->appConfig->getValueInt(AppIdentity::APP_ID, $this->countKey(sourceId: $sourceId), 0);
	}//end failureCount()

	/**
	 * The failure-count key: `stuf.zds.c.` plus 32 hex characters (43 characters).
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return string The key.
	 */
	private function countKey(string $sourceId): string {
		return 'stuf.zds.c.' . substr(sha1($sourceId), 0, 32);
	}//end countKey()

	/**
	 * The opened-at key: `stuf.zds.o.` plus 32 hex characters (43 characters).
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return string The key.
	 */
	private function openKey(string $sourceId): string {
		return 'stuf.zds.o.' . substr(sha1($sourceId), 0, 32);
	}//end openKey()
}//end class
