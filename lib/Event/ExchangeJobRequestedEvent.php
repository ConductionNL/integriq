<?php

/**
 * Integriq Exchange Job Requested Event.
 *
 * The typed command an app dispatches to have integriq carry one of its data
 * exchange jobs.
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
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Carry this exchange job for me", with a slot for the answer.
 *
 * ADR-041: a typed command with a synchronous result slot. The constructor and
 * the getters are the contract (contract.md); changing them breaks the
 * consuming apps. A `history` block turns the request into a migration of a
 * finished job, which is stored disabled and never runs.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 */
class ExchangeJobRequestedEvent extends Event {

	/**
	 * The job integriq created or found, once it took the request.
	 *
	 * @var string|null
	 */
	private ?string $jobId = null;

	/**
	 * Why the request was not taken, when it was not.
	 *
	 * @var array{code: string, reason: string}|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string            $ownerApp    The asking app's id.
	 * @param string            $target      The exchange target id.
	 * @param string            $direction   export, import or sync.
	 * @param string            $ownerRef    The owning app's opaque reference.
	 * @param array<string,mixed> $scope     Selectors and target parameters, never personal data.
	 * @param string|null       $mappingSlug The mapping row to apply, or null for none.
	 * @param string            $requestedBy The requesting user's id.
	 * @param string            $name        A label for the job list.
	 * @param array<string,mixed>|null $history Migration only: the finished job's history.
	 */
	public function __construct(
		private readonly string $ownerApp,
		private readonly string $target,
		private readonly string $direction,
		private readonly string $ownerRef = '',
		private readonly array $scope = [],
		private readonly ?string $mappingSlug = null,
		private readonly string $requestedBy = '',
		private readonly string $name = '',
		private readonly ?array $history = null,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The asking app.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getOwnerApp(): string {
		return $this->ownerApp;

	}//end getOwnerApp()

	/**
	 * The exchange target.
	 *
	 * @return string The target id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getTarget(): string {
		return $this->target;

	}//end getTarget()

	/**
	 * The direction.
	 *
	 * @return string export, import or sync.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getDirection(): string {
		return $this->direction;

	}//end getDirection()

	/**
	 * The owning app's reference for what the job is about.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getOwnerRef(): string {
		return $this->ownerRef;

	}//end getOwnerRef()

	/**
	 * Selectors and target parameters.
	 *
	 * @return array<string,mixed> The scope.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getScope(): array {
		return $this->scope;

	}//end getScope()

	/**
	 * The mapping row to apply.
	 *
	 * @return string|null The mapping slug.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-004-the-jobs-mapping-transforms-each-allowed-record
	 */
	public function getMappingSlug(): ?string {
		return $this->mappingSlug;

	}//end getMappingSlug()

	/**
	 * Who requested the job.
	 *
	 * @return string The user id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getRequestedBy(): string {
		return $this->requestedBy;

	}//end getRequestedBy()

	/**
	 * The job's label.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getName(): string {
		return $this->name;

	}//end getName()

	/**
	 * The migrated job's history, or null for a new job.
	 *
	 * @return array<string,mixed>|null The history.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function getHistory(): ?array {
		return $this->history;

	}//end getHistory()

	/**
	 * The job integriq created or found.
	 *
	 * @return string|null The job id.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getJobId(): ?string {
		return $this->jobId;

	}//end getJobId()

	/**
	 * Record the job integriq created or found.
	 *
	 * @param string $jobId The job id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function setJobId(string $jobId): void {
		$this->jobId = $jobId;

	}//end setJobId()

	/**
	 * The structured refusal, or null when the request was taken.
	 *
	 * @return array{code: string, reason: string}|null The refusal.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function getRefusal(): ?array {
		return $this->refusal;

	}//end getRefusal()

	/**
	 * Refuse the request, saying why.
	 *
	 * @param string $code   A machine-readable code (contract.md).
	 * @param string $reason What an operator can act on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function refuse(string $code, string $reason): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];

	}//end refuse()

	/**
	 * Whether integriq answered the request either way.
	 *
	 * @return bool True once a job id or a refusal is set.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function isHandled(): bool {
		return ($this->jobId !== null || $this->refusal !== null);

	}//end isHandled()
}//end class
