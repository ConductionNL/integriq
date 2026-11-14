<?php

/**
 * Resolving who receives one message, and refusing the requests that should not send.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure. No database, no transport, no clock of its own. That is not only for
 * testability: REQ-MRS-002 requires that an addition touch nothing outside the
 * message, and a resolver that cannot write is the strongest available proof of
 * it. There is no standing list to accidentally update, no party record to
 * amend, no subject to touch.
 *
 * 🔴 THREE REFUSALS, AND EACH ONE STOPS THE SEND RATHER THAN CORRECTING IT.
 *
 *  - A SUPPRESSION WITHOUT A REASON is refused. A year later the record has to
 *    say why somebody was kept off a beschikking, and "suppressed" with an
 *    empty reason is indistinguishable from a mis-click. Defaulting the reason
 *    to "no reason given" would make the record complete and useless.
 *  - SUPPRESSING A REQUIRED RECIPIENT is refused, and the refusal REPEATS THE
 *    CALLER'S OWN WORDS for why it is required. "This recipient is required"
 *    tells a handler nothing they can argue with or escalate; "the applicant
 *    must receive the beschikking under Awb 3:41" tells them what to do next.
 *  - A SUPPRESSION OF SOMEBODY NOT ON THE LIST is refused, because it silently
 *    does nothing while reading as a decision that was carried out.
 *
 * 🔴 AND INTEGRIQ NEVER DECIDES WHAT IS REQUIRED. Only a recipient the CALLER
 * marked required, with the caller's own stated reason, is protected. A rule
 * invented here would be integriq overriding a municipality's own legal advice
 * about its own letters, on the strength of a guess in a connector.
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

use DateTimeImmutable;

/**
 * Turns a delivery request into a recorded recipient decision, or refuses it.
 */
class RecipientResolver {

	/**
	 * Why this request may not be sent, if it may not.
	 *
	 * Every refusal, not the first: a handler correcting one and meeting the
	 * next on the retry learns the rules one round trip at a time.
	 *
	 * @param array<int, array<string, mixed>> $standing     The standing recipients.
	 * @param array<int, array<string, mixed>> $suppressions The suppressions asked for.
	 *
	 * @return string[] The refusals, empty when the request may be sent.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
	 */
	public function refusals(array $standing, array $suppressions): array {
		$byAddress = $this->recipientsByAddress(standing: $standing);

		$refusals = [];
		foreach ($suppressions as $suppression) {
			$refusals = array_merge($refusals, $this->suppressionRefusals(suppression: $suppression, byAddress: $byAddress));
		}

		return $refusals;
	}//end refusals()

	/**
	 * The standing recipients by trimmed address; a recipient without an address is left out.
	 *
	 * @param array<int, array<string, mixed>> $standing The standing recipients.
	 *
	 * @return array<string, array<string, mixed>> The recipients by address.
	 */
	private function recipientsByAddress(array $standing): array {
		$byAddress = [];
		foreach ($standing as $recipient) {
			$address = trim((string)($recipient['address'] ?? ''));
			if ($address !== '') {
				$byAddress[$address] = $recipient;
			}
		}

		return $byAddress;

	}//end recipientsByAddress()

	/**
	 * What is wrong with one suppression: it names nobody on the list, or it has no reason, or it names a required recipient.
	 *
	 * @param array<string, mixed>                $suppression The suppression.
	 * @param array<string, array<string, mixed>> $byAddress   The standing recipients by address.
	 *
	 * @return array<int, string> The refusals, in that order.
	 */
	private function suppressionRefusals(array $suppression, array $byAddress): array {
		$address = trim((string)($suppression['address'] ?? ''));
		if ($address === '' || isset($byAddress[$address]) === false) {
			return [$this->notOnTheListRefusal(address: $address)];
		}

		$refusals = [
			$this->missingReasonRefusal(suppression: $suppression, address: $address),
			$this->requiredRecipientRefusal(recipient: $byAddress[$address], address: $address),
		];

		return array_values(array_filter($refusals, static fn (?string $refusal): bool => $refusal !== null));

	}//end suppressionRefusals()

