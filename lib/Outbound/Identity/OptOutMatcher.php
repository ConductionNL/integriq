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
	 * @param SendDecisionRun $run The batch, with the chunk's rows.
	 * @param AddressedRecipient $addressed The recipient: its address, and its contact when it names one.
	 *
	 * @return list<OptOut> The recipient's rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-contact-erasure-keeps-the-opt-out-req-ooa-010
	 */
	public function rowsOf(SendDecisionRun $run, AddressedRecipient $addressed): array {
		$key = (string)$addressed->key;
		$contactRef = (string)($addressed->recipient['contactRef'] ?? '');

		return array_values(
			array_filter(
				$run->rows,
				static fn (OptOut $row): bool => $row->getAddress() === $key
					|| ($contactRef !== '' && (string)$row->getContactRef() === $contactRef)
			)
		);

	}//end rowsOf()

	/**
	 * The opt-out that stops this send, if any.
	 *
	 * A row stops it only when its purpose covers the batch's category.
	 *
	 * @param SendDecisionRun $run The batch: its channel and canonical category.
	 * @param AddressedRecipient $addressed The recipient, with its own rows.
	 *
	 * @return OptOut|null The opt-out.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function matchingOptOut(SendDecisionRun $run, AddressedRecipient $addressed): ?OptOut {
		$scope = new RecipientSendScope(channel: $run->channel, recipient: $addressed->recipient);
		foreach ($addressed->mine as $row) {
			if ($this->isOptOutFor(row: $row, category: $run->category) === true && $this->covers(row: $row, scope: $scope) === true) {
				return $row;
			}
		}

		return null;

	}//end matchingOptOut()

	/**
	 * Whether a row is an opt-out (or a stateless legacy row) whose purpose covers this category.
	 *
	 * @param OptOut $row The row.
	 * @param string $category The canonical category.
	 *
	 * @return bool True when the row can stop the send.
	 */
	private function isOptOutFor(OptOut $row, string $category): bool {
		$state = (string)$row->getState();
		if ($state !== '' && $state !== OptOut::STATE_OPTED_OUT) {
			return false;
		}

		return $this->purposeCovers(purpose: (string)$row->getPurpose(), category: $category);

	}//end isOptOutFor()

	/**
	 * Whether a row with this purpose covers a message of this category: an
	 * empty purpose covers every category, otherwise the category's group must match.
	 *
	 * @param string $purpose The row's purpose.
	 * @param string $category The canonical category.
	 *
	 * @return bool True when the row covers it.
	 */
	private function purposeCovers(string $purpose, string $category): bool {
		$purpose = strtolower(trim($purpose));
		if ($purpose === OptOutCategories::PURPOSE_ALL) {
			return true;
		}

		return ((OptOutCategories::PURPOSE_OF[strtolower(trim($category))] ?? null) === $purpose);

	}//end purposeCovers()

	/**
	 * Whether a row's scope covers this send.
	 *
	 * @param OptOut $row The row.
	 * @param RecipientSendScope $scope The send's channel, case and list.
	 *
	 * @return bool True when it covers it.
	 */
	private function covers(OptOut $row, RecipientSendScope $scope): bool {
		return match ((string)$row->getScope()) {
			OptOutRegistry::SCOPE_INSTANCE => true,
			OptOutRegistry::SCOPE_CHANNEL => $scope->channel !== '' && (string)$row->getChannel() === $scope->channel,
			OptOutRegistry::SCOPE_CASE => $scope->caseRef !== '' && (string)$row->getCaseRef() === $scope->caseRef,
			OptOutRegistry::SCOPE_LIST => $scope->listRef !== '' && (string)$row->getListRef() === $scope->listRef,
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
	 * A consent permits it only when its purpose covers the batch's category.
	 *
	 * @param SendDecisionRun $run The batch: its channel and canonical category.
	 * @param AddressedRecipient $addressed The recipient, with its own rows.
	 *
	 * @return bool True when a consent permits it.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-marketing-needs-recorded-consent-req-ooa-005
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function hasConsent(SendDecisionRun $run, AddressedRecipient $addressed): bool {
		$scope = new RecipientSendScope(channel: $run->channel, recipient: $addressed->recipient);
		foreach ($addressed->mine as $row) {
			if ($this->isConsentFor(row: $row, category: $run->category) === true
				&& $this->consentCovers(row: $row, scope: $scope) === true
				&& $this->basisPermits(row: $row) === true
			) {
				return true;
			}
		}

		return false;

	}//end hasConsent()

	/**
	 * Whether a row is a standing consent whose purpose covers this category.
	 *
	 * @param OptOut $row The row.
	 * @param string $category The canonical category.
	 *
	 * @return bool True when the row is an opt-in that was not withdrawn and covers the category.
	 */
	private function isConsentFor(OptOut $row, string $category): bool {
		if ((string)$row->getState() !== OptOut::STATE_OPTED_IN || $row->getWithdrawnAt() !== null) {
			return false;
		}

		return $this->purposeCovers(purpose: (string)$row->getPurpose(), category: $category);

	}//end isConsentFor()

	/**
	 * Whether a consent row covers this send: a list row for a list send, a
	 * channel row for anything else.
	 *
	 * @param OptOut $row The row.
	 * @param RecipientSendScope $scope The send's channel and list.
	 *
	 * @return bool True when it covers it.
	 */
	private function consentCovers(OptOut $row, RecipientSendScope $scope): bool {
		if ($scope->listRef !== '') {
			return ((string)$row->getScope() === OptOutRegistry::SCOPE_LIST && (string)$row->getListRef() === $scope->listRef);
		}

		return ((string)$row->getScope() === OptOutRegistry::SCOPE_CHANNEL && (string)$row->getChannel() === $scope->channel);

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
