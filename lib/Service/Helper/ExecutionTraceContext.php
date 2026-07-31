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
class ExecutionTraceContext
{

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
     * Optional observer notified as each step is appended (#1082).
     *
     * The step buffer is persisted once, at the end of a run, which is fine for
     * the trace's own purpose but useless for watching a run live: nothing can
     * see a step until the run that produced it has already finished. The
     * streaming harness registers a listener here so it can flush each step as an
     * SSE frame the moment it happens.
     *
     * Null by default, and {@see addStep()} is byte-for-byte unchanged when it is
     * null — every existing caller is unaffected.
     *
     * @var callable|null
     */
    private $stepListener = null;

    /**
     * Constructor. Mints a fresh UUIDv4 traceId unless one is supplied
     * (replay/rehydration passes the original traceId to continue an
     * existing trace — design.md Decision 2's approval-resume continuation).
     *
     * @param string                           $entryPoint   endpoint|job|event|sync.
     * @param string|null                      $entryPointId uuid of the endpoint/job/subscription/synchronization.
     * @param string|null                      $traceId      Explicit traceId to reuse (resume/rehydration); minted fresh when null.
     * @param string|null                      $replayOf     Original trace id, when this context is a replay.
     * @param boolean                          $isReplay     Whether this context was created by a replay.
     * @param boolean                          $dryRun       Whether this context is a dry-run preview.
     * @param string|null                      $triggeredBy  http|cron|manual.
     * @param array<int, array<string, mixed>> $priorSteps   Steps already recorded before this context was
     *                                                       (re)constructed — the
     *                                                       approval-suspend/resume continuation
     *                                                       (design.md Decision 2) rehydrates a context
     *                                                       pre-loaded with the `before`-phase steps so
     *                                                       `addStep()` continues the SAME ordered
     *                                                       sequence rather than restarting at order 1.
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
     * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004
     */
    public function __construct(
        string $entryPoint,
        ?string $entryPointId=null,
        ?string $traceId=null,
        ?string $replayOf=null,
        bool $isReplay=false,
        bool $dryRun=false,
        ?string $triggeredBy=null,
        array $priorSteps=[]
    ) {
        $this->traceId      = ($traceId ?? (string) Uuid::v4());
        $this->entryPoint   = $entryPoint;
        $this->entryPointId = $entryPointId;
        $this->startedAt    = (new DateTime())->format(DateTime::ATOM);
        $this->replayOf     = $replayOf;
        $this->isReplay     = $isReplay;
        $this->dryRun       = $dryRun;
        $this->triggeredBy  = $triggeredBy;
        $this->steps        = $priorSteps;

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
     * @param string               $type                Step type: rule|mapping|synchronization|call.
     * @param string               $name                Human-readable step name (rule name, mapping name, etc.).
     * @param string|null          $timing              Pipeline phase the step ran in (before|after), or null when not applicable.
     * @param string               $status              success|skipped|skipped_dry_run|error.
     * @param array<string, mixed> $input               Already-redacted input snapshot.
     * @param array<string, mixed> $output              Already-redacted output snapshot.
     * @param float|null           $startedAtMicrotime  microtime(true) at step start; defaults to now.
     * @param float|null           $finishedAtMicrotime microtime(true) at step end; defaults to now.
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
        array $input=[],
        array $output=[],
        ?float $startedAtMicrotime=null,
        ?float $finishedAtMicrotime=null
    ): void {
        $start = ($startedAtMicrotime ?? microtime(true));
        $end   = ($finishedAtMicrotime ?? $start);

        $step = [
            'order'      => (count($this->steps) + 1),
            'type'       => $type,
            'name'       => $name,
            'timing'     => $timing,
            'status'     => $status,
            'durationMs' => (int) round(($end - $start) * 1000),
            'startedAt'  => (new DateTime('@'.((int) $start)))->format(DateTime::ATOM),
            'input'      => $input,
            'output'     => $output,
        ];

        $this->steps[] = $step;

        // Notify the live observer, when one is registered (#1082). Wrapped so a
        // failing listener can never break the run it is only watching: a broken
        // console must not take down the synchronization it was opened to debug.
        if ($this->stepListener !== null) {
            try {
                ($this->stepListener)($step);
            } catch (\Throwable) {
                // Deliberately swallowed — observation is not allowed to affect
                // the observed. The trace buffer above already has the step.
            }
        }

    }//end addStep()

    /**
     * Register an observer notified with each step as it is appended (#1082).
     *
     * Exists so a run can be watched live. Without it the only way to read steps
     * is {@see getSteps()} after the run has finished, which is no use when the
     * point is to see what is happening — or to see the last step before a fatal
     * kills the process.
     *
     * The listener receives the single step array just appended, in the same shape
     * `getSteps()` returns its entries. It MUST NOT be relied upon to run: pass
     * null to detach, and note that {@see addStep()} swallows any Throwable it
     * raises rather than letting a watcher break the run.
     *
     * @param callable|null $listener Receives one step array, or null to detach.
     *
     * @return void
     *
     * @spec openspec/changes/streaming-run-output/specs/run-streaming/spec.md#requirement-shared-streaming-harness-emits-progress-and-a-final-result
     */
    public function setStepListener(?callable $listener): void
    {
        $this->stepListener = $listener;

    }//end setStepListener()

    /**
     * Get the minted traceId.
     *
     * @return string
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
     */
    public function getTraceId(): string
    {
        return $this->traceId;

    }//end getTraceId()

    /**
     * Get the entry point that minted this context.
     *
     * @return string
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
     */
    public function getEntryPoint(): string
    {
        return $this->entryPoint;

    }//end getEntryPoint()

    /**
     * Get the entry point's own id (endpoint/job/subscription/synchronization uuid).
     *
     * @return string|null
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
     */
    public function getEntryPointId(): ?string
    {
        return $this->entryPointId;

    }//end getEntryPointId()

    /**
     * Get the ISO 8601 timestamp this context was minted.
     *
     * @return string
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
     */
    public function getStartedAt(): string
    {
        return $this->startedAt;

    }//end getStartedAt()

    /**
     * Get the ordered step buffer.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
     */
    public function getSteps(): array
    {
        return $this->steps;

    }//end getSteps()

    /**
     * Get the original trace id this context replays, when applicable.
     *
     * @return string|null
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
     */
    public function getReplayOf(): ?string
    {
        return $this->replayOf;

    }//end getReplayOf()

    /**
     * Whether this context was created by a replay.
     *
     * @return boolean
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
     */
    public function isReplay(): bool
    {
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
    public function isDryRun(): bool
    {
        return $this->dryRun;

    }//end isDryRun()

    /**
     * Get what triggered this execution.
     *
     * @return string|null
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
     */
    public function getTriggeredBy(): ?string
    {
        return $this->triggeredBy;

    }//end getTriggeredBy()
}//end class
