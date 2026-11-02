<?php

/**
 * Contract of the ConnectionRegistryService.
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
 * Public operations of {@see ConnectionRegistryService} that other components call.
 */
interface ConnectionRegistryServiceInterface {
	/**
	 * Sync declarations into rows, for one app or for every enabled app.
	 *
	 * An app that is not enabled is not read; its rows are resolved again so
	 * they show D4 rule 1.
	 *
	 * @param string|null $app One app id, or null for every enabled app.
	 *
	 * @return array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]}
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function sync(?string $app = null): array;

	/**
	 * Sync every enabled app whose version moved since its rows were written,
	 * and every app that ships a declaration but has no rows yet.
	 *
	 * @return string[] The app ids that were synced.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	public function syncChangedDeclarations(): array;

	/**
	 * Resolve rows again and save the ones whose status changed.
	 *
	 * This plain resolve keeps the stored `refreshedAt`. The hourly job and a
	 * disabled app use it, so neither retires an observation.
	 *
	 * @param string|null $app One app, or null for every row.
	 * @param string|null $key One connection key of that app, or null for all.
	 *
	 * @return int The number of rows saved.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
	 */
	public function refresh(?string $app = null, ?string $key = null): int;

	/**
	 * Take an app's refresh request: stamp `refreshedAt`, then resolve.
	 *
	 * Only this path writes `refreshedAt`. A sync, a report, a probe and a plain
	 * {@see refresh()} keep the stored value. The stamp retires every report
	 * and probe older than itself from D4 rules 4a and 4b, because the settings
	 * they judged have changed. The observations stay on the row for reading.
	 *
	 * @param string $app The declaring app.
	 * @param string|null $key One connection key of that app, or null for all of its rows.
	 *
	 * @return int The number of rows saved.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 */
	public function refreshRequested(string $app, ?string $key = null): int;

	/**
	 * Record a status an app reported, then resolve the row.
	 *
	 * Refuses an unknown status or an app and key nothing declared, with a
	 * warning, and changes no row.
	 *
	 * @param string $app The declaring app.
	 * @param string $key The connection key.
	 * @param string $status The reported status.
	 * @param string $message The reported message.
	 *
	 * @return bool Whether the report was written.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#scenario-a-report-reaches-the-row
	 * @spec openspec/specs/connection-registry/spec.md#scenario-an-unknown-key-is-refused-without-an-exception
	 */
	public function report(string $app, string $key, string $status, string $message): bool;

	/**
	 * Resolve a row's data and return it with the outcome applied.
	 *
	 * @param array<string,mixed> $data The row data.
	 *
	 * @return array<string,mixed> The row data with status, statusMessage and checkedAt set.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
	 */
	public function resolveRow(array $data): array;
}
