<?php

/**
 * Integriq Exchange Gate Requested Event.
 *
 * Integriq asks the app that owns an exchange job whether the job may run,
 * and which records may leave.
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
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "May job X run, and what may leave?", answered by the owning app.
 *
 * The in-process binding of the gate contract (contract.md). The owning app
 * serves the same decision at `GET /apps/<app>/api/exchange-gates/{jobId}`
 * for people; integriq only ever asks through this event (ADR-041), because a
 * scheduled run has no session to make an HTTP call with.
 *
 * Exactly one answer counts: the first `allow()` or `refuse()` wins, so a
 * second listener cannot flip a refusal into a permission.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 */
class ExchangeGateRequestedEvent extends Event {

	/**
	 * Whether an answer was given.
	 *
	 * @var bool
	 */
	private bool $answered = false;

	/**
	 * Whether the answer was allow.
	 *
	 * @var bool
	 */
	private bool $allowed = false;

	/**
	 * The records that may leave, on allow.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $records = [];

	/**
	 * The refusal, on refuse.
	 *
	 * @var array{code: string, reason: string}|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string              $jobId     The exchange job's uuid.
	 * @param string              $ownerApp  The owning app's id.
	 * @param string              $target    The exchange target id.
	 * @param string              $direction export, import or sync.
	 * @param string              $ownerRef  The owning app's reference.
	 * @param array<string,mixed> $scope     The job's selectors and parameters.
	 */
	public function __construct(
		private readonly string $jobId,
		private readonly string $ownerApp,
		private readonly string $target,
		private readonly string $direction,
		private readonly string $ownerRef = '',
		private readonly array $scope = [],
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The job being gated.
	 *
	 * @return string The job uuid.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getJobId(): string {
		return $this->jobId;

	}//end getJobId()

	/**
	 * The owning app a listener must match before answering.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getOwnerApp(): string {
		return $this->ownerApp;

	}//end getOwnerApp()

	/**
	 * The exchange target.
	 *
	 * @return string The target id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getTarget(): string {
		return $this->target;

	}//end getTarget()

	/**
	 * The direction.
	 *
	 * @return string export, import or sync.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getDirection(): string {
		return $this->direction;

	}//end getDirection()

	/**
	 * The owning app's reference for what the job is about.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getOwnerRef(): string {
		return $this->ownerRef;

	}//end getOwnerRef()

	/**
	 * The job's selectors and target parameters.
	 *
	 * @return array<string,mixed> The scope.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getScope(): array {
		return $this->scope;

	}//end getScope()

	/**
	 * Let the job run, handing over what may leave.
	 *
	 * Each record is `{recordId: string, sourceKind: string, data: array}`.
	 * Integriq maps and sends them in this process and never stores them.
	 *
	 * @param array<int, array<string, mixed>> $records The records that may leave.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function allow(array $records = []): void {
		if ($this->answered === true) {
			return;
		}

		$this->answered = true;
		$this->allowed = true;
		$this->records = array_values($records);

	}//end allow()

	/**
	 * Stop the job, saying why.
	 *
	 * @param string $code   A machine-readable code of the owning app.
	 * @param string $reason A full sentence people can act on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function refuse(string $code, string $reason): void {
		if ($this->answered === true) {
			return;
		}

		$this->answered = true;
		$this->allowed = false;
		$this->refusal = ['code' => $code, 'reason' => $reason];

	}//end refuse()

	/**
	 * Whether any listener answered.
	 *
	 * @return bool True once allow() or refuse() ran.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function isAnswered(): bool {
		return $this->answered;

	}//end isAnswered()

	/**
	 * Whether the answer was allow.
	 *
	 * @return bool True on allow.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function isAllowed(): bool {
		return $this->allowed;

	}//end isAllowed()

	/**
	 * The records that may leave.
	 *
	 * @return array<int, array<string, mixed>> The records, empty unless allowed.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getRecords(): array {
		return $this->records;

	}//end getRecords()

	/**
	 * The refusal.
	 *
	 * @return array{code: string, reason: string}|null The refusal, or null.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function getRefusal(): ?array {
		return $this->refusal;

	}//end getRefusal()
}//end class
