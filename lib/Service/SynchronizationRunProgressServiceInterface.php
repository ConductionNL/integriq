<?php

/**
 * Contract of the SynchronizationRunProgressService.
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
 * Public operations of {@see SynchronizationRunProgressService} that other components call.
 *
 * @SuppressWarnings(PHPMD.LongClassName) The name is the class name plus the Interface suffix.
 */
interface SynchronizationRunProgressServiceInterface {
	/**
	 * The register progress records live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema progress records live in.
	 *
	 * @var string
	 */
	public const SCHEMA = 'synchronization_run';

	/**
	 * Run again on a failed run started it.
	 *
	 * @var string
	 */
	public const TRIGGER_RERUN = 'rerun';

	/**
	 * An administrator started the run.
	 *
	 * @var string
	 */
	public const TRIGGER_MANUAL = 'manual';

	/**
	 * Open a run record with status `running`, before any work starts.
	 *
	 * This is the one write that has to happen: without it the run is invisible
	 * for its entire duration, which is the defect being fixed. It is still
	 * guarded — an unavailable register must not stop a synchronization.
	 *
	 * @param string $synchronizationId The synchronization being run.
	 * @param bool $enabled False leaves the run unrecorded, and every
	 *                      later tick/finish a no-op.
	 * @param string|null $sourceId The source the synchronization reads, as it
	 *                              is at the start of this run.
	 * @param string $triggeredBy What started the run: cron, manual or rerun.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$enabled` is the caller's
	 *   feature switch for progress recording, not a mode selector that makes this
	 *   method do two different jobs — false means "record nothing", which is the
	 *   early return below and the same no-op every later tick/finish takes.
	 *   Splitting it into two methods would push the same `if` to every call site
	 *   and hand callers a way to start a run they then never tick.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-mid-run-progress-is-observable-without-slowing-the-run-req-022
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-every-run-records-its-source-and-what-started-it-req-crun-001
	 */
	public function start(
		string $synchronizationId,
		bool $enabled = true,
		?string $sourceId = null,
		string $triggeredBy = self::TRIGGER_MANUAL,
	): void;

	/**
	 * Record progress, subject to the throttle.
	 *
	 * Call this as often as is convenient — per page, per object, per batch. The
	 * throttle decides what actually reaches the database, so callers do not
	 * have to reason about write cost at each site.
	 *
	 * @param array $counters Partial counters to merge into the record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-mid-run-progress-is-observable-without-slowing-the-run-req-022
	 */
	public function tick(array $counters = []): void;

	/**
	 * Close the run with a terminal status. NOT throttled — the final state must
	 * always be written, or a finished run stays `running` forever and every
	 * watcher reads it as hung.
	 *
	 * @param string $status One of `success` or `failed`.
	 * @param array $counters Final counters to merge.
	 * @param string|null $message Terminal message, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-mid-run-progress-is-observable-without-slowing-the-run-req-022
	 */
	public function finish(string $status, array $counters = [], ?string $message = null): void;

	/**
	 * The uuid of the last run record this service opened, also after it finished.
	 *
	 * @return string|null The run record's uuid, or null when none was written.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-failed-pull-restarts-with-one-click-req-crun-003
	 */
	public function lastRunId(): ?string;

	/**
	 * How many progress writes were actually issued this run.
	 *
	 * The overhead control needs this: a throttle that never fired would show no
	 * overhead at all and look exactly like a throttle that works.
	 *
	 * @return int The number of issued writes.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-mid-run-progress-is-observable-without-slowing-the-run-req-022
	 *
	 * @orphaned-write-capability exclude Read-only accessor returning
	 * `$this->writes`; it performs no write and has no side effect at all. It
	 * matched the gate only because the method name begins with `write`. The
	 * actual write capability is the private `write()` below, which this merely
	 * counts. Re-verify in one glance from the body: a single `return` of an
	 * int property.
	 */
	public function writeCount(): int;
}
