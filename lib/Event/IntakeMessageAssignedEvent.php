<?php

/**
 * Integriq IntakeMessageAssigned Event.
 *
 * The ADR-041 cross-app command contract for working the shared intake inbox:
 * a handler linked an inbound message to a case that already exists, and
 * integriq asks whichever app owns that case to take the message and its
 * attachments in. The listener says so in the result slot. Integriq owns the
 * way in; it owns no case type, and names none in code.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "a handler put this message on one of your cases".
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */
class IntakeMessageAssignedEvent extends Event {

	/**
	 * Whether the owning app took the message in.
	 *
	 * @var boolean
	 */
	private bool $linked = false;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $message     The message as the `intake_message` schema holds it, assignment included.
	 * @param string              $caseRef     The case the handler picked.
	 * @param string              $messageUuid The integriq `intake_message` uuid.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function __construct(
		private readonly array $message,
		private readonly string $caseRef,
		private readonly string $messageUuid,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The message, with `assignedBy` and `assignedAt`.
	 *
	 * @return array<string,mixed> The message.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function getMessage(): array {
		return $this->message;
	}//end getMessage()

	/**
	 * The case the handler picked.
	 *
	 * @return string The case reference.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function getCaseRef(): string {
		return $this->caseRef;
	}//end getCaseRef()

	/**
	 * The integriq `intake_message` uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function getMessageUuid(): string {
		return $this->messageUuid;
	}//end getMessageUuid()

	/**
	 * The attachments and the media of the message, in that order.
	 *
	 * @return array<int,array<string,mixed>> The file metadata.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function getAttachments(): array {
		$files = [];
		foreach (['attachments', 'media'] as $key) {
			foreach ((array)($this->message[$key] ?? []) as $file) {
				if (is_array($file) === true) {
					$files[] = $file;
				}
			}
		}

		return $files;
	}//end getAttachments()

	/**
	 * Answer that the message and its attachments are on the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function markLinked(): void {
		$this->linked = true;
	}//end markLinked()

	/**
	 * Whether the owning app took the message in.
	 *
	 * @return boolean True when a listener answered.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function isLinked(): bool {
		return $this->linked;
	}//end isLinked()
}//end class
