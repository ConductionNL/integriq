<?php

/**
 * Integriq SubmissionDrain.
 *
 * Pushes the staged Open Formulieren submissions that never became a case
 * through the inbound submit path, so the staging schema can go.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use RuntimeException;

/**
 * Drain the `received` and `failed` submissions into their cases.
 *
 * A dry run only lists them. With apply, each runs through
 * DestinationSubmitter as the Open Formulieren connection's account, with its
 * submission id as idempotency key, so a drain that is run twice creates no
 * second case; a drained submission is marked `handed_off` with its case.
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/tasks.md
 */
class SubmissionDrain {

	/**
	 * The statuses still waiting for a case.
	 */
	public const PENDING_STATUSES = ['received', 'failed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService                 $objectService Reads and marks the staged submissions.
	 * @param DestinationSubmitterInterface $submitter     The inbound submit path.
	 * @param OpenFormulierenConnection     $connection    The account the submissions run as.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly DestinationSubmitterInterface $submitter,
		private readonly OpenFormulierenConnection $connection,
	) {
	}//end __construct()

	/**
	 * Drain the pending submissions, or list them.
	 *
	 * @param bool $apply Submit and mark them; without it nothing is written.
	 * @param int  $limit The most submissions per status.
	 *
	 * @return array{pending: int, drained: int, failed: int, rows: list<array{uuid: string, formSlug: string, outcome: string}>}
	 *
	 * @throws RuntimeException When apply is asked and there is no Open Formulieren connection account.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/tasks.md
	 */
	public function run(bool $apply, int $limit): array {
		$pending = $this->pending(limit: $limit);
		$report  = ['pending' => count($pending), 'drained' => 0, 'failed' => 0, 'rows' => []];
		if ($apply === false) {
			foreach ($pending as $submission) {
				$report['rows'][] = $this->row(submission: $submission, outcome: 'pending');
			}

			return $report;
		}

		$account = $this->connection->resolveAccount((string)($this->connection->findConsumer()?->getObject()['userId'] ?? ''));
		foreach ($pending as $submission) {
			$outcome = $this->connection->runAs($account, fn (): string => $this->drainOne(submission: $submission));
			$counter = 'failed';
			if (str_starts_with($outcome, 'created') === true) {
				$counter = 'drained';
			}

			$report[$counter]++;
			$report['rows'][] = $this->row(submission: $submission, outcome: $outcome);
		}

		return $report;
	}//end run()

	/**
	 * Submit one staged submission and mark it handed off.
	 *
	 * @param ObjectEntity $submission The staged submission.
	 *
	 * @return string `created <reference>` or `refused <status>: <message>`.
	 */
	private function drainOne(ObjectEntity $submission): string {
		$data = (array)$submission->getObject();
		$id   = (string)($data['submissionUuid'] ?? '');
		if ($id === '') {
			$id = (string)$submission->getUuid();
		}

		$values = (array)($data['rawValues'] ?? []);
		try {
			$answer = $this->submitter->submit(
				formSlug: (string)($data['formSlug'] ?? ''),
				submissionId: $id,
				values: $values,
				payloadHash: hash('sha256', (string)json_encode($values))
			);
		} catch (FormSubmitRefusedException $refusal) {
			return 'refused ' . $refusal->getStatus() . ': ' . $refusal->getMessage();
		}

		$reference = (string)($answer['reference'] ?? ($answer['id'] ?? ''));
		$targetCase = ['reference' => $reference, 'id' => (string)($answer['id'] ?? '')];
		$this->objectService->saveObject(
			object: array_merge($data, ['status' => 'handed_off', 'errorDetail' => null, 'targetCase' => $targetCase]),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: OpenFormulierenIntakeService::REGISTER, schema: OpenFormulierenIntakeService::SCHEMA_SUBMISSION),
				uuid: (string)$submission->getUuid()
			)
		);

		return 'created ' . $reference;
	}//end drainOne()

	/**
	 * The pending submissions, `received` first.
	 *
	 * @param int $limit The most per status.
	 *
	 * @return list<ObjectEntity>
	 */
	private function pending(int $limit): array {
		$pending = [];
		foreach (self::PENDING_STATUSES as $status) {
			$matches = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => OpenFormulierenIntakeService::REGISTER,
						'schema' => OpenFormulierenIntakeService::SCHEMA_SUBMISSION,
						'status' => $status,
					],
					'limit' => $limit,
				]
			);
			foreach (($matches['results'] ?? $matches) as $candidate) {
				if ($candidate instanceof ObjectEntity === true && ($candidate->getObject()['status'] ?? null) === $status) {
					$pending[] = $candidate;
				}
			}
		}

		return $pending;
	}//end pending()

	/**
	 * One report row.
	 *
	 * @param ObjectEntity $submission The submission.
	 * @param string       $outcome    What happened to it.
	 *
	 * @return array{uuid: string, formSlug: string, outcome: string}
	 */
	private function row(ObjectEntity $submission, string $outcome): array {
		return [
			'uuid' => (string)$submission->getUuid(),
			'formSlug' => (string)($submission->getObject()['formSlug'] ?? ''),
			'outcome' => $outcome,
		];
	}//end row()
}//end class
