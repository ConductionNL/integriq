<?php

/**
 * Contract of the ExecutionTraceService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Public operations of {@see ExecutionTraceService} that other components call.
 *
 * The method signatures repeat those of ExecutionTraceService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface ExecutionTraceServiceInterface {
	/**
	 * Persist the assembled `ExecutionTraceContext` as one `execution_trace`
	 * object, using the minted `traceId` as the object's own uuid — a single
	 * create for every entry point EXCEPT the approval-suspend/resume
	 * continuation, where the SAME object (matched by `traceId`) is updated
	 * (REQ-004). `saveObject()`'s uuid-upsert semantics make create-vs-update
	 * automatic: passing the same `traceId` twice always resolves to one row.
	 *
	 * @param ExecutionTraceContext $trace The assembled context to persist.
	 * @param string $status running|success|failed|short_circuited.
	 * @param array|null $error Terminal error {message, ruleType, ruleName}, when applicable.
	 * @param boolean $resume Documents that this call is the approval-resume continuation
	 *                        (design.md Decision 2) — informational only; the
	 *                        upsert-by-uuid behaviour is identical either way.
	 *
	 * @return ObjectEntity The persisted execution_trace object.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004
	 */
	public function persist(ExecutionTraceContext $trace, string $status, ?array $error = null, bool $resume = false): ObjectEntity;

	/**
	 * Read a persisted trace for the export job, as plain data.
	 *
	 * The job runs from cron without a session and the id is one this
	 * service queued itself, never caller input, so OpenRegister's access
	 * layer is bypassed the same way {@see persist()} writes.
	 *
	 * @param string $traceId The trace uuid.
	 *
	 * @return array|null The trace data, or null when it no longer exists.
	 *
	 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-export-never-delays-the-traced-work-req-otel-002
	 */
	public function findForExport(string $traceId): ?array;

	/**
	 * Find one execution_trace by id.
	 *
	 * @param string $traceId The trace uuid.
	 *
	 * @return ObjectEntity|null The trace, or null when not found.
	 *
	 * @spec exclude Not a CRUD pass-through with no callers (ADR-022). It pins
	 *       the register/schema pair and converts a MISS from a throw into
	 *       null. Both callers (ExecutionTracesController::show and replay()
	 *       below) depend on exactly that shape; deleting it duplicates the
	 *       arguments and a try/catch at each of them.
	 *
	 * ⚠️ This method used to pass `_rbac: false, _multitenancy: false`, on the
	 * reason *"a trace is infrastructure telemetry, not a tenant-owned
	 * object"*. That reason justifies the WRITE in persist(), which runs from
	 * background jobs with no session. It does not justify the READ: both
	 * callers are `#[NoAdminRequired]` HTTP endpoints taking a caller-supplied
	 * id, so switching OR's own layer off in source made this the only place
	 * an access decision could ever be made — and none was. The decision now
	 * lives in the controllers' `ActionAuthService::requireAction()` calls, and
	 * OR's layer is left at its defaults so it can become a second one.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
	 *
	 * @throws \Throwable Anything that is not a miss — an OpenRegister outage,
	 *                    an unresolvable schema — reaches the caller. Only
	 *                    DoesNotExistException becomes null.
	 */
	public function find(string $traceId): ?ObjectEntity;

	/**
	 * List execution_trace objects with optional filters and pagination,
	 * matching the `LogsController::index` pattern (`logs-and-statistics`
	 * REQ-001).
	 *
	 * @param array $filters Additional OR filters (e.g. `entryPoint`, `status`).
	 * @param integer|null $limit Maximum number of results (default 20).
	 * @param integer|null $offset Starting offset (default 0).
	 *
	 * @return array{results: array, total: integer}
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
	 */
	public function list(array $filters = [], ?int $limit = 20, ?int $offset = 0): array;

	/**
	 * Replay a traced execution: dry-run by default (REQ-005), an explicit
	 * `force: true` performs the real write via the original entry point's
	 * own dispatch mechanism (REQ-006). Every call creates a NEW
	 * `execution_trace` linked via `replayOf` — the original trace is never
	 * mutated.
	 *
	 * @param string $traceId The original trace's id.
	 * @param string $actorUid The acting operator's user id (event-kind replay audit).
	 * @param boolean $force False (default) = dry-run preview; true = real write.
	 *
	 * @return ObjectEntity The new, linked execution_trace.
	 *
	 * @throws DoesNotExistException When no such trace exists (controller maps to 404).
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 * @spec openspec/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006
	 */
	public function replay(string $traceId, string $actorUid, bool $force = false): ObjectEntity;
}
