<?php

/**
 * Contract of the IntakeAssignmentService.
 *
 * @category Contract
 * @package  OCA\Integriq\Intake
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Exception\IntakeRoutingException;

/**
 * Public operations of {@see IntakeAssignmentService} that other components call.
 */
interface IntakeAssignmentServiceInterface {

	/**
	 * Link one stored inbound message to a case a handler picked.
	 *
	 * @param string               $messageUuid The `intake_message` uuid.
	 * @param array<string,string> $assignment  `caseRef` (the case) and `assignedBy` (the handler's user id).
	 *
	 * @return array<string,mixed> `id`, `status`, `targetRef`, `assignedBy`, `assignedAt` and `attachmentsLinked`.
	 *
	 * @throws IntakeChannelException When the message is unknown.
	 * @throws IntakeRoutingException When no case is named, or the message cannot be assigned in its state.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
	 */
	public function assign(string $messageUuid, array $assignment): array;
}//end interface
