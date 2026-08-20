<?php

/**
 * OpenConnector ExecutionTraceContext.
 *
 * Lightweight, in-process value object minted once per execution entry point
 * (endpoint call, job run, event delivery, manual synchronization run) and
 * threaded alongside the existing FlowToken (never merged into it — see
 * design.md Decision 1). Buffers one ordered Step per pipeline stage; never
 * performs redaction itself (design.md Decision 3 — callers redact via
 * SensitiveFieldRegistry::redactArray() before calling addStep()) and never
 * persists itself (ExecutionTraceService::persist() owns the OpenRegister
 * write, REQ-004).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service\Helper;

use DateTime;
use Symfony\Component\Uid\Uuid;

/**
 * Carries a traceId and an in-memory ordered Step[] buffer across one
 * logical OpenConnector execution.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
 */
class ExecutionTraceContext {

	/**
	 * UUIDv4 minted once for this execution, shared by every step and the
	 * persisted execution_trace object's own id.
	 *
	 * @var string
	 */
	private string $traceId;

	/**
	 * Which of the four entry points minted this context: endpoint|job|event|sync.
	 *
	 * @var string
	 */
	private string $entryPoint;

	/**
	 * Uuid of the endpoint/job/subscription/synchronization that started the execution.
	 *
	 * @var string|null
	 */
	private ?string $entryPointId;

	/**
	 * Timestamp this context was minted (ISO 8601).
	 *
	 * @var string
	 */
	private string $startedAt;

	/**
	 * Set only on a trace created by replay — the original trace's id.
	 *
	 * @var string|null
	 */
	private ?string $replayOf;

	/**
	 * True when this context was created by a replay (dry-run or forced).
	 *
	 * @var boolean
	 */
	private bool $isReplay;

	/**
	 * True when this context is a dry-run preview replay — write-shaped
	 * pipeline stages MUST NOT perform their write (rule-pipeline REQ-RULE-011).
	 *
	 * @var boolean
	 */
	private bool $dryRun;

	/**
	 * What triggered this execution: http|cron|manual.
	 *
	 * @var string|null
	 */
	private ?string $triggeredBy;

	/**
	 * Ordered in-memory step buffer. Each entry:
	 * {order, type, name, timing, status, durationMs, startedAt, input, output}.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $steps = [];

	/**
	 * Steps dropped once the cap was reached, tallied by status.
	 *
	 * @var array<string, int>
	 */
	private array $droppedByStatus = [];

	/**
	 * How many detailed steps one trace retains.
	 *
	 * A trace records ONE STEP PER SYNCHRONISED OBJECT, and each step carries
	 * that object's `input` and `output`. Unbounded, a synchronization's trace is
	 * therefore a second copy of everything it just imported — held for the whole
	 * run, then written into a single object.
	 *
	 * MEASURED 2026-08-14 on data.overheid.nl. A 19,822-object run finished its
	 * actual work in 186 s — every object written, every contract written, the
	 * log saved — and then spent OVER TEN MINUTES at 100% CPU with no database
	 * activity, inside the one `saveObject()` that persists this array. Nothing
	 * appeared in any log; the run read exactly like a hang, and the objects were
	 * already there, so nothing downstream noticed either.
	 *
	 * Five hundred is far more than anyone reads by hand and far below where the
	 * cost appears. Past it the steps are TALLIED rather than kept, so the trace
	 * still reports what happened — it stops reporting it one row at a time.
	 *
	 * @var int
	 */
	private const MAX_RETAINED_STEPS = 500;

	/**
	 * Constructor. Mints a fresh UUIDv4 traceId unless one is supplied
	 * (replay/rehydration passes the original traceId to continue an
	 * existing trace — design.md Decision 2's approval-resume continuation).
	 *
	 * @param string $entryPoint endpoint|job|event|sync.
	 * @param string|null $entryPointId uuid of the endpoint/job/subscription/synchronization.
	 * @param string|null $traceId Explicit traceId to reuse (resume/rehydration); minted fresh when null.
	 * @param string|null $replayOf Original trace id, when this context is a replay.
	 * @param boolean $isReplay Whether this context was created by a replay.
	 * @param boolean $dryRun Whether this context is a dry-run preview.
	 * @param string|null $triggeredBy http|cron|manual.
	 * @param array<int, array<string, mixed>> $priorSteps Steps already recorded before this context was
	 *                                                     (re)constructed — the
	 *                                                     approval-suspend/resume continuation
	 *                                                     (design.md Decision 2) rehydrates a context
	 *                                                     pre-loaded with the `before`-phase steps so
	 *                                                     `addStep()` continues the SAME ordered
	 *                                                     sequence rather than restarting at order 1.
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004
	 */
	public function __construct(
		string $entryPoint,
		?string $entryPointId = null,
		?string $traceId = null,
		?string $replayOf = null,
		bool $isReplay = false,
		bool $dryRun = false,
		?string $triggeredBy = null,
		array $priorSteps = [],
	) {
		$this->traceId = ($traceId ?? (string)Uuid::v4());
		$this->entryPoint = $entryPoint;
		$this->entryPointId = $entryPointId;
		$this->startedAt = (new DateTime())->format(DateTime::ATOM);
		$this->replayOf = $replayOf;
		$this->isReplay = $isReplay;
		$this->dryRun = $dryRun;
		$this->triggeredBy = $triggeredBy;
		$this->steps = $priorSteps;

	}//end __construct()

