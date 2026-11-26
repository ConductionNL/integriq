<?php

/**
 * Contract of the MessageRecorder.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see MessageRecorder} that other components call.
 */
interface MessageRecorderInterface {

	/**
	 * Mark one recipient failed at a step.
	 *
	 * @param string $uuid    The record uuid.
	 * @param string $address The recipient address.
	 * @param string $step    The step it failed at.
	 * @param string $reason  What the transport said.
	 *
	 * @return ObjectEntity The updated record.
	 *
	 * @spec openspec/specs/outbound-message-log/spec.md#requirement-every-outbound-message-is-recorded-per-recipient-and-per-step-req-ocl-001
	 */
	public function recipientFailed(string $uuid, string $address, string $step, string $reason): ObjectEntity;

	/**
	 * Record that the transport reported delivery to one recipient.
	 *
	 * @param string $uuid    The record uuid.
	 * @param string $address The recipient address.
	 * @param string $detail  What the transport said.
	 *
	 * @return ObjectEntity The updated record.
	 *
	 * @spec openspec/specs/outbound-message-log/spec.md#requirement-every-outbound-message-is-recorded-per-recipient-and-per-step-req-ocl-001
	 */
	public function deliveryReported(string $uuid, string $address, string $detail = ''): ObjectEntity;

	/**
	 * Read one record.
	 *
	 * @param string $uuid The record uuid.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no outbound message has that uuid.
	 *
	 * @spec openspec/specs/outbound-message-log/spec.md#requirement-every-outbound-message-is-recorded-per-recipient-and-per-step-req-ocl-001
	 */
	public function read(string $uuid): array;
}//end interface
