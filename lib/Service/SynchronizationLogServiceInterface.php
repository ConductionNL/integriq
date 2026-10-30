<?php

/**
 * Contract of the SynchronizationLogService.
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

/**
 * Public operations of {@see SynchronizationLogService} that other components call.
 */
interface SynchronizationLogServiceInterface {
	/**
	 * Build a new in-memory synchronization run-log handle.
	 *
	 * The OpenRegister `synchronization_log` schema is immutable / append-only
	 * (ADR-003): OR rejects any `saveObject(uuid: ...)` UPDATE after the initial
	 * INSERT with an AppendOnlyException. The engine therefore writes the row
	 * exactly once, with its final state, at the end of the run — so this method
	 * only builds and returns the in-memory value object (auto-filling the system
	 * fields and a stable pre-generated uuid the engine can reference for
	 * contract-log linkage). The row is persisted later via update()/persist().
	 *
	 * @param array $object The log data.
	 *
	 * @return SynchronizationRunLog The (unpersisted) log value object.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationLogService::createFromArray()
	 */
	public function createFromArray(array $object): SynchronizationRunLog;

	/**
	 * Persist the in-memory run-log to OpenRegister, write-once.
	 *
	 * Mirrors the retired mapper's update() semantics (the 1-hour retention
	 * shortening for successful runs) but honours the append-only schema: the row
	 * is INSERTed exactly once via saveObject() WITHOUT a uuid parameter (a CREATE
	 * for OpenRegister). Because the schema rejects updates, any subsequent call
	 * for an already-persisted log is a no-op that returns the handle unchanged.
	 * Callers therefore invoke this once, at the end of the run, with the log in
	 * its final state.
	 *
	 * @param SynchronizationRunLog $log The log value object to persist.
	 *
	 * @return SynchronizationRunLog The persisted (or unchanged) log value object.
	 *
	 * @throws \Exception When the OpenRegister save fails.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011
	 */
	public function update(SynchronizationRunLog $log): SynchronizationRunLog;

	/**
	 * Persist a run-log to OpenRegister, write-once (alias of update()).
	 *
	 * Provided for call sites that conceptually "finalize" rather than "update".
	 *
	 * @param SynchronizationRunLog $log The log value object to persist.
	 *
	 * @return SynchronizationRunLog The persisted log value object.
	 *
	 * @throws \Exception When the OpenRegister save fails.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SynchronizationLogService::persist()
	 */
	public function persist(SynchronizationRunLog $log): SynchronizationRunLog;
}
