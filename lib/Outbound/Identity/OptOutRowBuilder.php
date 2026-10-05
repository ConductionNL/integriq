<?php

/**
 * Integriq OptOutRowBuilder.
 *
 * Turns a recorded wish into the opt-out row it stands for, and moves a
 * stored row to a new state: an opt-in clears a withdrawal, an opt-out after
 * an opt-in records one. The address is normalised per channel, so a BSN is
 * stored as its hash.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use InvalidArgumentException;
use OCA\Integriq\Db\OptOut;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Builds and changes opt-out rows.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */
class OptOutRowBuilder {

	/**
	 * Constructor.
	 *
	 * @param RecipientKey $recipientKey Normalises the address per channel.
	 * @param ITimeFactory $time Stamps the row.
	 * @param OptOutCategories $categories Normalises the purpose.
	 */
	public function __construct(
		private readonly RecipientKey $recipientKey,
		private readonly ITimeFactory $time,
		private readonly OptOutCategories $categories,
	) {

	}//end __construct()

	/**
	 * Build the row a request asks for, its dedupe key set.
	 *
	 * @param array<string,mixed> $request The request.
	 *
	 * @return OptOut The row.
	 *
	 * @throws InvalidArgumentException When the request is incomplete.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 */
	public function rowFor(array $request): OptOut {
		[$state, $scope, $channel, $ref] = $this->validated(request: $request);

		$key = $this->recipientKey->normalise(channel: $channel, address: (string)($request['address'] ?? ''));
		if ($key === null) {
			throw new InvalidArgumentException('The address cannot be used on the "' . $channel . '" channel.');
		}

		$evidence = ($request['evidence'] ?? null);
		$now = $this->time->getTime();

		$row = new OptOut();
		$row->setAddress($key);
		$row->setScope($scope);
		$row->setChannel($channel);
		$row->setCaseRef('');
		$row->setListRef('');
		if ($scope === OptOutRegistry::SCOPE_CASE) {
			$row->setCaseRef($ref);
		}

		if ($scope === OptOutRegistry::SCOPE_LIST) {
			$row->setListRef($ref);
		}

		$row->setState($state);
		$row->setPurpose($this->categories->normalisePurpose((string)($request['purpose'] ?? '')));
		$row->setContactRef((string)($request['contactRef'] ?? ''));
		$row->setLawfulBasis((string)($request['lawfulBasis'] ?? ''));
		$row->setEvidence(null);
		if (is_array($evidence) === true && $evidence !== []) {
			$row->setEvidence((string)json_encode($evidence));
		}

		$row->setSource((string)($request['source'] ?? ''));
		$row->setSourceApp((string)($request['sourceApp'] ?? ''));
		$legacyRef = trim((string)($request['legacyRef'] ?? ''));
		$row->setLegacyUuid(null);
		if ($legacyRef !== '') {
			$row->setLegacyUuid($legacyRef);
		}

		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);
		$row->assignDedupeKey();

		return $row;

	}//end rowFor()

	/**
	 * The state, scope, channel and ref of a request, checked.
	 *
	 * @param array<string,mixed> $request The request.
	 *
	 * @return array{0:string,1:string,2:string,3:string} State, scope, channel, ref.
	 *
	 * @throws InvalidArgumentException When the request is incomplete.
	 */
	private function validated(array $request): array {
		$state = (string)($request['state'] ?? '');
		if (in_array($state, [OptOut::STATE_OPTED_OUT, OptOut::STATE_OPTED_IN], true) === false) {
			throw new InvalidArgumentException('State must be opted-out, opted-in or erase-contact, not "' . $state . '".');
		}

		$scope = (string)($request['scope'] ?? OptOutRegistry::SCOPE_INSTANCE);
		if (in_array($scope, OptOutRegistry::SCOPES, true) === false) {
			throw new InvalidArgumentException('Scope must be instance, channel, case or list, not "' . $scope . '".');
		}

		$channel = strtolower(trim((string)($request['channel'] ?? '')));
		$ref = trim((string)($request['ref'] ?? ''));
		if ($scope === OptOutRegistry::SCOPE_CHANNEL && $channel === '') {
			throw new InvalidArgumentException('A channel scope needs a channel.');
		}

		if (in_array($scope, [OptOutRegistry::SCOPE_CASE, OptOutRegistry::SCOPE_LIST], true) === true && $ref === '') {
			throw new InvalidArgumentException('A ' . $scope . ' scope needs a ref.');
		}

		return [$state, $scope, $channel, $ref];

	}//end validated()

	/**
	 * Move a stored row to the state a new request asks for.
	 *
	 * @param OptOut $stored The stored row.
	 * @param OptOut $row The requested row.
	 * @param string $previous The stored row's state before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 */
	public function applyChange(OptOut $stored, OptOut $row, string $previous): void {
		$stored->setState((string)$row->getState());
		$stored->setSource((string)$row->getSource());
		$stored->setSourceApp((string)$row->getSourceApp());
		$stored->setUpdatedAt((int)$row->getUpdatedAt());
		if ((string)$row->getContactRef() !== '') {
			$stored->setContactRef((string)$row->getContactRef());
		}

		if ($row->getState() === OptOut::STATE_OPTED_IN) {
			$stored->setLawfulBasis((string)$row->getLawfulBasis());
			$stored->setEvidence($row->getEvidence());
			$stored->setWithdrawnAt(null);
			return;
		}

		if ($previous === OptOut::STATE_OPTED_IN) {
			$stored->setWithdrawnAt((int)$row->getUpdatedAt());
		}

	}//end applyChange()

}//end class
