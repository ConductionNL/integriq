<?php
/**
 * OpenConnector ExecutionTraceService.
 *
 * Assembles/persists the per-execution `execution_trace` OpenRegister
 * object (register.d fragment `execution-trace-observability.json`) and
 * orchestrates dry-run/forced replay of a traced entry point. Depends on
 * `ContainerInterface` (not a direct constructor dependency on
 * `EndpointService`/`SynchronizationService`/`JobService`/`EventService`)
 * so those four services can constructor-inject THIS service directly
 * without a circular DI graph — see design.md Decision 1/4.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/execution-trace/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Trace assembly, persistence, retrieval, and replay orchestration.
 *
 * @spec openspec/specs/execution-trace/spec.md
 */
class ExecutionTraceService
{

    /**
     * OpenRegister register slug holding execution traces.
     *
     * @var string
     */
    public const REGISTER = 'openconnector';

    /**
     * OR schema slug for an execution_trace record.
     *
     * @var string
     */
    public const SCHEMA = 'execution_trace';

    /**
     * Constructor.
     *
     * @param ORObjectService    $orObjectService    OR object service for execution_trace persistence.
     * @param ContainerInterface $containerInterface PSR container, used to lazily resolve
     *                                               `EndpointService`/`SynchronizationService`/`JobService`/
     *                                               `EventService` only inside {@see replay()} — never at
     *                                               construction time, to avoid a circular service graph
     *                                               (those four services constructor-inject THIS service).
     * @param LoggerInterface    $logger             Logger for non-fatal diagnostics.
     */
    public function __construct(
        private readonly ORObjectService $orObjectService,
        private readonly ContainerInterface $containerInterface,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Persist the assembled `ExecutionTraceContext` as one `execution_trace`
     * object, using the minted `traceId` as the object's own uuid — a single
     * create for every entry point EXCEPT the approval-suspend/resume
     * continuation, where the SAME object (matched by `traceId`) is updated
     * (REQ-004). `saveObject()`'s uuid-upsert semantics make create-vs-update
     * automatic: passing the same `traceId` twice always resolves to one row.
     *
     * @param ExecutionTraceContext $trace  The assembled context to persist.
     * @param string                $status running|success|failed|short_circuited.
     * @param array|null            $error  Terminal error {message, ruleType, ruleName}, when applicable.
     * @param boolean               $resume Documents that this call is the approval-resume continuation
     *                                      (design.md Decision 2) — informational only; the
     *                                      upsert-by-uuid behaviour is identical either way.
     *
     * @return ObjectEntity The persisted execution_trace object.
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004
     */
    public function persist(ExecutionTraceContext $trace, string $status, ?array $error=null, bool $resume=false): ObjectEntity
    {
        $now         = new DateTime();
        $startedAtTs = strtotime($trace->getStartedAt());
        if ($startedAtTs === false) {
            $startedAtTs = $now->getTimestamp();
        }

        $payload = [
            'traceId'      => $trace->getTraceId(),
            'entryPoint'   => $trace->getEntryPoint(),
            'entryPointId' => $trace->getEntryPointId(),
            'status'       => $status,
            'startedAt'    => $trace->getStartedAt(),
            'finishedAt'   => $now->format(DateTime::ATOM),
            'durationMs'   => max(0, ($now->getTimestamp() - $startedAtTs)) * 1000,
            'steps'        => $trace->getSteps(),
            'error'        => $error,
            'replayOf'     => $trace->getReplayOf(),
            'isReplay'     => $trace->isReplay(),
            'dryRun'       => $trace->isDryRun(),
            'triggeredBy'  => $trace->getTriggeredBy(),
        ];

        if ($resume === true) {
            $this->logger->debug(
                'ExecutionTraceService: persisting approval-resume continuation.',
                ['traceId' => $trace->getTraceId()]
            );
        }

        return $this->orObjectService->saveObject(
            object: $payload,
            register: self::REGISTER,
            schema: self::SCHEMA,
            uuid: $trace->getTraceId(),
            _rbac: false,
            _multitenancy: false
        );

    }//end persist()

    /**
     * Find one execution_trace by id.
     *
     * @param string $traceId The trace uuid.
     *
     * @return ObjectEntity|null The trace, or null when not found.
     *
     * @spec exclude Not a CRUD pass-through with no callers (ADR-022). It pins
     *       the register/schema pair, turns OR's rbac and multitenancy off —
     *       a trace is infrastructure telemetry, not a tenant-owned object —
     *       and converts a miss from a throw into null. Both callers
     *       (ExecutionTracesController::show and replay() below) depend on
     *       exactly that shape; deleting it duplicates five arguments and a
     *       try/catch at each of them.
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
     */
    public function find(string $traceId): ?ObjectEntity
    {
        try {
            return $this->orObjectService->find(
                id: $traceId,
                register: self::REGISTER,
                schema: self::SCHEMA,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $exception) {
            return null;
        }

    }//end find()

    /**
     * List execution_trace objects with optional filters and pagination,
     * matching the `LogsController::index` pattern (`logs-and-statistics`
     * REQ-001).
     *
     * @param array        $filters Additional OR filters (e.g. `entryPoint`, `status`).
     * @param integer|null $limit   Maximum number of results (default 20).
     * @param integer|null $offset  Starting offset (default 0).
     *
     * @return array{results: array, total: integer}
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
     */
    public function list(array $filters=[], ?int $limit=20, ?int $offset=0): array
    {
        $orFilters = array_merge(['register' => self::REGISTER, 'schema' => self::SCHEMA], $filters);
        $matches   = $this->orObjectService->findAll(config: ['filters' => $orFilters, 'limit' => $limit, 'offset' => $offset]);
        $results   = ($matches['results'] ?? $matches);
        $total     = ($matches['total'] ?? count($results));

        return ['results' => $results, 'total' => $total];

    }//end list()

    /**
     * Replay a traced execution: dry-run by default (REQ-005), an explicit
     * `force: true` performs the real write via the original entry point's
     * own dispatch mechanism (REQ-006). Every call creates a NEW
     * `execution_trace` linked via `replayOf` — the original trace is never
     * mutated.
     *
     * @param string  $traceId  The original trace's id.
     * @param string  $actorUid The acting operator's user id (event-kind replay audit).
     * @param boolean $force    False (default) = dry-run preview; true = real write.
     *
     * @return ObjectEntity The new, linked execution_trace.
     *
     * @throws DoesNotExistException When no such trace exists (controller maps to 404).
     *
     * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
     * @spec openspec/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006
     */
    public function replay(string $traceId, string $actorUid, bool $force=false): ObjectEntity
    {
        $original = $this->find(traceId: $traceId);
        if ($original === null) {
            throw new DoesNotExistException('No such execution_trace: '.$traceId);
        }

        $originalData = $original->getObject();
        $entryPoint   = (string) ($originalData['entryPoint'] ?? '');
        $entryPointId = ($originalData['entryPointId'] ?? null);
        $steps        = (array) ($originalData['steps'] ?? []);

        $newTraceEntryPoint = 'endpoint';
        if ($entryPoint !== '') {
            $newTraceEntryPoint = $entryPoint;
        }

        $newTrace = new ExecutionTraceContext(
            entryPoint: $newTraceEntryPoint,
            entryPointId: $entryPointId,
            replayOf: $traceId,
            isReplay: true,
            dryRun: ($force === false),
            triggeredBy: 'manual'
        );

        $status = match ($entryPoint) {
            'sync' => $this->replaySync(entryPointId: $entryPointId, steps: $steps, trace: $newTrace, force: $force),
            'job' => $this->replayJob(entryPointId: $entryPointId, trace: $newTrace, force: $force),
            'event' => $this->replayEvent(entryPointId: $entryPointId, actorUid: $actorUid, trace: $newTrace, force: $force),
            'endpoint' => $this->replayEndpoint(entryPointId: $entryPointId, steps: $steps, trace: $newTrace, force: $force),
            default => 'failed',
        };

        return $this->persist(trace: $newTrace, status: $status);

    }//end replay()

    /**
     * Extract the redacted `input` snapshot of the first step of a given
     * `type` from a persisted trace's `steps` array — the "business input"
     * a replay re-dispatches with credentials always re-resolved live,
     * never from this (redacted) snapshot (REQ-006).
     *
     * @param array  $steps The persisted trace's ordered steps.
     * @param string $type  rule|mapping|synchronization|call.
     *
     * @return array The step's redacted input, or an empty array when no step of that type exists.
     */
    private function extractStepInput(array $steps, string $type): array
    {
        foreach ($steps as $step) {
            if (($step['type'] ?? null) === $type) {
                $input = ($step['input'] ?? []);
                if (is_array($input) === true) {
                    return $input;
                }

                return [];
            }
        }

        return [];

    }//end extractStepInput()

    /**
     * Dispatch a `sync`-entryPoint replay via `SynchronizationService::replaySynchronizationItem()`
     * (REQ-005/REQ-006).
     *
     * @param string|null           $entryPointId The synchronization's uuid.
     * @param array                 $steps        The original trace's steps (payload source).
     * @param ExecutionTraceContext $trace        The new trace context.
     * @param boolean               $force        False = dry-run (`isTest: true`); true = real write.
     *
     * @return string success|failed.
     */
    private function replaySync(?string $entryPointId, array $steps, ExecutionTraceContext $trace, bool $force): string
    {
        if ($entryPointId === null) {
            return 'failed';
        }

        try {
            $synchronizationService = $this->containerInterface->get(SynchronizationService::class);
            $synchronization        = $synchronizationService->getSynchronization(id: $entryPointId)->jsonSerialize();
            $payload = $this->extractStepInput(steps: $steps, type: 'synchronization');

            $synchronizationService->replaySynchronizationItem(
                synchronization: $synchronization,
                payload: $payload,
                isTest: ($force === false),
                trace: $trace
            );

            return 'success';
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'ExecutionTraceService: sync-entryPoint replay failed.',
                ['entryPointId' => $entryPointId, 'exception' => $exception->getMessage()]
            );
            return 'failed';
        }//end try

    }//end replaySync()

    /**
     * Dispatch a `job`-entryPoint replay via `JobService::executeJob()`
     * (REQ-005/REQ-006).
     *
     * NOTE (deviation, disclosed): `JobService::executeJob()`'s `$forceRun`
     * flag only bypasses the enabled/schedule gate — it is NOT a no-write
     * dry-run mechanism (confirmed against HEAD: `JobsController::test()`
     * already always passes `forceRun: true` for its own "test" endpoint,
     * i.e. a real run). Unlike the `sync`/`endpoint` branches, a job-entryPoint
     * replay therefore always performs a real run regardless of `$force` —
     * there is no existing job-level no-write guarantee to reuse. Flagged as
     * a follow-up rather than inventing a new dry-run mechanism out of scope
     * for this change.
     *
     * @param string|null           $entryPointId The job's uuid.
     * @param ExecutionTraceContext $trace        The new trace context.
     * @param boolean               $force        Accepted for interface parity with the other branches; does not
     *                                            change dispatch behaviour (see NOTE above).
     *
     * @return string success|failed.
     */
    private function replayJob(?string $entryPointId, ExecutionTraceContext $trace, bool $force): string
    {
        if ($entryPointId === null) {
            return 'failed';
        }

        unset($force);

        try {
            $job        = $this->orObjectService->find(
                id: $entryPointId,
                register: 'openconnector',
                schema: 'job',
                _rbac: false,
                _multitenancy: false
            );
            $jobService = $this->containerInterface->get(JobService::class);
            $jobService->executeJob(job: $job, forceRun: true, trace: $trace);

            return 'success';
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'ExecutionTraceService: job-entryPoint replay failed.',
                ['entryPointId' => $entryPointId, 'exception' => $exception->getMessage()]
            );
            return 'failed';
        }

    }//end replayJob()

