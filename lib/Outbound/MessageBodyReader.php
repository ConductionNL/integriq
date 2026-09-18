<?php

/**
 * Integriq MessageBodyReader.
 *
 * Two permissions, because the body is not the fact. "A letter went out on 3
 * September to these two people" is what a handler needs to answer a klacht;
 * the text of that letter is a different question with a different audience,
 * and in a bezwaar dossier it can be the whole dispute.
 *
 * Reading a body is itself recorded, on the record that was read.
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

use DateTimeImmutable;
use OCA\Integriq\Service\ActionAuthService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IUser;

/**
 * Reads a stored body, for a principal allowed to and nobody else.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-the-sent-message-and-its-real-recipients-are-readable-behind-their-own-permission-req-ocl-002
 */
class MessageBodyReader {

	/**
	 * The ADR-023 action reading a stored body is gated by.
	 *
	 * It is deliberately not the action that lists the log: seeing that a
	 * message went out and reading what it said are different questions.
	 *
	 * @var string
	 */
	public const ACTION_READ_BODY = 'outbound.read-body';

	/**
	 * Constructor.
	 *
	 * @param MessageRecorder $recorder Reads the record.
	 * @param ORObjectService $objectService Records the read.
	 * @param ActionAuthService $actionAuth The ADR-023 action gate.
	 */
	public function __construct(
		private readonly MessageRecorder $recorder,
		private readonly ORObjectService $objectService,
		private readonly ActionAuthService $actionAuth,
	) {

	}//end __construct()

	/**
	 * Read one message's body and its resolved recipients.
	 *
	 * @param string $uuid The record uuid.
	 * @param IUser $user Who is asking.
	 *
	 * @return array{body:string,subject:string,recipients:array<int,array<string,mixed>>} The body.
	 *
	 * @throws \OCP\AppFramework\OCS\OCSForbiddenException When the principal may not read bodies.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When there is no such record.
	 */
	public function read(string $uuid, IUser $user): array {
		$this->actionAuth->requireAction(user: $user, action: self::ACTION_READ_BODY);

		$record = $this->recorder->read($uuid);
		$this->recordRead($uuid, $record, $user->getUID());

		return [
			'subject' => (string)($record['subject'] ?? ''),
			'body' => (string)($record['body'] ?? ''),
			'recipients' => $record['recipients'],
		];

	}//end read()

	/**
	 * Record that a body was read.
	 *
	 * @param string $uuid The record uuid.
	 * @param array<string,mixed> $record The record payload.
	 * @param string $actorUid Who read it.
	 *
	 * @return void
	 */
	private function recordRead(string $uuid, array $record, string $actorUid): void {
		$reads = ($record['bodyReads'] ?? []);
		if (is_array($reads) === false) {
			$reads = [];
		}

		$reads[] = [
			'by' => $actorUid,
			'at' => (new DateTimeImmutable())->format('c'),
		];
		$record['bodyReads'] = $reads;

		$this->objectService->saveObject(
			object: $record,
			register: MessageRecorder::REGISTER,
			schema: MessageRecorder::SCHEMA,
			uuid: $uuid,
		);

	}//end recordRead()

}//end class
