<?php

/**
 * Integriq ForwardService.
 *
 * Awb 2:3 doorzendplicht asks for provability: a request for the wrong
 * bestuursorgaan must be passed on, and the passing on must be provable. An
 * edit to the original record cannot prove anything, because it replaces the
 * evidence with its consequence. So a forward is its own record, linked to
 * the original from both ends, and the original is left exactly as it was.
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
use InvalidArgumentException;

/**
 * Forwards a recorded message onward as a new, linked record.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-a-message-is-forwarded-onward-and-the-forwarding-is-a-record-req-ocl-004
 */
class ForwardService {

	/**
	 * Constructor.
	 *
	 * @param MessageRecorder $recorder Reads the original and opens the forward.
	 * @param ORObjectService $objectService Writes the link back onto the original.
	 */
	public function __construct(
		private readonly MessageRecorder $recorder,
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Forward one recorded message.
	 *
	 * @param string $uuid The original record's uuid.
	 * @param array<int,array<string,mixed>> $recipients Who it goes to now.
	 * @param string $actorUid Who forwarded it.
	 * @param string $note An optional covering note, prepended to the body.
	 * @param string|null $channel The channel to forward on, or null to keep the original's.
	 *
	 * @return ObjectEntity The forward record.
	 *
	 * @throws InvalidArgumentException When no recipient is named, which would forward to nobody
	 *                                  while recording that a forward happened.
	 */
	public function forward(
		string $uuid,
		array $recipients,
		string $actorUid,
		string $note = '',
		?string $channel = null,
	): ObjectEntity {
		if ($recipients === []) {
			throw new InvalidArgumentException('A forward needs at least one recipient.');
		}

		$original = $this->recorder->read($uuid);
		$body = (string)($original['body'] ?? '');
		if (trim($note) !== '') {
			$body = $note . "\n\n---\n\n" . $body;
		}

		$forward = $this->recorder->start(
			(string)($original['subjectRef'] ?? ''),
			($channel ?? (string)($original['channel'] ?? '')),
			$this->forwardSubject((string)($original['subject'] ?? '')),
			$body,
			$recipients,
			[
				'sourceApp' => (string)($original['sourceApp'] ?? ''),
				'attachments' => ($original['attachments'] ?? []),
				'correlationId' => (string)($original['correlationId'] ?? ''),
			]
		);

		$this->linkForward($forward, $uuid, $actorUid);
		$this->linkOriginal($uuid, $original, (string)$forward->getUuid(), $actorUid);

		return $forward;

	}//end forward()

	/**
	 * Write the backward half of the link onto the forward.
	 *
	 * @param ObjectEntity $forward The forward record.
	 * @param string $originalUuid The original's uuid.
	 * @param string $actorUid Who forwarded it.
	 *
	 * @return void
	 */
	private function linkForward(ObjectEntity $forward, string $originalUuid, string $actorUid): void {
		$payload = $forward->getObject();
		$payload['forwardedFrom'] = $originalUuid;
		$payload['forwardedBy'] = $actorUid;

		$this->objectService->saveObject(
			object: $payload,
			register: MessageRecorder::REGISTER,
			schema: MessageRecorder::SCHEMA,
			uuid: (string)$forward->getUuid(),
		);

	}//end linkForward()

	/**
	 * Write the forward half of the link onto the original.
	 *
	 * This is the only change a forward makes to the original: the link. Its
	 * recipients, steps, statuses and body stay exactly as they were, because
	 * they are the evidence.
	 *
	 * @param string $uuid The original's uuid.
	 * @param array<string,mixed> $original The original payload.
	 * @param string $forwardUuid The forward's uuid.
	 * @param string $actorUid Who forwarded it.
	 *
	 * @return void
	 */
	private function linkOriginal(string $uuid, array $original, string $forwardUuid, string $actorUid): void {
		$forwards = ($original['forwardedTo'] ?? []);
		if (is_array($forwards) === false) {
			$forwards = [];
		}

		$forwards[] = ['message' => $forwardUuid, 'by' => $actorUid];
		$original['forwardedTo'] = $forwards;

		$this->objectService->saveObject(
			object: $original,
			register: MessageRecorder::REGISTER,
			schema: MessageRecorder::SCHEMA,
			uuid: $uuid,
		);

	}//end linkOriginal()

	/**
	 * The subject a forward carries.
	 *
	 * @param string $subject The original subject.
	 *
	 * @return string The forward's subject.
	 */
	private function forwardSubject(string $subject): string {
		if (str_starts_with(strtolower($subject), 'fwd:') === true) {
			return $subject;
		}

		return 'Fwd: ' . $subject;

	}//end forwardSubject()

}//end class
