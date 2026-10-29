<?php

/**
 * Contract of the SynchronizationService.
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

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OCA\Integriq\Exception\FormsFeatureDisabledException;
use OCA\Integriq\Exception\TablesFeatureDisabledException;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Public operations of {@see SynchronizationService} that other components call.
 */
interface SynchronizationServiceInterface {
	/**
	 * Default share (0.0-1.0) of a synchronization's existing contracts that
	 * `deleteInvalidObjects()` may garbage-collect in a single run before the
	 * deletion-ratio guard aborts the pass. Overridable per-synchronization via
	 * `sourceConfig.deletionRatioThreshold`.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public const DEFAULT_DELETION_RATIO_THRESHOLD = 0.10;

	/**
	 * The outcome of a destruction notice for a record no contract of the
	 * synchronization names: nothing was touched (REQ-SDP-002).
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public const DESTRUCTION_NO_CONTRACT = 'no_contract';

	/**
	 * Minimum number of existing contracts a synchronization must have before
	 * the deletion-ratio guard is evaluated at all.
	 *
	 * A percentage computed from a handful of contracts is not a meaningful
	 * signal of "mass deletion" (deleting the single existing contract is
	 * always a 100% ratio) and the production incidents motivating this guard
	 * (ConductionNL/integriq#1000/#1001/#1002) involve synchronizations
	 * with dozens to thousands of contracts, not a handful. Below this floor
	 * the guard is skipped entirely and deletion proceeds exactly as it did
	 * before this change (still subject to the fetch-completeness gate).
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 */
	public const MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD = 3;

	/**
	 * Finds all synchronizations by the given source ID, which is a combination of register and schema.
	 *
	 * @param $register The register id.
	 * @param $schema The schema id.
	 *
	 * @return array The list of records matching the source ID.
	 */
	public function findAllBySourceId($register, $schema);

	/**
	 * Handle synchronization for object create/update/delete events.
	 *
	 * This centralizes event listener behavior:
	 * - run direct source synchronizations for the object's register/schema
	 * - run related-object trigger synchronizations
	 *
	 * @param ObjectEntity $object The object from the event.
	 * @param string $eventMutationType The triggering mutation: create|update|delete
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-synchronization-orchestration-and-direction-routing-req-001
	 */
	public function handleObjectEventSynchronization(ObjectEntity $object, string $eventMutationType): void;

	/**
	 * Resolve and fetch the parent object for a related-object trigger.
	 *
	 * @param array $synchronization The synchronization that should run.
	 * @param array $triggerObject The related object payload from the event.
	 * @param string|int $triggerRegister The register of the related object source.
	 * @param string|int $triggerSchema The schema of the related object source.
	 * @param string|null $mutationType The triggering mutation type (reserved for future filtering).
	 *
	 * @return array|null The fetched parent object as array, or null when it cannot be resolved.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function resolveParentObjectForRelatedObjectTrigger(
		array $synchronization,
		array $triggerObject,
		string|int $triggerRegister,
		string|int $triggerSchema,
		?string $mutationType = null,
	): ?array;

	/**
	 * Re-invokes processSynchronizationObject() for a single payload against
	 * its synchronization — used by SyncItemDeadLetterService::replayMessage()
	 * to manually re-attempt a dead-lettered sync item (dead-letter-replay
	 * spec REQ-DLR-009). Unlike a full synchronize() pass, this does not
	 * fetch from the source, does not run follow-ups, and does not persist a
	 * new synchronization_log entry — a single, synchronous, immediate
	 * re-attempt only.
	 *
	 * @param array $synchronization The synchronization payload (as returned by getSynchronization()->jsonSerialize()).
	 * @param array $payload The raw source object to re-process (the dead-lettered payload).
	 * @param boolean $isTest False by default, preserving `dead-letter-replay` REQ-DLR-009's existing
	 *                        hardcoded-real-write behaviour for its own direct callers. When `true`
	 *                        (execution-trace REQ-005's dry-run sync-entryPoint replay branch,
	 *                        `ExecutionTraceService::replay()` always passes `isTest: !$force`), no
	 *                        target write occurs — reuses `synchronization-engine` REQ-011's
	 *                        existing no-write guarantee rather than inventing a second dry-run
	 *                        mechanism.
	 * @param ExecutionTraceContext|null $trace Active execution trace context for the replay, when called from
	 *                                          `ExecutionTraceService::replay()`.
	 *
	 * @return array{result: array, targetId: string|null} The processSynchronizationObject() outcome.
	 *
	 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
	 * @spec openspec/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005
	 */
	public function replaySynchronizationItem(
		array $synchronization,
		array $payload,
		bool $isTest = false,
		?ExecutionTraceContext $trace = null,
	): array;

