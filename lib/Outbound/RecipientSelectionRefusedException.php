<?php

/**
 * Integriq RecipientSelectionRefusedException.
 *
 * Thrown when the recipient decision of one message cannot be honoured: a
 * suppression without a reason, a suppression of a recipient the caller marked
 * required, or a suppression of somebody who is not a recipient at all. The
 * message is then not sent, and the refusal says why in words a handler reads.
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
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

use RuntimeException;

/**
 * A recipient decision that is refused, with a machine code and a readable reason.
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-suppression-carries-a-reason-and-is-refused-without-one-req-mrs-003
 */
class RecipientSelectionRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $refusalCode The machine-readable refusal code.
	 * @param string $reason      The readable reason, naming the recipient.
	 * @param string $address     The recipient the refusal is about ('' when none).
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-suppression-carries-a-reason-and-is-refused-without-one-req-mrs-003
	 */
	public function __construct(
		private readonly string $refusalCode,
		string $reason,
		private readonly string $address = '',
	) {
		parent::__construct(message: $reason);

	}//end __construct()

	/**
	 * The machine-readable refusal code.
	 *
	 * @return string One of the RecipientResolver::REFUSED_* codes.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-suppression-carries-a-reason-and-is-refused-without-one-req-mrs-003
	 */
	public function getRefusalCode(): string {
		return $this->refusalCode;

	}//end getRefusalCode()

	/**
	 * The recipient the refusal is about.
	 *
	 * @return string The address, or '' when the refusal is about no single recipient.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-decision-travels-on-the-delivery-request-req-mrs-006
	 */
	public function getAddress(): string {
		return $this->address;

	}//end getAddress()
}//end class
