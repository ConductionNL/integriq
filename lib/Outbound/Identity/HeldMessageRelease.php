<?php

/**
 * Integriq HeldMessageRelease.
 *
 * Releases a message a send check held for review. The release is recorded
 * with the person who gave it, then the message goes out through the same
 * delivery pipeline a retry uses, which asks the opt-out list again for every
 * recipient: a release is a person's decision to try again, not a way past a
 * recipient's opt-out.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCA\Integriq\Outbound\OutboundRetryServiceInterface;

/**
 * Release a held message, then send it.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
 */
class HeldMessageRelease implements HeldMessageReleaseInterface {

	/**
	 * Constructor.
	 *
	 * @param HoldQueueInterface            $holdQueue    Records the release with its actor.
	 * @param OutboundRetryServiceInterface $retryService Sends the message through the delivery pipeline.
	 */
	public function __construct(
		private readonly HoldQueueInterface $holdQueue,
		private readonly OutboundRetryServiceInterface $retryService,
	) {

	}//end __construct()

	/**
	 * Release a message held for review and send it through the delivery pipeline.
	 *
	 * The release is written first, so the log names who released the message
	 * whatever the send then does.
	 *
	 * @param string $uuid     The message record uuid.
	 * @param string $actorUid Who released it.
	 *
	 * @return array{message:string,status:string,retry:array<string,mixed>} The release and the send's outcome.
	 *
	 * @throws \RuntimeException When the message is not held for review.
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
	 */
	public function release(string $uuid, string $actorUid): array {
		$this->holdQueue->releaseHeld(uuid: $uuid, actorUid: $actorUid);

		return [
			'message' => $uuid,
			'status' => HoldQueue::STATUS_RELEASED,
			'retry' => $this->retryService->retry($uuid, $actorUid),
		];

	}//end release()

}//end class