	/**
	 * The refusal of a suppression that names nobody on the list.
	 *
	 * @param string $address The suppressed address, '' when it names none.
	 *
	 * @return string The refusal.
	 */
	private function notOnTheListRefusal(string $address): string {
		$named = $address;
		if ($named === '') {
			$named = '(no address)';
		}

		return sprintf(
			'"%s" is not on this message\'s recipient list, so suppressing it would record a decision '
			.'that changed nothing.',
			$named
		);

	}//end notOnTheListRefusal()

	/**
	 * The refusal of a suppression without a reason, or null when it has one.
	 *
	 * @param array<string, mixed> $suppression The suppression.
	 * @param string               $address     The suppressed address.
	 *
	 * @return string|null The refusal.
	 */
	private function missingReasonRefusal(array $suppression, string $address): ?string {
		if (trim((string)($suppression['reason'] ?? '')) !== '') {
			return null;
		}

		return sprintf(
			'Keeping "%s" off this message needs a reason. A year from now the record has to say why '
			.'they did not receive it, and an empty reason reads the same as a mis-click.',
			$address
		);

	}//end missingReasonRefusal()

	/**
	 * The refusal of suppressing a recipient the caller marked required, or null when it is not.
	 *
	 * The caller's own words, repeated. A handler who meets "this recipient is
	 * required" has nothing to argue with or escalate.
	 *
	 * @param array<string, mixed> $recipient The standing recipient.
	 * @param string               $address   Its address.
	 *
	 * @return string|null The refusal.
	 */
	private function requiredRecipientRefusal(array $recipient, string $address): ?string {
		$requirement = trim((string)($recipient['requiredBecause'] ?? ''));
		if ($requirement === '') {
			return null;
		}

		return sprintf(
			'"%s" cannot be kept off this message: %s',
			$address,
			$requirement
		);

	}//end requiredRecipientRefusal()

	/**
	 * Resolve a request into its three recorded parts.
	 *
	 * @param array<int, array<string, mixed>> $standing     The standing recipients.
	 * @param array<int, array<string, mixed>> $additions    Added for this message only.
	 * @param array<int, array<string, mixed>> $suppressions Kept off this message.
	 * @param string                           $user         Who made the decision.
	 * @param DateTimeImmutable|null           $now          The moment, for a frozen clock.
	 *
	 * @return RecipientDecision The decision.
	 *
	 * @throws RecipientRefusedException When the request may not be sent.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
	 */
	public function resolve(
		array $standing,
		array $additions = [],
		array $suppressions = [],
		string $user = '',
		?DateTimeImmutable $now = null
	): RecipientDecision {
		$refusals = $this->refusals(standing: $standing, suppressions: $suppressions);
		if ($refusals !== []) {
			throw new RecipientRefusedException(message: implode(' ', $refusals), refusals: $refusals);
		}

		$resolution = new RecipientResolution(
			user: $user,
			moment: ($now ?? new DateTimeImmutable()),
			suppressions: $this->suppressionsByAddress(suppressions: $suppressions)
		);
		[$standingPart, $suppressedPart] = $this->splitStanding(standing: $standing, resolution: $resolution);

		$addedPart = $this->addedPart(additions: $additions, resolution: $resolution);

		return new RecipientDecision(standing: $standingPart, added: $addedPart, suppressed: $suppressedPart);
	}//end resolve()

	/**
	 * The suppressions by trimmed address; a later suppression of the same address wins.
	 *
	 * @param array<int, array<string, mixed>> $suppressions The suppressions.
	 *
	 * @return array<string, array<string, mixed>> The suppressions by address.
	 */
	private function suppressionsByAddress(array $suppressions): array {
		$suppressedAddresses = [];
		foreach ($suppressions as $suppression) {
			$suppressedAddresses[trim((string)($suppression['address'] ?? ''))] = $suppression;
		}

		return $suppressedAddresses;

	}//end suppressionsByAddress()

