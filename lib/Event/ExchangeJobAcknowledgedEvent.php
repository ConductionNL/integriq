<?php

/**
 * Integriq Exchange Job Acknowledged Event.
 *
 * Raised when an authority's acknowledgement for one record of an exchange
 * job arrives, so the owning app can record the authority's answer.
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
 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "The authority answered this record of your exchange job."
 *
 * ADR-041 event for the owning app. It arrives after the job concluded
 * (ExchangeJobConcludedEvent), possibly days later, and once per record. A
 * listener MUST filter on getOwnerApp() and keep its side effect
 * idempotent: an authority can send the same retour twice. The getters are
 * the contract; changing one breaks the owning app.
 *
 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
 */
class ExchangeJobAcknowledgedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string $ownerApp     The owning app.
	 * @param string $jobId        The job the acknowledgement answers.
	 * @param string $recordId     The record the acknowledgement answers.
	 * @param string $target       The exchange target.
	 * @param bool   $accepted     Whether the authority accepted the record.
	 * @param string $signaalcode  The authority's signaalcode.
	 * @param string $description  The authority's description of the code.
	 * @param string $receivedAt   When integriq received the acknowledgement.
	 */
	public function __construct(
		private readonly string $ownerApp,
		private readonly string $jobId,
		private readonly string $recordId,
		private readonly string $target,
		private readonly bool $accepted,
		private readonly string $signaalcode,
		private readonly string $description,
		private readonly string $receivedAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The owning app.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function getOwnerApp(): string {
		return $this->ownerApp;
	}//end getOwnerApp()

	/**
	 * The job the acknowledgement answers.
	 *
	 * @return string The job uuid.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function getJobId(): string {
		return $this->jobId;
	}//end getJobId()

	/**
	 * The record the acknowledgement answers.
	 *
	 * @return string The record id the job sent.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function getRecordId(): string {
		return $this->recordId;
	}//end getRecordId()

	/**
	 * The exchange target.
	 *
	 * @return string The target id.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function getTarget(): string {
		return $this->target;
	}//end getTarget()

	/**
	 * Whether the authority accepted the record.
	 *
	 * @return bool True when accepted.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function isAccepted(): bool {
		return $this->accepted;
	}//end isAccepted()

	/**
	 * The authority's signaalcode.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function getSignaalcode(): string {
		return $this->signaalcode;
	}//end getSignaalcode()

	/**
	 * The authority's description of the code.
	 *
	 * @return string The description, empty when it gave none.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function getDescription(): string {
		return $this->description;
	}//end getDescription()

	/**
	 * When integriq received the acknowledgement.
	 *
	 * @return string An ISO 8601 timestamp.
	 *
	 * @spec openspec/changes/connectors-data-exchange-dispatch/specs/exchange-jobs/spec.md#requirement-req-013-the-authority-acknowledgement-for-a-record-is-reported-against-its-job
	 */
	public function getReceivedAt(): string {
		return $this->receivedAt;
	}//end getReceivedAt()
}//end class