	/**
	 * Append one ordered step to the in-memory buffer.
	 *
	 * `$input`/`$output` MUST already be redacted by the caller via
	 * `SensitiveFieldRegistry::redactArray()` (design.md Decision 3) — this
	 * method never redacts, and never re-derives redaction from raw data.
	 *
	 * `$startedAtMicrotime`/`$finishedAtMicrotime` (as returned by
	 * `microtime(true)`) let the caller report the step's own wall-clock
	 * span; when omitted, both default to "now" so `durationMs` is `0` for
	 * steps whose caller does not track sub-step timing.
	 *
	 * @param string $type Step type: rule|mapping|synchronization|call.
	 * @param string $name Human-readable step name (rule name, mapping name, etc.).
	 * @param string|null $timing Pipeline phase the step ran in (before|after), or null when not applicable.
	 * @param string $status success|skipped|skipped_dry_run|error.
	 * @param array<string, mixed> $input Already-redacted input snapshot.
	 * @param array<string, mixed> $output Already-redacted output snapshot.
	 * @param float|null $startedAtMicrotime microtime(true) at step start; defaults to now.
	 * @param float|null $finishedAtMicrotime microtime(true) at step end; defaults to now.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	public function addStep(
		string $type,
		string $name,
		?string $timing,
		string $status,
		array $input = [],
		array $output = [],
		?float $startedAtMicrotime = null,
		?float $finishedAtMicrotime = null,
	): void {
		$start = ($startedAtMicrotime ?? microtime(true));
		$end = ($finishedAtMicrotime ?? $start);

		// Past the cap, count rather than keep. Dropping the step's `input` and
		// `output` is the whole point: those hold the record itself, so retaining
		// them makes the trace a duplicate of the import.
		if (count($this->steps) >= self::MAX_RETAINED_STEPS) {
			$this->droppedByStatus[$status] = (($this->droppedByStatus[$status] ?? 0) + 1);

			return;
		}

		$this->steps[] = [
			'order' => (count($this->steps) + 1),
			'type' => $type,
			'name' => $name,
			'timing' => $timing,
			'status' => $status,
			'durationMs' => (int)round(($end - $start) * 1000),
			'startedAt' => (new DateTime('@' . ((int)$start)))->format(DateTime::ATOM),
			'input' => $input,
			'output' => $output,
		];

	}//end addStep()

	/**
	 * Get the minted traceId.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getTraceId(): string {
		return $this->traceId;
	}//end getTraceId()

	/**
	 * Get the entry point that minted this context.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getEntryPoint(): string {
		return $this->entryPoint;
	}//end getEntryPoint()

	/**
	 * Get the entry point's own id (endpoint/job/subscription/synchronization uuid).
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getEntryPointId(): ?string {
		return $this->entryPointId;
	}//end getEntryPointId()

	/**
	 * Get the ISO 8601 timestamp this context was minted.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getStartedAt(): string {
		return $this->startedAt;
	}//end getStartedAt()

	/**
	 * Get the ordered step buffer.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	public function getSteps(): array {
		if ($this->droppedByStatus === []) {
			return $this->steps;
		}

		// One synthetic final entry, so a truncated trace SAYS it is truncated.
		// Silently returning the first 500 of 19,822 would be worse than the cost
		// it avoids: the trace would read as a complete record of a short run.
		$steps = $this->steps;
		$steps[] = [
			'order' => (count($steps) + 1),
			'type' => 'synchronization',
			'name' => 'truncated',
			'timing' => null,
			'status' => 'truncated',
			'durationMs' => 0,
			'startedAt' => (new DateTime())->format(DateTime::ATOM),
			'input' => [],
			'output' => [
				'retainedSteps' => count($this->steps),
				'droppedSteps' => array_sum($this->droppedByStatus),
				'droppedByStatus' => $this->droppedByStatus,
			],
		];

		return $steps;
	}//end getSteps()

	/**
	 * Get the original trace id this context replays, when applicable.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 */
	public function getReplayOf(): ?string {
		return $this->replayOf;
	}//end getReplayOf()

	/**
	 * Whether this context was created by a replay.
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 */
	public function isReplay(): bool {
		return $this->isReplay;
	}//end isReplay()

	/**
	 * Whether this context is a dry-run preview — write-shaped stages MUST
	 * suppress their side effect (rule-pipeline REQ-RULE-011).
	 *
	 * @return boolean
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-011
	 */
	public function isDryRun(): bool {
		return $this->dryRun;
	}//end isDryRun()

	/**
	 * Get what triggered this execution.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 */
	public function getTriggeredBy(): ?string {
		return $this->triggeredBy;
	}//end getTriggeredBy()
}//end class