	/**
	 * Synchronizes a given synchronization (or a complete source).
	 *
	 * @param array $synchronization The synchronization configuration.
	 * @param bool|null $isTest False by default; for the test endpoint.
	 * @param bool|null $force False by default; if true always update.
	 * @param array|\OCA\OpenRegister\Db\ObjectEntity|null $object Object to synchronize, by reference.
	 * @param string|null $mutationType For single object sync: the mutation
	 *                                  type, 'create', 'update' or
	 *                                  'delete'. Used for syncs to external
	 *                                  sources.
	 * @param string|null $source The source; defaults to the sync source.
	 * @param array|null $data The data; defaults to the sync data.
	 * @param FlowToken|null $flowToken The flow token shared across the run.
	 * @param bool|null $forceDeletion False by default; explicit override for
	 *                                 the deletion-ratio guard (REQ-010). Not
	 *                                 applicable to test runs.
	 * @param string|null $approvalRequestId Bypass token: the id of a specific
	 *                                       approved `approval_request` to consume
	 *                                       when `sourceConfig.requiresApproval`
	 *                                       gates this run (synchronization-engine
	 *                                       REQ-015).
	 * @param ExecutionTraceContext|null $trace The active execution trace context.
	 *                                          When null (a manual/cron-triggered
	 *                                          top-level run), a fresh
	 *                                          `sync`-entryPoint context is minted
	 *                                          and its persistence owned by this
	 *                                          method. When supplied (a
	 *                                          `synchronization` rule inside an
	 *                                          already-traced endpoint pipeline),
	 *                                          reused instead (execution-trace
	 *                                          REQ-001).
	 * @param string|null $triggeredBy What started the run, for the run record:
	 *                                 `rerun` from Run again; null reads the
	 *                                 trace (cron or manual).
	 *
	 * @return array|array|null
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 * @throws Exception
	 * @throws TooManyRequestsHttpException
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 * @spec openspec/specs/synchronization-engine/spec.md
	 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Backward-compatible optional flags
	 * (isTest/force pre-exist; forceDeletion is mandated by design.md Decision 2/3).
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) $approvalRequestId is an additive,
	 * backward-compatible optional bypass token (hitl-approval-rule-action design.md
	 * Decision 6); all 9 preceding parameters pre-exist this change. $trace is likewise
	 * additive (execution-trace-observability design.md Decision 1).
	 */
	public function synchronize(
		array|ObjectEntity $synchronization,
		?bool $isTest = false,
		?bool $force = false,
		array|\OCA\OpenRegister\Db\ObjectEntity|null &$object = null,
		?string $mutationType = null,
		?string $source = null,
		?array $data = null,
		?FlowToken &$flowToken = null,
		?bool $forceDeletion = false,
		?string $approvalRequestId = null,
		?ExecutionTraceContext $trace = null,
		?string $triggeredBy = null,
	): ?array;

