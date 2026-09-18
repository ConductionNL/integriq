<?php

/**
 * Who a message was meant for, who was added, and who was kept off it.
 *
 * 🔴 THE RESOLVED LIST ALONE IS NOT A RECORD. Storing only "these three
 * addresses received it" loses the one fact somebody asks about a year later:
 * that a fourth person was meant to receive it and did not. From the resolved
 * list, a recipient who was deliberately kept off a beschikking and a recipient
 * who was never on the list look identical, and so does a bug that dropped
 * them.
 *
 * So all three parts are kept: the standing recipients the caller gave, the
 * ones added for this message, and the ones suppressed for it. A suppressed
 * recipient stays IN the record and is marked suppressed.
 *
 * 🔴 AND A SUPPRESSED RECIPIENT CARRIES NO DELIVERY STATE. Not `failed`, not
 * `not reported`, not `pending`. Those all say something went wrong with a
 * delivery that was attempted, and nothing was attempted. `not reported` in
 * particular reads as a transport that never answered, which sends somebody to
 * chase a provider about a message nobody sent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Integriq\Service\Recipients
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Integriq.app
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Recipients;

/**
 * The three recorded parts of one message's recipient decision.
 */
final class RecipientDecision {

	/**
	 * A recipient who was on the standing list and stays on it.
	 *
	 * @var string
	 */
	public const STANDING = 'standing';

	/**
	 * A recipient added for this message alone.
	 *
	 * @var string
	 */
	public const ADDED = 'added';

	/**
	 * A recipient deliberately kept off this message.
	 *
	 * @var string
	 */
	public const SUPPRESSED = 'suppressed';

	/**
	 * Hold the decision.
	 *
	 * @param array<int, array<string, mixed>> $standing   The standing recipients, each with its own state.
	 * @param array<int, array<string, mixed>> $added      Added for this message only.
	 * @param array<int, array<string, mixed>> $suppressed Kept off, each with a reason, a user and a time.
	 */
	public function __construct(
		public readonly array $standing,
		public readonly array $added,
		public readonly array $suppressed,
	) {
	}//end __construct()

	/**
	 * The addresses the transport is handed.
	 *
	 * Standing minus suppressed, plus added. Computed from the parts rather
	 * than stored beside them, so the list and the record can never disagree
	 * about who received the message.
	 *
	 * @return string[] The addresses, in order, without duplicates.
	 */
	public function forTransport(): array {
		$suppressed = [];
		foreach ($this->suppressed as $recipient) {
			$suppressed[$this->addressOf(recipient: $recipient)] = true;
		}

		$addresses = [];
		foreach (array_merge($this->standing, $this->added) as $recipient) {
			$address = $this->addressOf(recipient: $recipient);
			if ($address === '' || isset($suppressed[$address]) === true) {
				continue;
			}

			$addresses[$address] = true;
		}

		return array_keys($addresses);
	}//end forTransport()

	/**
	 * The record, with every part kept and every suppression explained.
	 *
	 * @return array<string, mixed> The record.
	 */
	public function asRecord(): array {
		return [
			'standing' => $this->standing,
			'added' => $this->added,
			'suppressed' => $this->suppressed,
			'resolved' => $this->forTransport(),
			'standingCount' => count($this->standing),
			'addedCount' => count($this->added),
			'suppressedCount' => count($this->suppressed),
		];
	}//end asRecord()

	/**
	 * One recipient's address.
	 *
	 * @param array<string, mixed> $recipient The recipient.
	 *
	 * @return string The address, or an empty string.
	 */
	private function addressOf(array $recipient): string {
		return trim((string)($recipient['address'] ?? ''));
	}//end addressOf()
}//end class
