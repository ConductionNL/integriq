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

		$row = new OptOut();
		$row->setAddress($this->recipientKeyFor(request: $request, channel: $channel));
		$this->setTarget(row: $row, target: ['scope' => $scope, 'channel' => $channel, 'ref' => $ref]);
		$row->setState($state);
		$this->setWish(row: $row, request: $request);
		$this->setOrigin(row: $row, request: $request);

		$now = $this->time->getTime();
		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);
		$row->assignDedupeKey();

		return $row;

	}//end rowFor()

	/**
	 * The request's address as a recipient key on its channel.
	 *
	 * @param array  $request The request.
	 * @param string $channel The validated channel.
	 *
	 * @return string The recipient key.
	 *
	 * @throws InvalidArgumentException When the address cannot be used on the channel.
	 */
	private function recipientKeyFor(array $request, string $channel): string {
		$key = $this->recipientKey->normalise(channel: $channel, address: (string)($request['address'] ?? ''));
		if ($key === null) {
			throw new InvalidArgumentException('The address cannot be used on the "' . $channel . '" channel.');
		}

		return $key;

	}//end recipientKeyFor()

	/**
	 * Set what the wish covers: the scope, the channel and the case or list it names.
	 *
	 * @param OptOut                                      $row    The row.
	 * @param array{scope:string,channel:string,ref:string} $target The validated scope, channel and ref.
	 *
	 * @return void
	 */
	private function setTarget(OptOut $row, array $target): void {
		$row->setScope($target['scope']);
		$row->setChannel($target['channel']);
		$row->setCaseRef('');
		$row->setListRef('');
		if ($target['scope'] === OptOutRegistry::SCOPE_CASE) {
			$row->setCaseRef($target['ref']);
		}

		if ($target['scope'] === OptOutRegistry::SCOPE_LIST) {
			$row->setListRef($target['ref']);
		}

	}//end setTarget()

	/**
	 * Set the wish's purpose, contact, lawful basis and evidence (kept only when it is a non-empty list).
	 *
	 * @param OptOut $row     The row.
	 * @param array  $request The request.
	 *
	 * @return void
	 */
	private function setWish(OptOut $row, array $request): void {
		$evidence = ($request['evidence'] ?? null);
		$row->setPurpose($this->categories->normalisePurpose((string)($request['purpose'] ?? '')));
		$row->setContactRef((string)($request['contactRef'] ?? ''));
		$row->setLawfulBasis((string)($request['lawfulBasis'] ?? ''));
		$row->setEvidence(null);
		if (is_array($evidence) === true && $evidence !== []) {
			$row->setEvidence((string)json_encode($evidence));
		}

	}//end setWish()

	/**
	 * Set where the wish came from: the source, the asking app and the legacy reference when set.
	 *
	 * @param OptOut $row     The row.
	 * @param array  $request The request.
	 *
	 * @return void
	 */
	private function setOrigin(OptOut $row, array $request): void {
		$row->setSource((string)($request['source'] ?? ''));
		$row->setSourceApp((string)($request['sourceApp'] ?? ''));
		$legacyRef = trim((string)($request['legacyRef'] ?? ''));
		$row->setLegacyUuid(null);
		if ($legacyRef !== '') {
			$row->setLegacyUuid($legacyRef);
		}

	}//end setOrigin()

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
		$state = $this->validState(request: $request);
		$scope = $this->validScope(request: $request);
		$channel = strtolower(trim((string)($request['channel'] ?? '')));
		$ref = trim((string)($request['ref'] ?? ''));
		$this->assertTargetNamed(scope: $scope, target: ['channel' => $channel, 'ref' => $ref]);

		return [$state, $scope, $channel, $ref];

	}//end validated()

	/**
	 * The requested state.
	 *
	 * @param array $request The request.
	 *
	 * @return string Opted-out or opted-in.
	 *
	 * @throws InvalidArgumentException For any other state.
	 */
	private function validState(array $request): string {
		$state = (string)($request['state'] ?? '');
		if (in_array($state, [OptOut::STATE_OPTED_OUT, OptOut::STATE_OPTED_IN], true) === false) {
			throw new InvalidArgumentException('State must be opted-out, opted-in or erase-contact, not "' . $state . '".');
		}

		return $state;

	}//end validState()

	/**
	 * The requested scope, instance by default.
	 *
	 * @param array $request The request.
	 *
	 * @return string One of the registry's scopes.
	 *
	 * @throws InvalidArgumentException For any other scope.
	 */
	private function validScope(array $request): string {
		$scope = (string)($request['scope'] ?? OptOutRegistry::SCOPE_INSTANCE);
		if (in_array($scope, OptOutRegistry::SCOPES, true) === false) {
			throw new InvalidArgumentException('Scope must be instance, channel, case or list, not "' . $scope . '".');
		}

		return $scope;

	}//end validScope()

	/**
	 * Refuse a channel scope without a channel, and a case or list scope without a ref.
	 *
	 * @param string                           $scope  The validated scope.
	 * @param array{channel:string,ref:string} $target The channel and ref the request names.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the scope's target is missing.
	 */
	private function assertTargetNamed(string $scope, array $target): void {
		if ($scope === OptOutRegistry::SCOPE_CHANNEL && $target['channel'] === '') {
			throw new InvalidArgumentException('A channel scope needs a channel.');
		}

		if (in_array($scope, [OptOutRegistry::SCOPE_CASE, OptOutRegistry::SCOPE_LIST], true) === true && $target['ref'] === '') {
			throw new InvalidArgumentException('A ' . $scope . ' scope needs a ref.');
		}

	}//end assertTargetNamed()

	/**
	 * Give a stored row the purpose and key it has under the purpose rules.
	 *
	 * A row written before purposes were read kept its purpose out of its
	 * key. Without a new key the next write for the same wish would miss it
	 * and add a second row.
	 *
	 * @param OptOut $row The stored row.
	 *
	 * @return bool True when the row changed and needs saving.
	 *
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function rekey(OptOut $row): bool {
		$before = [(string)$row->getPurpose(), (string)$row->getDedupeKey()];
		$row->setPurpose($this->categories->normalisePurpose((string)$row->getPurpose()));
		$row->assignDedupeKey();

		return $before !== [(string)$row->getPurpose(), (string)$row->getDedupeKey()];

	}//end rekey()

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
		$this->takeRequest(stored: $stored, row: $row);
		if ($row->getState() === OptOut::STATE_OPTED_IN) {
			$this->applyOptIn(stored: $stored, row: $row);
			return;
		}

		if ($previous === OptOut::STATE_OPTED_IN) {
			$stored->setWithdrawnAt((int)$row->getUpdatedAt());
		}

	}//end applyChange()

	/**
	 * Take the request's state, source, asking app and time, and its contact when it names one.
	 *
	 * @param OptOut $stored The stored row.
	 * @param OptOut $row    The requested row.
	 *
	 * @return void
	 */
	private function takeRequest(OptOut $stored, OptOut $row): void {
		$stored->setState((string)$row->getState());
		$stored->setSource((string)$row->getSource());
		$stored->setSourceApp((string)$row->getSourceApp());
		$stored->setUpdatedAt((int)$row->getUpdatedAt());
		if ((string)$row->getContactRef() !== '') {
			$stored->setContactRef((string)$row->getContactRef());
		}

	}//end takeRequest()

	/**
	 * Take an opt-in's lawful basis and evidence, and clear any withdrawal.
	 *
	 * @param OptOut $stored The stored row.
	 * @param OptOut $row    The requested opt-in.
	 *
	 * @return void
	 */
	private function applyOptIn(OptOut $stored, OptOut $row): void {
		$stored->setLawfulBasis((string)$row->getLawfulBasis());
		$stored->setEvidence($row->getEvidence());
		$stored->setWithdrawnAt(null);

	}//end applyOptIn()

}//end class
