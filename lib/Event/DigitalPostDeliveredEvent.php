<?php

/**
 * Integriq DigitalPostDelivered Event.
 *
 * Dispatched on every status change of a tracked digital post message, so the
 * app that asked for the letter learns what became of it without polling
 * integriq.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * The name says delivered because that is the status anyone waits for, but it
 * is dispatched on every change, including `failed`. A consumer that only
 * listens for the happy path will hear about the unhappy one too, which is
 * the point.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */
class DigitalPostDeliveredEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $messageId The tracked message id.
	 * @param string $status The status it moved to.
	 * @param string $requestedBy Who asked for the letter.
	 * @param string $previousStatus The status it moved from.
	 * @param bool $simulated Whether the binding that handled it sends nothing.
	 * @param string $lastError The provider's reason, when the status is failed.
	 */
	public function __construct(
		private readonly string $messageId,
		private readonly string $status,
		private readonly string $requestedBy = '',
		private readonly string $previousStatus = '',
		private readonly bool $simulated = false,
		private readonly string $lastError = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The tracked message id.
	 *
	 * @return string Message id.
	 */
	public function getMessageId(): string {
		return $this->messageId;
	}//end getMessageId()

	/**
	 * The status it moved to.
	 *
	 * @return string Status.
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * Who asked for the letter.
	 *
	 * @return string The acting user or system id.
	 */
	public function getRequestedBy(): string {
		return $this->requestedBy;
	}//end getRequestedBy()

	/**
	 * The status it moved from.
	 *
	 * @return string Previous status.
	 */
	public function getPreviousStatus(): string {
		return $this->previousStatus;
	}//end getPreviousStatus()

	/**
	 * Whether the binding that handled it sends nothing.
	 *
	 * @return bool True when the send was simulated.
	 */
	public function isSimulated(): bool {
		return $this->simulated;
	}//end isSimulated()

	/**
	 * The provider's reason, when the status is failed.
	 *
	 * @return string The reason, empty otherwise.
	 */
	public function getLastError(): string {
		return $this->lastError;
	}//end getLastError()
}//end class