	/**
	 * Clear a Synchronization's stored cursor watermark (REQ-019, change
	 * cdc-incremental-sync).
	 *
	 * Persists `cursorWatermark` as cleared (null/absent). Leaves `syncMode`
	 * and every other field untouched, and performs no target write/delete
	 * of its own — REQ-018's deletion block is keyed on `syncMode`, not on
	 * cursor state, so clearing the watermark alone never re-enables
	 * `deleteInvalidObjects()` for an incremental Synchronization (design.md
	 * Decision 3 / Risks). Following a reset, the Synchronization's next run
	 * resolves `{{ cursor }}` to an empty string (REQ-016's "no prior
	 * watermark" case).
	 *
	 * @param array|ObjectEntity $synchronization The synchronization whose watermark to clear.
	 *
	 * @return array The updated synchronization payload (for response/confirmation).
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-reset-cursor-action-clears-the-stored-watermark-req-019
	 */
	public function resetCursor(array|ObjectEntity $synchronization): array;

	/**
	 * Fetch an object from a specific endpoint.
	 *
	 * @param array $synchronization The synchronization containing the source.
	 * @param string $endpoint The endpoint to request to fetch the desired object.
	 * @param string|int|null $source The source to request if object is in other source than synchronization.
	 *
	 * @return array The resulting object.
	 *
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-source-object-fetching-and-pagination-req-002
	 */
	public function getObjectFromSource(array $synchronization, string $endpoint, string|int|null $source = null): array;

	/**
	 * Deletes invalid objects associated with a synchronization.
	 *
	 * This function identifies and removes objects that are no longer valid or do not exist
	 * in the source data for a given synchronization. It compares the target IDs from the
	 * synchronization contract with the synchronized target IDs and deletes the unmatched ones.
	 *
	 * Two safety guards gate the bulk source-diff cleanup path (the
	 * `$deleteRestriction === false` case; the single-object event-driven
	 * delete path is exempt from both — see the ratio-guard block below):
	 * - `$fetchComplete === false` unconditionally skips deletion — a known
	 *   incomplete fetch is never a safe basis for a diff-based cleanup.
	 *   `$forceDeletion` does NOT bypass this check.
	 * - A deletion-ratio guard aborts the pass when the number of candidate
	 *   deletions exceeds `sourceConfig.deletionRatioThreshold` (default
	 *   `self::DEFAULT_DELETION_RATIO_THRESHOLD`) of the synchronization's
	 *   total existing contracts, unless `$forceDeletion === true`. Below
	 *   `self::MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD` total contracts the
	 *   ratio guard is skipped (see that constant's docblock).
	 *
	 * @param array $synchronization The synchronization entity to process.
	 * @param array|null $synchronizedTargetIds An array of target IDs that are still valid in the source.
	 * @param bool $deleteRestriction Sets if deletion should be restricted to identifiers in $data.
	 * @param array $data The data to be checked when $deleteRestriction is true.
	 * @param bool $fetchComplete Whether the fetch preceding this call completed
	 *                            (REQ-009). Defaults `true` (today's implicit
	 *                            assumption) so direct callers that are not the
	 *                            extern→intern pipeline are unaffected.
	 * @param bool $forceDeletion Explicit override for the deletion-ratio guard.
	 *                            Distinct from the pre-existing `$force`
	 *                            parameter used elsewhere in this class (see
	 *                            design.md Decision 2) — never bypasses the
	 *                            fetch-completeness check above.
	 * @param array|null $guardInfo By-reference output parameter: populated with
	 *                              guard outcome details (`guarded`, `reason`,
	 *                              `ratio`, `threshold`, `candidateCount`,
	 *                              `totalContracts`) when applicable.
	 *
	 * @return int The count of objects that were deleted.
	 *
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface|\OCP\DB\Exception If any database or
	 *                                                                                  deletion error occurs.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Backward-compatible optional flags
	 * (deleteRestriction pre-exists; fetchComplete/forceDeletion are mandated by
	 * design.md Decision 2 as appended, defaulting-to-current-behaviour parameters).
	 */
	public function deleteInvalidObjects(
		array|ObjectEntity $synchronization,
		?array $synchronizedTargetIds = [],
		bool $deleteRestriction = false,
		array $data = [],
		bool $fetchComplete = true,
		bool $forceDeletion = false,
		?array &$guardInfo = null,
	): int;

