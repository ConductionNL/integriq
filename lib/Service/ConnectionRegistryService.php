<?php

/**
 * Integriq ConnectionRegistryService.
 *
 * Turns every enabled app's `lib/Settings/connections.json` into `app_connection`
 * rows, resolves their status, and takes the reports and refresh requests apps
 * send. The contract is the hydra umbrella design, sections D2, D4, D5 and D6.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * The declaration sync and the status bookkeeping around it.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */
class ConnectionRegistryService {

	/**
	 * Where an app keeps its declaration, relative to its app path.
	 *
	 * @var string
	 */
	public const DECLARATION_FILE = 'lib/Settings/connections.json';

	/**
	 * Default sort order of an entry without one.
	 *
	 * @var int
	 */
	private const DEFAULT_ORDER = 100;

	/**
	 * A sync summary before anything happened.
	 *
	 * @var array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]}
	 */
	private const EMPTY_SUMMARY = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => []];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Finds enabled apps, their paths and versions.
	 * @param ConnectionDeclarationValidator $validator Checks a declaration file.
	 * @param ConnectionStatusResolver $resolver The D4 rules.
	 * @param ConnectionStore $store Reads and writes rows.
	 * @param LoggerInterface $logger Logs skipped files and refused reports.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ConnectionDeclarationValidator $validator,
		private readonly ConnectionStatusResolver $resolver,
		private readonly ConnectionStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function sync(?string $app = null): array {
		$summary = self::EMPTY_SUMMARY;

		$apps = $this->appManager->getEnabledApps();
		if ($app !== null) {
			if (in_array($app, $apps, true) === false) {
				$this->refresh(app: $app);
				return $summary;
			}

			$apps = [$app];
		}

		foreach ($apps as $appId) {
			$summary = $this->syncApp(app: (string)$appId, summary: $summary);
		}

		return $summary;
	}//end sync()

	/**
	 * Sync every enabled app whose version moved since its rows were written,
	 * and every app that ships a declaration but has no rows yet.
	 *
	 * @return string[] The app ids that were synced.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	public function syncChangedDeclarations(): array {
		$declaredVersions = [];
		foreach ($this->store->findRows() as $row) {
			$declaredVersions[(string)($row['data']['app'] ?? '')][] = (string)($row['data']['declaredVersion'] ?? '');
		}

		$synced = [];
		foreach ($this->appManager->getEnabledApps() as $appId) {
			$appId = (string)$appId;
			if ($this->needsSync(app: $appId, declaredVersions: $declaredVersions[$appId] ?? null) === false) {
				continue;
			}

			$this->syncApp(app: $appId, summary: self::EMPTY_SUMMARY);
			$synced[] = $appId;
		}

		return $synced;
	}//end syncChangedDeclarations()

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
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
	 */
	public function refresh(?string $app = null, ?string $key = null): int {
		return $this->resolveRows(app: $app, key: $key, stamp: []);
	}//end refresh()

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
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 */
	public function refreshRequested(string $app, ?string $key = null): int {
		return $this->resolveRows(app: $app, key: $key, stamp: ['refreshedAt' => $this->resolver->now()]);
	}//end refreshRequested()

	/**
	 * Resolve the matching rows with the stamp applied, and save the ones that changed.
	 *
	 * @param string|null $app One app, or null for every row.
	 * @param string|null $key One connection key of that app, or null for all.
	 * @param array<string,string> $stamp Fields to write before resolving: `refreshedAt`, or nothing.
	 *
	 * @return int The number of rows saved.
	 */
	private function resolveRows(?string $app, ?string $key, array $stamp): int {
		$rows = $this->store->findRows(app: $app);
		if ($key !== null) {
			$rows = array_filter($rows, static fn (array $row): bool => ($row['data']['key'] ?? null) === $key);
		}

		$saved = 0;
		foreach ($rows as $row) {
			$data = $this->resolveRow(data: array_merge($row['data'], $stamp));
			if ($this->isSame(stored: $row['data'], next: $data) === true) {
				continue;
			}

			$this->store->save(data: $data, uuid: $row['uuid']);
			$saved++;
		}

		return $saved;
	}//end resolveRows()

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
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-report-reaches-the-row
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-unknown-key-is-refused-without-an-exception
	 */
	public function report(string $app, string $key, string $status, string $message): bool {
		if (in_array($status, ConnectionStatusResolver::STATUSES, true) === false) {
			$this->logger->warning(
				'Integriq refused a connection report with unknown status "{status}" from {reportingApp} for {key}.',
				['app' => 'integriq', 'status' => $status, 'reportingApp' => $app, 'key' => $key]
			);
			return false;
		}

		$row = $this->rowsByKey(app: $app)[$key] ?? null;
		if ($row === null) {
			$this->logger->warning(
				'Integriq refused a connection report from {reportingApp} for {key}: no such connection is declared.',
				['app' => 'integriq', 'reportingApp' => $app, 'key' => $key]
			);
			return false;
		}

		$data = $row['data'];
		$data['lastReport'] = ['status' => $status, 'message' => $message, 'at' => $this->resolver->now()];
		$this->store->save(data: $this->resolveRow(data: $data), uuid: $row['uuid']);

		return true;
	}//end report()

	/**
	 * Resolve a row's data and return it with the outcome applied.
	 *
	 * @param array<string,mixed> $data The row data.
	 *
	 * @return array<string,mixed> The row data with status, statusMessage and checkedAt set.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
	 */
	public function resolveRow(array $data): array {
		$app = (string)($data['app'] ?? '');
		$outcome = $this->resolver->resolve(row: $data, appEnabled: $app !== '' && $this->appManager->isEnabledForAnyone($app) === true);

		$data['status'] = $outcome['status'];
		$data['statusMessage'] = $outcome['statusMessage'];
		$data['checkedAt'] = $outcome['checkedAt'];

		return $data;
	}//end resolveRow()

	/**
	 * Sync one enabled app.
	 *
	 * @param string $app The app id.
	 * @param array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]} $summary The running summary.
	 *
	 * @return array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]}
	 */
	private function syncApp(string $app, array $summary): array {
		$entries = $this->readDeclaration(app: $app);
		$existing = $this->rowsByKey(app: $app);
		if ($entries === null) {
			$summary['skipped'][] = $app;
			return $summary;
		}

		if ($entries === [] && $existing === []) {
			return $summary;
		}

		$version = $this->appManager->getAppVersion($app);
		$declaredKeys = [];
		foreach ($entries as $entry) {
			$key = (string)$entry['key'];
			$declaredKeys[$key] = true;
			$summary = $this->upsert(app: $app, entry: $entry, version: $version, existing: $existing[$key] ?? null, summary: $summary);
		}

		foreach ($existing as $key => $row) {
			if (isset($declaredKeys[$key]) === false) {
				$summary = $this->retire(app: $app, row: $row, summary: $summary);
			}
		}

		return $summary;
	}//end syncApp()

	/**
	 * Read and validate an app's declaration file.
	 *
	 * @param string $app The app id.
	 *
	 * @return array<int,array<string,mixed>>|null The entries; [] when the app ships no file; null when the file is refused.
	 */
	private function readDeclaration(string $app): ?array {
		$file = $this->declarationPath(app: $app);
		if ($file === null) {
			return [];
		}

		$data = json_decode((string)file_get_contents($file), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error(
				'Integriq skipped the connections.json of {declaringApp}: it is not valid JSON ({reason}).',
				['app' => 'integriq', 'declaringApp' => $app, 'reason' => json_last_error_msg()]
			);
			return null;
		}

		$errors = $this->validator->validate(data: $data);
		if ($errors !== []) {
			$this->logger->error(
				'Integriq skipped the connections.json of {declaringApp}: {errors}',
				['app' => 'integriq', 'declaringApp' => $app, 'errors' => implode('; ', $errors)]
			);
			return null;
		}

		if ($data['app'] !== $app) {
			$this->logger->error(
				'Integriq refused the connections.json of {declaringApp}: it claims the app id {claimedApp}.',
				['app' => 'integriq', 'declaringApp' => $app, 'claimedApp' => $data['app']]
			);
			return null;
		}

		return $data['connections'];
	}//end readDeclaration()

	/**
	 * The declaration file path of an app, or null when it ships none.
	 *
	 * @param string $app The app id.
	 *
	 * @return string|null
	 */
	private function declarationPath(string $app): ?string {
		try {
			$path = rtrim($this->appManager->getAppPath($app), '/') . '/' . self::DECLARATION_FILE;
		} catch (\Throwable $e) {
			return null;
		}

		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		return $path;
	}//end declarationPath()

	/**
	 * Create or update one declared row.
	 *
	 * @param string $app The app id.
	 * @param array<string,mixed> $entry The declaration entry.
	 * @param string $version The app's installed version.
	 * @param array{uuid:string,data:array<string,mixed>}|null $existing The stored row, if any.
	 * @param array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]} $summary The running summary.
	 *
	 * @return array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]}
	 */
	private function upsert(string $app, array $entry, string $version, ?array $existing, array $summary): array {
		$stored = $existing['data'] ?? [];
		$data = array_merge(
			$stored,
			[
				'slug' => 'connection-' . $app . '-' . $entry['key'],
				'app' => $app,
				'key' => $entry['key'],
				'title' => $entry['title'],
				'description' => (string)($entry['description'] ?? ''),
				'order' => (int)($entry['order'] ?? self::DEFAULT_ORDER),
				'settingsUrl' => (string)($entry['settingsUrl'] ?? ''),
				'declaration' => $entry,
				'declaredVersion' => $version,
			]
		);
		$data = $this->resolveRow(data: $data);

		if ($existing === null) {
			$this->store->save(data: $data);
			$summary['created']++;
			return $summary;
		}

		if ($this->isSame(stored: $stored, next: $data) === true) {
			$summary['unchanged']++;
			return $summary;
		}

		$this->store->save(data: $data, uuid: $existing['uuid']);
		$summary['updated']++;
		return $summary;
	}//end upsert()

	/**
	 * Handle a stored row whose key left the declaration.
	 *
	 * Without a source the row is deleted. With one it stays, marked
	 * unavailable, because deleting it would silently drop an admin's link.
	 * The mark lives in the stored declaration, so D4 rule 2 keeps giving the
	 * same answer after a later probe or report.
	 *
	 * @param string $app The app id.
	 * @param array{uuid:string,data:array<string,mixed>} $row The stored row.
	 * @param array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]} $summary The running summary.
	 *
	 * @return array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]}
	 */
	private function retire(string $app, array $row, array $summary): array {
		if ((string)($row['data']['source'] ?? '') === '') {
			$this->store->delete(uuid: $row['uuid']);
			$summary['deleted']++;
			return $summary;
		}

		$data = $row['data'];
		$declaration = $data['declaration'] ?? [];
		if (is_array($declaration) === false) {
			$declaration = [];
		}

		$declaration['available'] = false;
		$declaration['unavailableMessage'] = 'No longer declared by ' . $app . '.';
		$data['declaration'] = $declaration;
		$data = $this->resolveRow(data: $data);

		if ($this->isSame(stored: $row['data'], next: $data) === true) {
			$summary['unchanged']++;
			return $summary;
		}

		$this->store->save(data: $data, uuid: $row['uuid']);
		$summary['updated']++;
		return $summary;
	}//end retire()

	/**
	 * Whether an enabled app needs a sync from the hourly job.
	 *
	 * @param string $app The app id.
	 * @param string[]|null $declaredVersions The declaredVersion of each of its rows, or null when it has none.
	 *
	 * @return bool
	 */
	private function needsSync(string $app, ?array $declaredVersions): bool {
		if ($declaredVersions === null) {
			return $this->declarationPath(app: $app) !== null;
		}

		return array_diff($declaredVersions, [$this->appManager->getAppVersion($app)]) !== [];
	}//end needsSync()

	/**
	 * An app's stored rows keyed by connection key.
	 *
	 * @param string $app The app id.
	 *
	 * @return array<string,array{uuid:string,data:array<string,mixed>}>
	 */
	private function rowsByKey(string $app): array {
		$rows = [];
		foreach ($this->store->findRows(app: $app) as $row) {
			$rows[(string)($row['data']['key'] ?? '')] = $row;
		}

		unset($rows['']);
		return $rows;
	}//end rowsByKey()

	/**
	 * Whether a row's writable data is unchanged.
	 *
	 * @param array<string,mixed> $stored The stored data.
	 * @param array<string,mixed> $next The data about to be written.
	 *
	 * @return bool
	 */
	private function isSame(array $stored, array $next): bool {
		return $this->store->payload(data: $stored) === $this->store->payload(data: $next);
	}//end isSame()
}//end class
