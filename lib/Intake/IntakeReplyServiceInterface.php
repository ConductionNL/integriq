<?php

/**
 * Contract of the IntakeReplyService.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\Integriq\Exception\IntakeChannelException;

/**
 * Public operations of {@see IntakeReplyService} that other components call.
 */
interface IntakeReplyServiceInterface {
	/**
	 * Reply to one stored inbound message.
	 *
	 * @param string $messageUuid The `intake_message` uuid.
	 * @param string $text The reply text.
	 *
	 * @return ReplyResult What happened.
	 *
	 * @throws IntakeChannelException When the message is unknown, or its channel is.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-direct-reply-to-a-citizen-s-message-passes-an-opt-out-req-ooa-009
	 */
	public function reply(string $messageUuid, string $text): ReplyResult;
}
