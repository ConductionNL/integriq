<?php

/**
 * Integriq LastContactQuery.
 *
 * "Nobody has written to this citizen in six weeks" is the complaint behind
 * most klachten, and it is one query here. It stays a query: writing the
 * answer onto somebody else's record would make integriq own a field on a
 * case, which ADR-022 refuses, and would go stale the moment a message is
 * retried a week later. dossiq projects the answer onto the field it indexes.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound
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
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Answers when a recipient was last told anything about a subject.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-log-answers-when-a-recipient-was-last-told-anything-req-ocl-006
 */
class LastContactQuery {

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the log.
	 */
	public function __construct(private readonly ORObjectService $objectService) {

	}//end __construct()

	/**
	 * When this recipient was last told anything about this subject.
	 *
	 * "Told" means a message that reached at least the transport step for this
	 * recipient. A message that failed before it left told nobody anything.
	 *
	 * @param string $subjectRef The subject reference.
	 * @param string $recipient The recipient's address.
	 *
	 * @return array{contacted:bool,at:string|null,message:string|null} The answer. `contacted`
	 *         false means never, which is not the same as "now" and never borrows the case's
	 *         own dates.
	 */
	public function lastContact(string $subjectRef, string $recipient): array {
		$latest = null;
		$latestMessage = null;

		foreach ($this->messagesFor(subjectRef: $subjectRef) as $entity) {
			$record = $entity->getObject();
			$recipients = ($record['recipients'] ?? []);
			if (is_array($recipients) === false) {
				continue;
			}

			foreach ($recipients as $row) {
				if (is_array($row) === false) {
					continue;
				}

				if ((string)($row['address'] ?? '') !== $recipient) {
					continue;
				}

				if ((string)($row['status'] ?? '') !== RecipientState::STATUS_SENT) {
					continue;
				}

				$at = (string)($row['handedOverAt'] ?? '');
				if ($at === '') {
					$at = (string)($record['createdAt'] ?? '');
				}

				if ($at === '') {
					continue;
				}

				if ($latest === null || strtotime($at) > strtotime($latest)) {
					$latest = $at;
					$latestMessage = (string)$entity->getUuid();
				}
			}
		}

		return [
			'contacted' => ($latest !== null),
			'at' => $latest,
			'message' => $latestMessage,
		];

	}//end lastContact()

	/**
	 * Every message recorded for one subject.
	 *
	 * The recipient is not part of the filter on purpose: it lives inside a
	 * nested array, and asking the objects endpoint to filter on a nested key
	 * returns a confident wrong answer rather than an error.
	 *
	 * @param string $subjectRef The subject reference.
	 *
	 * @return array<int,ObjectEntity> The messages.
	 */
	private function messagesFor(string $subjectRef): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => MessageRecorder::REGISTER,
					'schema' => MessageRecorder::SCHEMA,
					'subjectRef' => $subjectRef,
				],
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		$messages = [];
		foreach ($results as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			if ((string)($row->getObject()['subjectRef'] ?? '') !== $subjectRef) {
				continue;
			}

			$messages[] = $row;
		}

		return $messages;

	}//end messagesFor()

}//end class
