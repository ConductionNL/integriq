<?php

/**
 * Integriq OptOutMatcher.
 *
 * Which of a recipient's rows stops a send, and whether a recorded consent
 * permits one. Pure: it reads only the rows it is given. OptOutRegistry reads
 * the rows and makes the decision; this class holds the matching rules of
 * design section 1, so they can be read in one place.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-marketing-needs-recorded-consent-req-ooa-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use OCA\Integriq\Db\OptOut;

/**
 * The matching rules.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-marketing-needs-recorded-consent-req-ooa-005
 */
class OptOutMatcher {

	/**
	 * The rows that belong to one recipient: same address, or same contact.
	 *
	 * @param list<OptOut> $rows The chunk's rows.
	 * @param string $key The address.
	 * @param string $contactRef The contact, or empty.
	 *
	 * @return list<OptOut> The recipient's rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-contact-erasure-keeps-the-opt-out-req-ooa-010
	 */
	public function rowsOf(array $rows, string $key, string $contactRef): array {
		return array_values(
			array_filter(
				$rows,
				static fn (OptOut $row): bool => $row->getAddress() === $key
					|| ($contactRef !== '' && (string)$row->getContactRef() === $contactRef)
			)
		);

	}//end rowsOf()

	/**
	 * The opt-out that stops this send, if any.
	 *
	 * @param list<OptOut> $rows The recipient's rows.
	 * @param array<string,mixed> $recipient The recipient.
	 * @param string $channel The channel.
	 *
	 * @return OptOut|null The opt-out.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function matchingOptOut(array $rows, array $recipient, string $channel): ?OptOut {
		$caseRef = (string)($recipient['caseRef'] ?? '');
		$listRef = (string)($recipient['listRef'] ?? '');
		foreach ($rows as $row) {
			$state = (string)$row->getState();
			if ($state !== '' && $state !== OptOut::STATE_OPTED_OUT) {
				continue;
			}

			if ($this->covers(row: $row, channel: $channel, caseRef: $caseRef, listRef: $listRef) === true) {
				return $row;
			}
		}

		return null;

	}//end matchingOptOut()

	/**
	 * Whether a row's scope covers this send.
	 *
	 * @param OptOut $row The row.
	 * @param string $channel The channel.
	 * @param string $caseRef The case, or empty.
	 * @param string $listRef The list, or empty.
	 *
	 * @return bool True when it covers it.
	 */
	private function covers(OptOut $row, string $channel, string $caseRef, string $listRef): bool {
		return match ((string)$row->getScope()) {
			OptOutRegistry::SCOPE_INSTANCE => true,
			OptOutRegistry::SCOPE_CHANNEL => $channel !== '' && (string)$row->getChannel() === $channel,
			OptOutRegistry::SCOPE_CASE => $caseRef !== '' && (string)$row->getCaseRef() === $caseRef,
			OptOutRegistry::SCOPE_LIST => $listRef !== '' && (string)$row->getListRef() === $listRef,
			default => false,
		};

	}//end covers()

	/**
	 * Whether a recorded consent permits a send that requires one.
	 *
	 * With a list ref only a list row counts; without one only a channel row.
	 * A channel consent does not open a list and a list consent does not open
	 * a channel. `imported` never permits; `soft-opt-in` needs the evidence
	 * that an objection was offered.
	 *
	 * @param list<OptOut> $rows The recipient's rows.
	 * @param array<string,mixed> $recipient The recipient.
	 * @param string $channel The channel.
	 *
	 * @return bool True when a consent permits it.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-marketing-needs-recorded-consent-req-ooa-005
	 */
	public function hasConsent(array $rows, array $recipient, string $channel): bool {
		$listRef = (string)($recipient['listRef'] ?? '');
		foreach ($rows as $row) {
			if ((string)$row->getState() !== OptOut::STATE_OPTED_IN || $row->getWithdrawnAt() !== null) {
				continue;
			}

			if ($this->consentCovers(row: $row, channel: $channel, listRef: $listRef) === true && $this->basisPermits(row: $row) === true) {
				return true;
			}
		}

		return false;

	}//end hasConsent()

	/**
	 * Whether a consent row covers this send: a list row for a list send, a
	 * channel row for anything else.
	 *
	 * @param OptOut $row The row.
	 * @param string $channel The channel.
	 * @param string $listRef The list, or empty.
	 *
	 * @return bool True when it covers it.
	 */
	private function consentCovers(OptOut $row, string $channel, string $listRef): bool {
		if ($listRef !== '') {
			return ((string)$row->getScope() === OptOutRegistry::SCOPE_LIST && (string)$row->getListRef() === $listRef);
		}

		return ((string)$row->getScope() === OptOutRegistry::SCOPE_CHANNEL && (string)$row->getChannel() === $channel);

	}//end consentCovers()

	/**
	 * Whether a consent row's lawful basis permits a send.
	 *
	 * @param OptOut $row The row.
	 *
	 * @return bool True when it does.
	 */
	private function basisPermits(OptOut $row): bool {
		$basis = (string)$row->getLawfulBasis();
		if ($basis === 'imported') {
			return false;
		}

		if ($basis === 'soft-opt-in') {
			return (($row->evidenceArray()['objectionOffered'] ?? false) === true);
		}

		return true;

	}//end basisPermits()

}//end class