	/**
	 * Recursively sort an associative array by key.
	 *
	 * @param mixed $array The array to sort.
	 *
	 * @return bool Whether or not the sort is successful.
	 */
	public function sortNestedArray(mixed &$array): bool;

	/**
	 * Synchronize a contract.
	 *
	 * @param array $synchronizationContract The contract payload array to synchronize.
	 * @param FlowToken $flowToken The flow token shared across the run. Declared
	 *                             BEFORE the optional parameters: a required
	 *                             parameter after an optional one is deprecated
	 *                             as of PHP 8.1, and every caller passes this
	 *                             by name, so the position is free to move.
	 * @param array|null $synchronization The synchronization configuration.
	 * @param array $object The object being synchronized.
	 * @param bool|null $isTest False by default, added for the test endpoint.
	 * @param bool|null $force False by default; if true, always update.
	 * @param SynchronizationRunLog|null $log The log to update.
	 * @param string|null $mutationType For single object sync: the mutation type,
	 *                                  'create', 'update' or 'delete'. Used for
	 *                                  syncs to external sources.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, threaded through
	 *                                          to the outbound target dispatch (execution-trace REQ-001).
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
	 *
	 * @return array|Exception
	 *
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws GuzzleException
	 */
	public function synchronizeContract(
		array $synchronizationContract,
		FlowToken &$flowToken,
		?array $synchronization = null,
		array &$object = [],
		?bool $isTest = false,
		?bool $force = false,
		?SynchronizationRunLog $log = null,
		?string $mutationType = null,
		?ExecutionTraceContext $trace = null,
	): array|Exception;

	/**
	 * Apply a source's destruction notice to the one object a contract maintains (REQ-SDP-002).
	 *
	 * Resolves the contract of this synchronization whose `originId` is one of
	 * the candidates, tried in order. With `sourceConfig.onSourceDestroyed:
	 * purge` that object is purged at once; otherwise the synchronization's
	 * disappearance policy is applied to it alone. No full run, no garbage
	 * collection, and a record without a contract leaves everything untouched.
	 *
	 * @param array|ObjectEntity $synchronization The synchronization.
	 * @param list<string>       $originIds       Candidate origin ids of the destroyed record.
	 * @param string|null        $reference       The notice's reference, written to the contract log.
	 *
	 * @return array{outcome: string, synchronizationId: string, originId: string|null, targetId: string|null, reason?: string}
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function applySourceDestruction(array|ObjectEntity $synchronization, array $originIds, ?string $reference): array;

	/**
	 * Recursively replaces 'originId' values with corresponding target IDs in the given object,
	 * according to the provided config array. The config array defines which keys to traverse and
	 * replace with ObjectEntity uuids.
	 *
	 * The object can contain nested associative arrays (sub objects) or indexed arrays of associative
	 * arrays (array of multiple subobjects). Only keys present in the config array are processed.
	 * Beforehand the $object must be mapped so properties that are relations to other objects set as a
	 * 'originId' which are equal to existing originIds on SynchronizationContracts, so that we can take
	 * the targetId of those found contracts so objects can be linked from earlier synchronizations.
	 *
	 * @param array $object The object array to process (can be nested).
	 * @param array $config A nested config tree indicating which keys to process and replace.
	 * @param bool $replaceIdWithTargetId If we need to replace id with target id found by origin id if configured.
	 *
	 * @return array The processed data with 'originId' replaced with actual ObjectEntities their uuids
	 *               where applicable.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function replaceRelatedOriginIds(array $object, array $config, bool $replaceIdWithTargetId = false): array;

	/**
	 * Write the data to the target.
	 *
	 * @param array $synchronizationContract The contract payload array to write.
	 * @param array|null $targetObject The object data to write to the target.
	 * @param string|null $action Determines what needs to be done with the target object,
	 *                            defaults to 'save'.
	 * @param string|null $mutationType If dealing with single object synchronization, the type
	 *                                  of the mutation that will be handled, 'create',
	 *                                  'update' or 'delete'. Used for syncs to external
	 *                                  sources.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, threaded through to the
	 *                                          outbound `api`-target dispatch (execution-trace REQ-001).
	 * @param array|null $synchronization The synchronization being run. Optional: resolved from
	 *                                    the contract when omitted, so standalone callers keep
	 *                                    working. Batch callers pass the array they already hold
	 *                                    rather than making this re-read it once per record.
	 *
	 * @param-out array $targetObject Callers may pass null IN (the parameter also
	 *        defaults to []), but nothing in here ever assigns null back out. The
	 *        normalisation at the top of the body is what makes that true on the
	 *        `database` and `nextcloud-table` paths, which never write it back.
	 *
	 * @return array
	 *
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 * @throws Exception
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 * @spec openspec/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002
	 */
	public function updateTarget(
		array $synchronizationContract,
		?array &$targetObject = [],
		?string $action = 'save',
		?string $mutationType = null,
		?ExecutionTraceContext $trace = null,
		?array $synchronization = null,
	): array;

