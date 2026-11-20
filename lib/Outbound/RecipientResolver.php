<?php

/**
 * Integriq RecipientResolver.
 *
 * Resolves the recipients of one outgoing message: the standing recipients the
 * calling app passes, plus the ones added for this message only, minus the ones
 * suppressed for this message only. The result keeps all three parts, because a
 * flattened list answers "who got it" and cannot answer "who was meant to and
 * did not". The send and the preview call the same method, so the preview is
 * never a second opinion.
 *
 * Integriq does not decide who is required: a caller marks a standing recipient
 * required and names what requires it, and a suppression of that recipient is
 * refused with the caller's own words repeated back.
 *
 * Pure: it reads nothing and writes nothing. An addition lives on the message
 * alone, because there is nowhere else here to put it.
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

use DateTimeImmutable;
use OCP\IL10N;

/**
 * Turns a per-message recipient decision into its three recorded parts and the resolved list.
 *
 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001
 */
class RecipientResolver {

	/**
	 * A suppression without a typed reason.
	 *
	 * @var string
	 */
	public const REFUSED_NO_REASON = 'suppression_without_reason';

	/**
	 * A suppression of a recipient the caller marked required.
	 *
	 * @var string
	 */
	public const REFUSED_REQUIRED = 'required_recipient';

	/**
	 * A suppression of somebody who is not a standing recipient.
	 *
	 * @var string
	 */
	public const REFUSED_UNKNOWN = 'unknown_recipient';

	/**
	 * A required marker that does not say what requires the recipient.
	 *
	 * @var string
	 */
	public const REFUSED_REQUIRED_UNNAMED = 'required_without_source';

	/**
	 * An addition without an address.
	 *
	 * @var string
	 */
	public const REFUSED_ADDITION_WITHOUT_ADDRESS = 'addition_without_address';

	/**
	 * The state a suppressed recipient reads on the record.
	 *
	 * @var string
	 */
	public const STATE_SUPPRESSED = RecipientState::SUPPRESSED;

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the refusals.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Whether a recipient decision asks for anything at all.
	 *
	 * @param array<string,mixed> $decision The decision as the caller sent it.
	 *
	 * @return bool True when it carries standing, added or suppressed recipients.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-decision-travels-on-the-delivery-request-req-mrs-006
	 */
	public function isDecision(array $decision): bool {
		foreach (['standing', 'added', 'suppressed'] as $part) {
			if (is_array($decision[$part] ?? null) === true && $decision[$part] !== []) {
				return true;
			}
		}

		return false;

	}//end isDecision()