	/**
	 * Split the standing recipients into those that stay and those kept off this message.
	 *
	 * @param array<int, array<string, mixed>> $standing   The standing recipients.
	 * @param RecipientResolution              $resolution The resolution.
	 *
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>} The standing and the suppressed part.
	 */
	private function splitStanding(array $standing, RecipientResolution $resolution): array {
		$standingPart = [];
		$suppressedPart = [];
		foreach ($standing as $recipient) {
			$suppression = ($resolution->suppressions[trim((string)($recipient['address'] ?? ''))] ?? null);
			if ($suppression === null) {
				$standingPart[] = array_merge($recipient, ['part' => RecipientDecision::STANDING]);
				continue;
			}

			$suppressedPart[] = $this->suppressedRecipient(recipient: $recipient, resolution: $resolution);
		}

		return [$standingPart, $suppressedPart];

	}//end splitStanding()

	/**
	 * A standing recipient kept off this message, with the suppression's reason, user and time.
	 *
	 * 🔴 IT STAYS IN THE RECORD, AND IT CARRIES NO DELIVERY STATE. Not failed,
	 * not pending, and above all not "not reported": that reads as a transport
	 * that never answered, and sends somebody chasing a provider about a
	 * message nobody sent.
	 *
	 * @param array<string, mixed> $recipient  The standing recipient.
	 * @param RecipientResolution  $resolution The resolution.
	 *
	 * @return array<string, mixed> The suppressed entry.
	 */
	private function suppressedRecipient(array $recipient, RecipientResolution $resolution): array {
		$suppression = $resolution->suppressions[trim((string)($recipient['address'] ?? ''))];

		return array_merge(
			$recipient,
			[
				'part' => RecipientDecision::SUPPRESSED,
				'reason' => trim((string)($suppression['reason'] ?? '')),
				'suppressedBy' => ($suppression['user'] ?? $resolution->user),
				'suppressedAt' => $resolution->at(),
				'deliveryState' => null,
			]
		);

	}//end suppressedRecipient()

	/**
	 * The recipients added for this message only.
	 *
	 * An addition is scoped to this message by construction: it lands in the
	 * returned decision and nowhere else, because this class has nothing to
	 * write to.
	 *
	 * @param array<int, array<string, mixed>> $additions  The additions.
	 * @param RecipientResolution              $resolution The resolution.
	 *
	 * @return array<int, array<string, mixed>> The added part.
	 */
	private function addedPart(array $additions, RecipientResolution $resolution): array {
		$addedPart = [];
		foreach ($additions as $addition) {
			$addedPart[] = array_merge(
				$addition,
				['part' => RecipientDecision::ADDED, 'addedBy' => ($addition['user'] ?? $resolution->user), 'addedAt' => $resolution->at()]
			);
		}

		return $addedPart;

	}//end addedPart()

	/**
	 * The same answer the send would record, written nowhere.
	 *
	 * 🔴 THE PREVIEW RUNS THE SEND'S RESOLUTION, NOT A COPY OF IT. A second
	 * implementation drifts, and the first time anybody notices is when a
	 * handler approves a list of recipients that is not the list the message
	 * went to. That is worse than having no preview at all, because the handler
	 * has now signed off on it.
	 *
	 * @param array<int, array<string, mixed>> $standing     The standing recipients.
	 * @param array<int, array<string, mixed>> $additions    Added for this message only.
	 * @param array<int, array<string, mixed>> $suppressions Kept off this message.
	 * @param string                           $user         Who is previewing.
	 * @param DateTimeImmutable|null           $now          The moment.
	 *
	 * @return array<string, mixed> The same three parts the send would record.
	 *
	 * @throws RecipientRefusedException When the request would be refused.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md
	 */
	public function preview(
		array $standing,
		array $additions = [],
		array $suppressions = [],
		string $user = '',
		?DateTimeImmutable $now = null
	): array {
		return $this->resolve(
			standing: $standing,
			additions: $additions,
			suppressions: $suppressions,
			user: $user,
			now: $now
		)->asRecord();
	}//end preview()
}//end class