    /**
     * Dispatch an `event`-entryPoint replay: forced delegates to the
     * existing, unchanged `EventService::replayMessage()` dead-letter-replay
     * path (REQ-006, `dead-letter-replay` REQ-DLR-003); dry-run resolves the
     * would-be outbound request via `EventService::previewWebhookDelivery()`
     * WITHOUT dispatching (REQ-005).
     *
     * @param string|null           $entryPointId The event_message's uuid.
     * @param string                $actorUid     The acting operator's user id.
     * @param ExecutionTraceContext $trace        The new trace context.
     * @param boolean               $force        False = dry-run preview; true = real delivery.
     *
     * @return string success|failed.
     */
    private function replayEvent(?string $entryPointId, string $actorUid, ExecutionTraceContext $trace, bool $force): string
    {
        if ($entryPointId === null) {
            return 'failed';
        }

        try {
            $eventService = $this->containerInterface->get(EventService::class);

            if ($force === true) {
                $eventService->replayMessage(id: $entryPointId, actorUid: $actorUid);
                return 'success';
            }

            $message = $this->orObjectService->find(
                id: $entryPointId,
                register: 'openconnector',
                schema: 'event_message',
                _rbac: false,
                _multitenancy: false
            );
            $preview = $eventService->previewWebhookDelivery(message: $message);
            $trace->addStep(type: 'call', name: 'webhook preview', timing: null, status: 'skipped_dry_run', output: $preview);

            return 'success';
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'ExecutionTraceService: event-entryPoint replay failed.',
                ['entryPointId' => $entryPointId, 'exception' => $exception->getMessage()]
            );
            return 'failed';
        }//end try

    }//end replayEvent()

    /**
     * Dispatch an `endpoint`-entryPoint replay via `EndpointService::replay()`
     * (REQ-005/REQ-006, `rule-pipeline` REQ-RULE-011).
     *
     * @param string|null           $entryPointId The endpoint's uuid.
     * @param array                 $steps        The original trace's steps (request snapshot source).
     * @param ExecutionTraceContext $trace        The new trace context.
     * @param boolean               $force        False = dry-run (`dryRun: true`); true = real dispatch.
     *
     * @return string success|failed.
     */
    private function replayEndpoint(?string $entryPointId, array $steps, ExecutionTraceContext $trace, bool $force): string
    {
        if ($entryPointId === null) {
            return 'failed';
        }

        try {
            $endpointService = $this->containerInterface->get(EndpointService::class);
            $endpoint        = $endpointService->getEndpointById($entryPointId);
            if ($endpoint === null) {
                return 'failed';
            }

            $requestSnapshot = $this->extractStepInput(steps: $steps, type: 'rule');
            $response        = $endpointService->replay(
                endpoint: $endpoint,
                requestSnapshot: $requestSnapshot,
                trace: $trace,
                dryRun: ($force === false)
            );
            $statusCode      = $response->getStatus();

            // 200-299 covers the approval-suspend 202 case too (design.md
            // Decision 4 / approval-workflow REQ-001's HTTP 202 pending_approval
            // response), so no separate 202 check is needed.
            if ($statusCode >= 200 && $statusCode < 300) {
                return 'success';
            }

            return 'failed';
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'ExecutionTraceService: endpoint-entryPoint replay failed.',
                ['entryPointId' => $entryPointId, 'exception' => $exception->getMessage()]
            );
            return 'failed';
        }//end try

    }//end replayEndpoint()
}//end class
