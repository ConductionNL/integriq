<?php

/**
 * Integriq Exchange Job Run Event.
 *
 * The job fields the events of a running exchange job share: the job uuid,
 * the owning app a listener must match before answering, the target, the
 * direction, the owning app's reference and the job's scope. The gate and
 * the records hand-over are each their own subclass, so a listener still
 * registers per event.
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
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * One running exchange job, as its events carry it.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
 */
abstract class ExchangeJobRunEvent extends Event {

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
	 * The exchange job.
	 *
	 * @return string The job uuid.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
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
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
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
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
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
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
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
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
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
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
	 */
	public function getScope(): array {
		return $this->scope;

	}//end getScope()
}//end class