	/**
	 * Get all the object from a source.
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest False by default, added for the synchronization-test endpoint.
	 * @param array|null $data The data to synchronize; if not provided the synchronization's
	 *                         data will be used.
	 * @param array|null $fetchInfo By-reference output parameter: populated with
	 *                              `['complete' => bool, 'pagesFetched' => int, 'failureReason' => ?string]`
	 *                              describing whether the fetch completed. Does not change
	 *                              this method's own (flat object list) return value.
	 * @param array|null $resolvedSource When provided, used directly as the resolved source
	 *                                   instead of re-looking it up by `sourceId` — required for
	 *                                   a transient, never-persisted ad-hoc source (REQ-012).
	 *
	 * @return array
	 *
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-form-source-dispatch-req-020
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
	 */
	public function getAllObjectsFromSource(
		array $synchronization,
		?bool $isTest = false,
		?array $data = null,
		?array &$fetchInfo = null,
		?array $resolvedSource = null,
	): array;

	/**
	 * Fetches all rows from a `nextcloud-table` source for a given synchronization.
	 *
	 * Delegates to {@see TablesSyncAdapter::fetchAllRows()}; the Tables row id
	 * (`Row.id`) is exposed as the top-level `id` key of each returned array so
	 * the existing `getOriginId()` default `idPosition` ('id') resolves it with
	 * no adapter-specific override, exactly like every other source type.
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest If true, only a single row is returned for testing purposes.
	 *
	 * @return array An array of rows retrieved from the table.
	 *
	 * @throws TablesFeatureDisabledException When the Tables app is not enabled.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-014
	 */
	public function getAllObjectsFromTable(array $synchronization, ?bool $isTest = false): array;

	/**
	 * Fetches all submissions from a `nextcloud-form` source for a given synchronization.
	 *
	 * Delegates to {@see FormsSyncAdapter::fetchAllSubmissions()}; the Forms
	 * submission id (`Submission.id`) is exposed as the top-level `id` key of
	 * each returned array so the existing `getOriginId()` default
	 * `idPosition` ('id') resolves it with no adapter-specific override,
	 * exactly like the `nextcloud-table` branch above. No accompanying
	 * target/deletion branch exists for `nextcloud-form` — source-only
	 * (`nextcloud-forms-connector` REQ-002, `synchronization-engine` REQ-020).
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest If true, only a single submission is returned for testing purposes.
	 *
	 * @return array An array of submissions retrieved from the form.
	 *
	 * @throws FormsFeatureDisabledException When the Forms app is not enabled.
	 *
	 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-nextcloud-form-source-dispatch-req-020
	 */
	public function getAllObjectsFromForm(array $synchronization, ?bool $isTest = false): array;

