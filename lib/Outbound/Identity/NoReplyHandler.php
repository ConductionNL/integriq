<?php

/**
 * Integriq NoReplyHandler.
 *
 * A citizen who replies to a no-reply address has written to the gemeente.
 * Dropping that is the one option this refuses: the message is either
 * diverted to a mailbox somebody reads, or refused with a message that says
 * where to write instead. Either way it is recorded.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use DateTimeImmutable;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Decides what happens to mail sent to a no-reply identity.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
class NoReplyHandler {

	/**
	 * Send the message on to a mailbox somebody reads.
	 *
	 * @var string
	 */
	public const MODE_DIVERT = 'divert';

	/**
	 * Answer the sender and tell them where to write.
	 *
	 * @var string
	 */
	public const MODE_REFUSE = 'refuse';

	/**
	 * The schema a diversion or refusal is recorded under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'intake_message';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Records what happened.
	 */
	public function __construct(private readonly ORObjectService $objectService) {

	}//end __construct()

	/**
	 * Handle one reply to a no-reply identity.
	 *
	 * @param array<string,mixed> $identity The identity that was replied to.
	 * @param array<string,mixed> $message The inbound message: `from`, `subject`, `text`.
	 *
	 * @return array{outcome:string,target:string,notice:string} What was done with it.
	 */
	public function handle(array $identity, array $message): array {
		$noReply = ($identity['noReply'] ?? []);
		if (is_array($noReply) === false || ($noReply['enabled'] ?? false) !== true) {
			return [
				'outcome' => 'accepted',
				'target' => (string)($identity['address'] ?? ''),
				'notice' => '',
			];
		}

		$mode = (string)($noReply['mode'] ?? self::MODE_DIVERT);
		if ($mode === self::MODE_REFUSE) {
			$writeInstead = trim((string)($noReply['writeInstead'] ?? ''));
			$notice = ($writeInstead === ''
				? 'This address takes no replies.'
				: 'This address takes no replies. Please write to ' . $writeInstead . ' instead.');
			$this->record($identity, $message, self::MODE_REFUSE, $writeInstead, $notice);

			return ['outcome' => self::MODE_REFUSE, 'target' => $writeInstead, 'notice' => $notice];
		}

		$divertTo = trim((string)($noReply['divertTo'] ?? ''));
		if ($divertTo === '') {
			// Configured to divert with nowhere to divert to. Refusing is the
			// only remaining option that does not drop the message.
			$notice = 'This address takes no replies, and no forwarding mailbox is configured.';
			$this->record($identity, $message, self::MODE_REFUSE, '', $notice);

			return ['outcome' => self::MODE_REFUSE, 'target' => '', 'notice' => $notice];
		}

		$this->record($identity, $message, self::MODE_DIVERT, $divertTo, '');

		return ['outcome' => self::MODE_DIVERT, 'target' => $divertTo, 'notice' => ''];

	}//end handle()

	/**
	 * Record the diversion or the refusal.
	 *
	 * @param array<string,mixed> $identity The identity.
	 * @param array<string,mixed> $message The inbound message.
	 * @param string $outcome What was done.
	 * @param string $target Where it went, or where the sender was pointed.
	 * @param string $notice What the sender was told.
	 *
	 * @return void
	 */
	private function record(array $identity, array $message, string $outcome, string $target, string $notice): void {
		$this->objectService->saveObject(
			object: [
				'channelId' => 'mail',
				'externalId' => (string)($message['messageId'] ?? ''),
				'correspondent' => ['address' => (string)($message['from'] ?? '')],
				'text' => (string)($message['text'] ?? ''),
				'status' => 'held',
				'reason' => 'Reply to the no-reply identity "' . (string)($identity['address'] ?? '') . '": '
					. $outcome . ($target === '' ? '' : ' to ' . $target) . '. ' . $notice,
				'receivedAt' => (new DateTimeImmutable())->format('c'),
			],
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
		);

	}//end record()

}//end class
