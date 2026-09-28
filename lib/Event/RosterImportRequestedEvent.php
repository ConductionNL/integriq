<?php

/**
 * Integriq Roster Import Requested Event.
 *
 * The ADR-041 cross-app command another app uses to have integriq deliver a
 * rostering source (Zermelo, Untis, Xedule, TimeEdit) into planninq, the
 * fleet's timetable owner (decision D10). Learniq's `timetable-import` job
 * dispatches it. Integriq's listener always answers through the result slot,
 * with `status: delivered` and planninq's counts or `status: failed` and an
 * error code. The consumer MUST guard the dispatch with class_exists() and
 * treat an absent class or an unhandled event as a failed import.
 *
 * Contract: `openspec/changes/rostering-adapter-targets-planninq/contract.md`.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "deliver this rostering source into planninq".
 *
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */
class RosterImportRequestedEvent extends Event {
	/**
	 * Contract version of this event and its result.
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The delivery result, once integriq handled the event.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string              $sourceApp     The app asking, e.g. `learniq`.
	 * @param string              $systemId      The rostering Source row id.
	 * @param array<string,mixed> $options       Optional `groupMap` and `teacherMap`.
	 * @param string              $correlationId The caller's job id.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $systemId,
		private readonly array $options = [],
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app asking.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The rostering Source row id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function getSystemId(): string {
		return $this->systemId;
	}//end getSystemId()

	/**
	 * The delivery options.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function getOptions(): array {
		return $this->options;
	}//end getOptions()

	/**
	 * The caller's job id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Record the delivery result; this also marks the event handled.
	 *
	 * @param array<string,mixed> $result The contract's result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function setResult(array $result): void {
		$this->result = $result;
	}//end setResult()

	/**
	 * The delivery result, or null when nothing handled the event.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Whether integriq handled the event.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()
}//end class
