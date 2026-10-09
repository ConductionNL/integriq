<?php

/**
 * Integriq OptOutChangeRequested Event.
 *
 * The ADR-041 command a sibling app sends to record a person's wish in
 * integriq's opt-out table: an opt-out, a consent with its lawful basis and
 * evidence, or the erasure of a contact (which keeps the opt-out). pipelinq's
 * STOP and START keywords, its preference centre and its consent migration
 * all land here. A request with a `legacyRef` already recorded writes nothing
 * and returns the earlier record id, so a migration run twice writes once.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "record this wish".
 *
 * The result slot has the same shape as DigitalPostSendRequestedEvent's:
 * handled, a record id, or a structured refusal.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the ADR-041 event contract is a flat
 * readonly envelope the consumer stubs mirror verbatim.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */
class OptOutChangeRequestedEvent extends Event {

	/**
	 * Whether integriq handled the request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The record id, once recorded. For an erasure, the rows cleared.
	 *
	 * @var int|null
	 */
	private ?int $recordId = null;

	/**
	 * The structured refusal, when the request was refused.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The recording app id.
	 * @param string $address The person's address; may be empty for `erase-contact`.
	 * @param string $state `opted-out`, `opted-in` or `erase-contact`.
	 * @param string $scope `instance`, `channel`, `case` or `list`.
	 * @param string $channel The channel, for a channel scope.
	 * @param string $ref The case for a case scope, the list for a list scope.
	 * @param string $contactRef The sibling app's contact id.
	 * @param string $lawfulBasis For `opted-in`: the AVG article 6 basis.
	 * @param array<string,mixed> $evidence For `opted-in`: what was shown.
	 * @param string $source What triggered it, for example `keyword-stop`.
	 * @param string $correlationId The caller's correlation id.
	 * @param string $legacyRef The sibling app's record id, for an idempotent migration.
	 * @param string $purpose What a consent is for, for example `marketing`.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $address,
		private readonly string $state,
		private readonly string $scope = 'instance',
		private readonly string $channel = '',
		private readonly string $ref = '',
		private readonly string $contactRef = '',
		private readonly string $lawfulBasis = '',
		private readonly array $evidence = [],
		private readonly string $source = '',
		private readonly string $correlationId = '',
		private readonly string $legacyRef = '',
		private readonly string $purpose = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The request as OptOutRegistry::record() reads it.
	 *
	 * @return array<string,mixed> The request.
	 */
	public function toRequest(): array {
		return [
			'sourceApp' => $this->sourceApp,
			'address' => $this->address,
			'state' => $this->state,
			'scope' => $this->scope,
			'channel' => $this->channel,
			'ref' => $this->ref,
			'contactRef' => $this->contactRef,
			'lawfulBasis' => $this->lawfulBasis,
			'evidence' => $this->evidence,
			'source' => $this->source,
			'correlationId' => $this->correlationId,
			'legacyRef' => $this->legacyRef,
			'purpose' => $this->purpose,
		];

	}//end toRequest()

	/**
	 * The recording app id.
	 *
	 * @return string The app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;

	}//end getSourceApp()

	/**
	 * The requested state.
	 *
	 * @return string The state.
	 */
	public function getState(): string {
		return $this->state;

	}//end getState()

	/**
	 * The caller's correlation id.
	 *
	 * @return string The id.
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;

	}//end getCorrelationId()

	/**
	 * Mark the request handled.
	 *
	 * @param bool $handled Whether it was handled.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;

	}//end setHandled()

	/**
	 * Whether integriq handled the request.
	 *
	 * @return bool True when handled.
	 */
	public function isHandled(): bool {
		return $this->handled;

	}//end isHandled()

	/**
	 * Record the id of the stored row.
	 *
	 * @param int $recordId The id.
	 *
	 * @return void
	 */
	public function setRecordId(int $recordId): void {
		$this->recordId = $recordId;

	}//end setRecordId()

	/**
	 * The id of the stored row, once recorded.
	 *
	 * @return int|null The id.
	 */
	public function getRecordId(): ?int {
		return $this->recordId;

	}//end getRecordId()

	/**
	 * Record a structured refusal.
	 *
	 * @param string $reason Why the request was refused.
	 * @param string $code A machine-readable code.
	 *
	 * @return void
	 */
	public function setRefusal(string $reason, string $code = 'refused'): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];

	}//end setRefusal()

	/**
	 * The structured refusal, when there was one.
	 *
	 * @return array<string,mixed>|null The refusal.
	 */
	public function getRefusal(): ?array {
		return $this->refusal;

	}//end getRefusal()

}//end class
