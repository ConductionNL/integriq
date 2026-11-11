<?php

/**
 * Integriq Exchange Records Received Event.
 *
 * Integriq hands the records an import job received back to the app that
 * owns the job, and waits for that app's answer: how many it accepted and
 * which it rejected, with reason codes.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * "Here are the records job X received; which did you take?", answered by the owning app.
 *
 * The mirror of {@see ExchangeGateRequestedEvent}: the gate asks what may
 * leave, this event delivers what arrived. Exactly one answer counts: the
 * first `accept()` wins, so a second listener cannot rewrite the outcome.
 *
 * A rejection carries a record id, a reason code and optional field names,
 * never a value: rejections are stored as dead letters and shown to people.
 *
 * The job fields live in {@see ExchangeJobRunEvent}.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
 */
class ExchangeRecordsReceivedEvent extends ExchangeJobRunEvent {

	/**
	 * Whether an answer was given.
	 *
	 * @var bool
	 */
	private bool $answered = false;

	/**
	 * Accepted record count.
	 *
	 * @var int
	 */
	private int $acceptedCount = 0;

	/**
	 * Rejected records, normalised.
	 *
	 * @var array<int, array{recordId: string, sourceKind: string, errorCode: string, offendingFields: array<int, string>}>
	 */
	private array $rejected = [];

	/**
	 * Constructor.
	 *
	 * @param string                           $jobId     The exchange job's uuid.
	 * @param string                           $ownerApp  The owning app's id.
	 * @param string                           $target    The exchange target id.
	 * @param string                           $direction Always `import` today.
	 * @param string                           $ownerRef  The owning app's reference.
	 * @param array<string, mixed>             $scope     The job's selectors and parameters.
	 * @param array<int, array<string, mixed>> $records   The received records, each
	 *                                                    `{recordId, sourceKind, data}`.
	 */
	public function __construct(
		string $jobId,
		string $ownerApp,
		string $target,
		string $direction,
		string $ownerRef = '',
		array $scope = [],
		private readonly array $records = [],
	) {
		parent::__construct(jobId: $jobId, ownerApp: $ownerApp, target: $target, direction: $direction, ownerRef: $ownerRef, scope: $scope);

	}//end __construct()

	/**
	 * The received records, after the job's mapping row.
	 *
	 * @return array<int, array<string, mixed>> Each `{recordId, sourceKind, data}`.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
	 */
	public function getRecords(): array {
		return $this->records;

	}//end getRecords()

	/**
	 * Answer: how many records were taken, and which were rejected and why.
	 *
	 * Each rejection is `{recordId: string, errorCode: string, offendingFields?: string[]}`;
	 * `sourceKind` is copied from the received record. A rejection for an unknown
	 * record id, or without a code, is dropped. Never pass a value.
	 *
	 * @param int                              $acceptedCount The number of records taken.
	 * @param array<int, array<string, mixed>> $rejected      The rejected records.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-011-the-owning-apps-answer-ends-the-job
	 */
	public function accept(int $acceptedCount, array $rejected=[]): void {
		if ($this->answered === true) {
			return;
		}

		$kinds = [];
		foreach ($this->records as $record) {
			$kinds[(string) ($record['recordId'] ?? '')] = (string) ($record['sourceKind'] ?? '');
		}

		$clean = [];
		foreach ($rejected as $rejection) {
			$recordId = (string) ($rejection['recordId'] ?? '');
			$code     = (string) ($rejection['errorCode'] ?? '');
			if ($code === '' || array_key_exists($recordId, $kinds) === false) {
				continue;
			}

			$fields = [];
			foreach ((array) ($rejection['offendingFields'] ?? []) as $field) {
				if (is_string($field) === true && $field !== '') {
					$fields[] = $field;
				}
			}

			$clean[$recordId] = [
				'recordId' => $recordId,
				'sourceKind' => $kinds[$recordId],
				'errorCode' => $code,
				'offendingFields' => $fields,
			];
		}//end foreach

		$this->answered      = true;
		$this->rejected      = array_values($clean);
		$this->acceptedCount = max(0, min($acceptedCount, (count($this->records) - count($this->rejected))));

	}//end accept()

	/**
	 * Whether the owning app answered.
	 *
	 * @return bool True once accept() ran.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-011-the-owning-apps-answer-ends-the-job
	 */
	public function isAnswered(): bool {
		return $this->answered;

	}//end isAnswered()

	/**
	 * The accepted count, clamped to the records not rejected.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-011-the-owning-apps-answer-ends-the-job
	 */
	public function getAcceptedCount(): int {
		return $this->acceptedCount;

	}//end getAcceptedCount()

	/**
	 * The rejected records.
	 *
	 * @return array<int, array{recordId: string, sourceKind: string, errorCode: string, offendingFields: array<int, string>}>
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-011-the-owning-apps-answer-ends-the-job
	 */
	public function getRejected(): array {
		return $this->rejected;

	}//end getRejected()
}//end class
