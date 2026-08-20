<?php

/**
 * OpenConnector SynchronizationDeletionGuarded Event.
 *
 * Dispatched by SynchronizationService::deleteInvalidObjects() whenever a
 * cleanup pass is aborted by a safety guard (an incomplete preceding fetch,
 * or a deletion ratio above the configured threshold) instead of proceeding
 * with the garbage-collection delete.
 *
 * @category Event
 * @package  OCA\OpenConnector\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Event;

use OCP\EventDispatcher\Event;

/**
 * Event fired when SynchronizationService::deleteInvalidObjects() aborts a
 * garbage-collection pass instead of deleting.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
 */
class SynchronizationDeletionGuardedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $synchronizationId The OpenRegister id/uuid of the synchronization whose
	 *                                  cleanup pass was guarded.
	 * @param string $reason Why the pass was guarded: `fetch_incomplete` or
	 *                       `ratio_threshold_exceeded`.
	 * @param float|null $ratio The computed deletion ratio (candidates ÷
	 *                          total contracts). Null for `fetch_incomplete`,
	 *                          where no ratio was computed.
	 * @param float|null $threshold The configured/default deletion-ratio threshold that
	 *                              was exceeded. Null for `fetch_incomplete`.
	 * @param integer|null $candidateCount The number of contracts that would have been deleted.
	 *                                     Null for `fetch_incomplete`.
	 * @param integer|null $totalContracts The total number of existing contracts for the
	 *                                     synchronization. Null for `fetch_incomplete`.
	 */
	public function __construct(
		private readonly string $synchronizationId,
		private readonly string $reason,
		private readonly ?float $ratio = null,
		private readonly ?float $threshold = null,
		private readonly ?int $candidateCount = null,
		private readonly ?int $totalContracts = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Get the guarded synchronization's id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public function getSynchronizationId(): string {
		return $this->synchronizationId;
	}//end getSynchronizationId()

	/**
	 * Get the reason the cleanup pass was guarded.
	 *
	 * @return string `fetch_incomplete` or `ratio_threshold_exceeded`.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * Get the computed deletion ratio, when applicable.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public function getRatio(): ?float {
		return $this->ratio;
	}//end getRatio()

	/**
	 * Get the configured/default deletion-ratio threshold, when applicable.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public function getThreshold(): ?float {
		return $this->threshold;
	}//end getThreshold()

	/**
	 * Get the number of contracts that would have been deleted, when applicable.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public function getCandidateCount(): ?int {
		return $this->candidateCount;
	}//end getCandidateCount()

	/**
	 * Get the total number of existing contracts for the synchronization, when applicable.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public function getTotalContracts(): ?int {
		return $this->totalContracts;
	}//end getTotalContracts()
}//end class