	/**
	 * Fetches all objects from an API source for a given synchronization.
	 *
	 * @param array $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest If true, only a single object is returned for testing purposes.
	 * @param array|null $data The data to synchronize; if not provided the synchronization's
	 *                         data will be used.
	 * @param array|null $fetchInfo By-reference output parameter: populated with
	 *                              `['complete' => bool, 'pagesFetched' => int, 'failureReason' => ?string]`
	 *                              describing whether the fetch completed. Does not change
	 *                              this method's own (flat object list) return value.
	 * @param array|null $resolvedSource When provided, used directly as the resolved source
	 *                                   instead of re-looking it up by `sourceId` — required for
	 *                                   a transient, never-persisted ad-hoc source (REQ-012).
	 *
	 * @return array An array of all objects retrieved from the API.
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-incremental-sync-mode-selects-a-cursor-filtered-fetch-request-req-016
	 */
	public function getAllObjectsFromApi(
		array $synchronization,
		?bool $isTest = false,
		?array $data = null,
		?array &$fetchInfo = null,
		?array $resolvedSource = null,
	): array;

	/**
	 * Retrieves the next link for pagination from the API response body.
	 *
	 * @param array $body The decoded JSON body of the API response.
	 *
	 * @return string|null The URL for the next page of results, or null if there is no next page.
	 */
	public function getNextlinkFromCall(array $body): ?string;

	/**
	 * Extracts all objects from the API response body.
	 *
	 * @param array $array The decoded JSON body of the API response.
	 * @param array $synchronization The synchronization object containing source configuration.
	 *
	 * @return array An array of items extracted from the response body.
	 * @throws Exception If the position of objects in the return body cannot be determined.
	 */
	public function getAllObjectsFromArray(array $array, array $synchronization): array;

	/**
	 * Synchronize data to a target.
	 *
	 * The synchronizationContract should be given if the normal procedure to find the contract
	 * (on originId) is not available to the contract that should be updated.
	 *
	 * @param ObjectEntity $object The object to synchronize.
	 * @param array|null $synchronizationContract If given: the contract payload array to update.
	 * @param bool|null $force If true, the object is updated regardless of changes.
	 * @param bool|null $test Whether this is a test run.
	 * @param SynchronizationRunLog|null $log The synchronization run log.
	 *
	 * @return array The updated synchronizationContracts.
	 *
	 * @throws ContainerExceptionInterface
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 * @throws GuzzleException
	 */
	public function synchronizeToTarget(
		ObjectEntity $object,
		?array $synchronizationContract = null,
		?bool $force = false,
		?bool $test = false,
		?SynchronizationRunLog $log = null,
	): array;

