<?php

/**
 * Integriq Exchange Job Concluded Event.
 *
 * Raised when an exchange job reaches a terminal state, so the owning app can
 * project the outcome onto its own records.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Your exchange job ended like this."
 *
 * ADR-041 concluded event. A listener MUST filter on getOwnerApp() and keep
 * its side effect idempotent: an administrator can re-run a job.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
 */
class ExchangeJobConcludedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string                   $ownerApp     The owning app's id.
	 * @param string                   $jobId        The job's uuid.
	 * @param string                   $target       The exchange target id.
	 * @param string                   $direction    export, import or sync.
	 * @param string                   $ownerRef     The owning app's reference.
	 * @param string                   $status       succeeded, partial, failed or refused.
	 * @param array<string,mixed>      $result       The run's counts.
	 * @param array<string,mixed>|null $gateDecision The gate's answer, when it was asked.
	 * @param string|null              $errorMessage Why the job failed as a whole, when it did.
	 */
	public function __construct(
		private readonly string $ownerApp,
		private readonly string $jobId,
		private readonly string $target,
		private readonly string $direction,
		private readonly string $ownerRef,
		private readonly string $status,
		private readonly array $result = [],
		private readonly ?array $gateDecision = null,
		private readonly ?string $errorMessage = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The owning app.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getOwnerApp(): string {
		return $this->ownerApp;

	}//end getOwnerApp()

	/**
	 * The job.
	 *
	 * @return string The job uuid.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getJobId(): string {
		return $this->jobId;

	}//end getJobId()

	/**
	 * The exchange target.
	 *
	 * @return string The target id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getTarget(): string {
		return $this->target;

	}//end getTarget()

	/**
	 * The direction.
	 *
	 * @return string export, import or sync.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getDirection(): string {
		return $this->direction;

	}//end getDirection()

	/**
	 * The owning app's reference.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getOwnerRef(): string {
		return $this->ownerRef;

	}//end getOwnerRef()

	/**
	 * The terminal status.
	 *
	 * @return string succeeded, partial, failed or refused.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getStatus(): string {
		return $this->status;

	}//end getStatus()

	/**
	 * The run's counts.
	 *
	 * @return array<string,mixed> {recordsProcessed, recordsAccepted, recordsRejected, runId, artefactRef}.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getResult(): array {
		return $this->result;

	}//end getResult()

	/**
	 * The gate's answer.
	 *
	 * @return array<string,mixed>|null The decision, or null when the gate was not asked.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getGateDecision(): ?array {
		return $this->gateDecision;

	}//end getGateDecision()

	/**
	 * Why the job failed as a whole.
	 *
	 * @return string|null The message, or null.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-009-a-terminal-job-raises-a-concluded-event
	 */
	public function getErrorMessage(): ?string {
		return $this->errorMessage;

	}//end getErrorMessage()
}//end class