	/**
	 * Resolve a message's recipients.
	 *
	 * @param array<string,mixed> $decision `standing` (`{address, name, accountId, required, requiredBy}`),
	 *                                      `added` (`{address, name, accountId}`) and `suppressed`
	 *                                      (`{address, reason, user, at}`).
	 * @param string              $userId   Who made the decision, used when a suppression names nobody.
	 *
	 * @return array<string,array<int,array<string,mixed>>> The three recorded parts (`standing`, `added`,
	 *                                                     `suppressed`) and the list the transport
	 *                                                     receives (`resolved`).
	 *
	 * @throws RecipientSelectionRefusedException When the decision cannot be honoured; nothing is to be sent.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-list-of-a-message-is-a-recorded-decision-req-mrs-001
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-an-addition-applies-to-one-message-only-req-mrs-002
	 */
	public function resolve(array $decision, string $userId = ''): array {
		$standing = $this->standing(rows: $this->rows(value: ($decision['standing'] ?? [])));
		$added = $this->added(rows: $this->rows(value: ($decision['added'] ?? [])));
		$suppressed = $this->suppressed(
			rows: $this->rows(value: ($decision['suppressed'] ?? [])),
			standing: $standing,
			userId: $userId
		);

		$gone = [];
		foreach ($suppressed as $row) {
			$gone[$this->key(address: $row['address'])] = true;
		}

		$resolved = [];
		$seen = [];
		foreach (array_merge($standing, $added) as $row) {
			$key = $this->key(address: $row['address']);
			if (isset($gone[$key]) === true || isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			$resolved[] = [
				'address' => $row['address'],
				'name' => $row['name'],
				'accountId' => $row['accountId'],
			];
		}

		return [
			'standing' => $standing,
			'added' => $added,
			'suppressed' => $suppressed,
			'resolved' => $resolved,
		];

	}//end resolve()

	/**
	 * The standing recipients, each with its required marker; a marker that names
	 * nothing is refused, since integriq does not invent the reason.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows as sent.
	 *
	 * @return array<int,array<string,mixed>> The normalised standing recipients.
	 *
	 * @throws RecipientSelectionRefusedException When a required marker names nothing.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-required-recipient-cannot-be-suppressed-req-mrs-004
	 */
	private function standing(array $rows): array {
		$standing = [];
		foreach ($rows as $row) {
			$address = trim((string)($row['address'] ?? ''));
			if ($address === '') {
				continue;
			}

			$required = (($row['required'] ?? false) === true);
			$requiredBy = trim((string)($row['requiredBy'] ?? ''));
			if ($required === true && $requiredBy === '') {
				throw new RecipientSelectionRefusedException(
					self::REFUSED_REQUIRED_UNNAMED,
					$this->l10n->t('%1$s is marked required, but the request does not say what requires it.', [$address]),
					$address
				);
			}

			$standing[] = [
				'address' => $address,
				'name' => (string)($row['name'] ?? ''),
				'accountId' => trim((string)($row['accountId'] ?? '')),
				'required' => $required,
				'requiredBy' => $requiredBy,
			];
		}//end foreach

		return $standing;

	}//end standing()

	/**
	 * The recipients added for this message only.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows as sent.
	 *
	 * @return array<int,array<string,mixed>> The normalised additions.
	 *
	 * @throws RecipientSelectionRefusedException When an addition has no address.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-an-addition-applies-to-one-message-only-req-mrs-002
	 */
	private function added(array $rows): array {
		$added = [];
		foreach ($rows as $row) {
			$address = trim((string)($row['address'] ?? ''));
			if ($address === '') {
				throw new RecipientSelectionRefusedException(
					self::REFUSED_ADDITION_WITHOUT_ADDRESS,
					$this->l10n->t('An added recipient has no address.')
				);
			}

			$accountId = trim((string)($row['accountId'] ?? ''));
			$added[] = [
				'address' => $address,
				'name' => (string)($row['name'] ?? ''),
				'accountId' => $accountId,
				'external' => ($accountId === ''),
			];
		}

		return $added;

	}//end added()

	/**
	 * The recipients suppressed for this message only, each with its reason, user and time.
	 *
	 * @param array<int,array<string,mixed>> $rows     The rows as sent.
	 * @param array<int,array<string,mixed>> $standing The normalised standing recipients.
	 * @param string                         $userId   The fallback user.
	 *
	 * @return array<int,array<string,mixed>> The recorded suppressions.
	 *
	 * @throws RecipientSelectionRefusedException On an unknown, a required or a reasonless suppression.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-suppression-carries-a-reason-and-is-refused-without-one-req-mrs-003
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-a-required-recipient-cannot-be-suppressed-req-mrs-004
	 */
	private function suppressed(array $rows, array $standing, string $userId): array {
		$byKey = [];
		foreach ($standing as $row) {
			$byKey[$this->key(address: $row['address'])] = $row;
		}

		$suppressed = [];
		foreach ($rows as $row) {
			$address = trim((string)($row['address'] ?? ''));
			$target = ($byKey[$this->key(address: $address)] ?? null);
			if ($address === '' || $target === null) {
				throw new RecipientSelectionRefusedException(
					self::REFUSED_UNKNOWN,
					$this->l10n->t('The suppression names %1$s, who is not a recipient of this message.', [$address]),
					$address
				);
			}

			if ($target['required'] === true) {
				throw new RecipientSelectionRefusedException(
					self::REFUSED_REQUIRED,
					$this->l10n->t('%1$s cannot be left out of this message: %2$s', [$target['address'], $target['requiredBy']]),
					$target['address']
				);
			}

			$reason = trim((string)($row['reason'] ?? ''));
			if ($reason === '') {
				throw new RecipientSelectionRefusedException(
					self::REFUSED_NO_REASON,
					$this->l10n->t('Leaving %1$s out of this message needs a reason.', [$target['address']]),
					$target['address']
				);
			}

			$suppressed[] = [
				'address' => $target['address'],
				'name' => $target['name'],
				'state' => self::STATE_SUPPRESSED,
				'reason' => $reason,
				'user' => $this->suppressedBy(row: $row, userId: $userId),
				'at' => $this->suppressedAt(row: $row),
			];
		}//end foreach

		return $suppressed;

	}//end suppressed()

	/**
	 * Who suppressed: the user the row names, else the decision's user.
	 *
	 * @param array<string,mixed> $row    The suppression as sent.
	 * @param string              $userId The fallback user.
	 *
	 * @return string The user id.
	 */
	private function suppressedBy(array $row, string $userId): string {
		$user = trim((string)($row['user'] ?? ''));
		if ($user === '') {
			return $userId;
		}

		return $user;

	}//end suppressedBy()

	/**
	 * When it was suppressed: the time the row names, else now.
	 *
	 * @param array<string,mixed> $row The suppression as sent.
	 *
	 * @return string An ISO 8601 timestamp.
	 */
	private function suppressedAt(array $row): string {
		$at = trim((string)($row['at'] ?? ''));
		if ($at === '') {
			return (new DateTimeImmutable())->format('c');
		}

		return $at;

	}//end suppressedAt()

	/**
	 * The list rows of one part, plain address strings accepted.
	 *
	 * @param mixed $value The part as sent.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function rows(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			if (is_string($row) === true) {
				$row = ['address' => $row];
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;

	}//end rows()

	/**
	 * The comparison key of an address: trimmed and case-insensitive.
	 *
	 * @param string $address The address.
	 *
	 * @return string The key.
	 */
	private function key(string $address): string {
		return mb_strtolower(trim($address));

	}//end key()
}//end class