	/**
	 * Resolve one rule by id, for callers outside this service.
	 *
	 * `SynchronizationFlowGenerator` has to know what a synchronization's
	 * `actions` ARE before it can decide whether they are expressible as flow
	 * steps — refusing every synchronization that declares any is what kept 74
	 * of them unmigratable. It needs the rule's `type` and `timing`, nothing
	 * more, and re-implementing the lookup against register `openconnector` /
	 * schema `rule` in a second place is how the two drift.
	 *
	 * ⚠️ THE READ IS DELIBERATELY UNSCOPED, and delegating to `getRuleById()`
	 * was wrong for exactly that reason. That method reads WITH rbac and
	 * multitenancy, which is right for the legacy engine's in-request rule
	 * pipeline and wrong here: the generator runs under `occ`, where there is
	 * no user session, so a scoped read filters every rule away and returns
	 * null. The generator then cannot establish what the rule does and refuses
	 * the synchronization — which is not "this rule is unsupported", it is "I
	 * could not look".
	 *
	 * MEASURED, and this is why the flag matters rather than being defensive:
	 * after `openconnector.fetch-file` shipped, a full sweep of all 240
	 * synchronizations still refused the same 74, and ALL 74 gave
	 * `actions: rule "…" could not be resolved`. Not one of them was refused
	 * for its rule's TYPE. The feature was complete and unreachable.
	 *
	 * `MigrationEntityReader` already reads this way and says why in its own
	 * docblock; this is the same rule applied to the one entity that did not go
	 * through it.
	 *
	 * @param string $id The rule's OpenRegister uuid or slug.
	 *
	 * @return array|null The rule payload, or null when there is no such rule.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function findRule(string $id): ?array;

	/**
	 * Run ONE `fetch_file` rule, by id, outside the legacy rule pipeline.
	 *
	 * WHY THIS SEAM EXISTS. `openconnector.fetch-file` is a flow step over the
	 * same behaviour, and the decomposed pipeline has no `processRules()` pass
	 * to hang it off. The alternative was to lift the 75 lines of
	 * `processFetchFileRule()` into something both callers share, and that is a
	 * refactor of the legacy write path for the benefit of a new one — the kind
	 * of change that looks behaviour-preserving and is not. A public entry
	 * point that RESOLVES a rule and delegates keeps exactly one
	 * implementation, and the flow step and the legacy engine cannot drift
	 * apart because there is nothing to drift.
	 *
	 * It also means the generated flow references the SAME Rule entity the
	 * synchronization referenced. An operator who edits that rule keeps
	 * affecting both engines, which is what makes a half-migrated instance
	 * survivable.
	 *
	 * ⚠️ TYPE IS CHECKED, NOT ASSUMED. Another rule type reaching here would be
	 * silently handled as a file fetch — reading `configuration.fetch_file` off
	 * a rule that has none, finding nothing, and returning the data untouched
	 * while reporting success. A step that does nothing and says it worked is
	 * the failure this whole change keeps running into, so it refuses instead.
	 *
	 * @param string $ruleId The rule's OpenRegister uuid or slug.
	 * @param array $data The item data the rule acts on.
	 * @param string|null $objectId The written object, when there is one.
	 *
	 * @return array The data, with placeholders applied when configured.
	 *
	 * @throws Exception When the rule is missing or is not a `fetch_file` rule.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function runFetchFileRule(string $ruleId, array $data, ?string $objectId = null): array;

	/**
	 * Public entry point for {@see \OCA\Integriq\BackgroundJob\FetchFilesJob}.
	 *
	 * Re-resolves the source and runs the SAME fetch path the inline mode uses,
	 * so `sync` and `async` cannot drift into fetching differently — the only
	 * intended difference is when the work happens, never what it does.
	 *
	 * @param array $config The fetch_file rule configuration.
	 * @param mixed $endpoint The endpoint(s) to fetch.
	 * @param string $objectId The object the files belong to.
	 * @param int $ruleId The rule id, for error attribution.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-objects-multiple-files-shall-be-fetched-concurrently
	 */
	public function fetchFilesForObject(array $config, mixed $endpoint, string $objectId, int $ruleId = 0): void;

	/**
	 * Replaces strings in array keys, helpful for characters like . in array keys.
	 *
	 * @param array $array The array to encode the array keys for.
	 * @param string $toReplace The character to encode.
	 * @param string $replacement The encoded character.
	 *
	 * @return array The array with encoded array keys
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array;

	/**
	 * Fetch a synchronization OpenRegister object by id or other characteristics.
	 *
	 * Post-cutover, synchronizations are OpenRegister objects (register
	 * `openconnector`, schema `synchronization`); this resolves them without any
	 * caller needing to touch the OpenRegister object service directly.
	 *
	 * @param string|int|null $id The OpenRegister id (UUID) of the synchronization.
	 * @param array $filters Other filters to find the synchronization by.
	 *
	 * @return ObjectEntity The resulting synchronization object.
	 *
	 * @throws DoesNotExistException Thrown if the synchronization does not exist.
	 */
	public function getSynchronization(null|string|int $id = null, array $filters = []) :ObjectEntity;

	/**
	 * Cleans up files for an object based on the current attachments array.
	 *
	 * This method compares the files currently attached to an object with the files
	 * that should exist based on the attachments array from the synchronized data.
	 * Files that are no longer referenced in the attachments will be removed.
	 *
	 * @param string $objectId The UUID of the object to clean up files for.
	 * @param array $attachments Array of attachment objects with filename properties.
	 *
	 * @return int The number of files that were deleted.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
	public function cleanupFilesFromAttachments(string $objectId, array $attachments): int;
}
