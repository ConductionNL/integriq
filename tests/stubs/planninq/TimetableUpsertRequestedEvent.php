<?php

/**
 * CONTRACT FIXTURE, NOT A STUB: a verbatim copy of planninq's
 * `lib/Event/TimetableUpsertRequestedEvent.php` at ConductionNL/planninq
 * commit 84703dd (PR #685, change school-timetable-target, contract v1).
 *
 * Integriq never imports this class; PlanninqTimetableTarget looks it up by
 * name. The tests load this copy so the dispatch runs against the REAL
 * constructor and getters: a parameter planninq renames fails these tests
 * instead of failing silently on an instance. Refresh it by copying the
 * file again when planninq bumps CONTRACT_VERSION. The code is identical;
 * the only edit is that planninq's `@spec` tags read `planninq-spec:` here,
 * because those anchors live in the planninq repository, not this one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

/**
 * Planninq Timetable Upsert Requested Event
 *
 * The public, typed door another app uses to deliver a school timetable into
 * planninq (ADR-041: cross-app commands are typed events). The integriq
 * rostering adapter dispatches it; planninq's own listener upserts the batch
 * and writes the result back into this event before dispatch returns.
 *
 * Consumers never import this class. They look it up by name, guard it with
 * `class_exists()`, construct it with named arguments and read the result
 * slot; when the class is absent or the event comes back unhandled, planninq
 * is not installed and the consumer fails closed. The constructor and getters
 * are the contract: `openspec/changes/school-timetable-target/contract.md`.
 *
 * @category Event
 * @package  OCA\Planninq\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
 */

declare(strict_types=1);

namespace OCA\Planninq\Event;

use OCP\EventDispatcher\Event;

/**
 * A request to upsert one batch of timetable sessions from one source.
 *
 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
 */
class TimetableUpsertRequestedEvent extends Event {

	/**
	 * Contract version of this event and its result.
	 *
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The upsert result, once planninq handled the event.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Constructor.
	 *
	 * @param string           $sourceApp     The app dispatching the request, e.g. `integriq`.
	 * @param string           $sourceSystem  The source every row is stored under, e.g. `roster-zermelo`.
	 * @param array<int,mixed> $sessions      Rows in the contract's session shape.
	 * @param string           $correlationId The caller's job or run id, for its own logs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $sourceSystem,
		private readonly array $sessions,
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app dispatching the request.
	 *
	 * @return string
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The source every row is stored under.
	 *
	 * @return string
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
	 */
	public function getSourceSystem(): string {
		return $this->sourceSystem;
	}//end getSourceSystem()

	/**
	 * The rows to upsert.
	 *
	 * @return array<int,mixed>
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
	 */
	public function getSessions(): array {
		return $this->sessions;
	}//end getSessions()

	/**
	 * The caller's job or run id.
	 *
	 * @return string
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Record the upsert result; this also marks the event handled.
	 *
	 * @param array<string,mixed> $result The contract's upsert result.
	 *
	 * @return void
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
	 */
	public function setResult(array $result): void {
		$this->result = $result;
	}//end setResult()

	/**
	 * The upsert result, or null when nothing handled the event.
	 *
	 * @return array<string,mixed>|null
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Whether planninq handled the event.
	 *
	 * @return bool
	 *
	 * planninq-spec: openspec/changes/school-timetable-target/specs/school-timetable/spec.md#requirement-another-app-delivers-a-batch-through-a-typed-event-req-004
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()
}//end class
