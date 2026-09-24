<?php

/**
 * Integriq DirectorySyncController.
 *
 * The admin surface of a directory connection: the connections themselves, a
 * preview that changes nothing, an on-demand run, and the runs a connection has
 * already had. Every method is admin-only and CSRF-protected — a membership
 * write is an access decision, and the preview reads the whole directory, so
 * neither belongs on an endpoint an ordinary account can reach.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Directory\DirectorySyncService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Throwable;

/**
 * Admin-only REST surface for directory connections and their runs.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */
class DirectorySyncController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param DirectorySource $directorySource Finds directory connections.
	 * @param DirectorySyncService $directorySyncService Runs a connection.
	 * @param OrObjectService $orObjectService Reads the run records back.
	 * @param IL10N $l10n Translations.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly DirectorySource $directorySource,
		private readonly DirectorySyncService $directorySyncService,
		private readonly OrObjectService $orObjectService,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The directory connections and the mapping each one declares.
	 *
	 * @return JSONResponse The connections.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function connections(): JSONResponse {
		$rows = [];

		foreach ($this->directorySource->findConnections() as $connection) {
			$object = $connection->getObject();
			$configuration = (array)($object['configuration'] ?? []);

			$rows[] = [
				'id' => (string)$connection->getUuid(),
				'name' => (string)($object['name'] ?? ''),
				'description' => (string)($object['description'] ?? ''),
				'isEnabled' => (bool)($object['isEnabled'] ?? true),
				'mock' => (bool)($configuration['mock'] ?? false),
				'mapping' => (array)($configuration['mapping'] ?? []),
			];
		}

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);

	}//end connections()

	/**
	 * Run one directory connection, or preview it.
	 *
	 * `dryRun` writes no membership and lists both sides. `confirmRemovals`
	 * resumes a run the deletion guard stopped, which is the administrator
	 * taking the decision the guard exists to hand them.
	 *
	 * @param string $id The connection uuid.
	 *
	 * @return JSONResponse The run record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function run(string $id): JSONResponse {
		$connection = $this->findConnection(id: $id);
		if ($connection === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('There is no directory connection with that id.')],
				Http::STATUS_NOT_FOUND
			);
		}

		$dryRun = filter_var($this->request->getParam('dryRun', false), FILTER_VALIDATE_BOOLEAN);
		$confirmRemovals = filter_var($this->request->getParam('confirmRemovals', false), FILTER_VALIDATE_BOOLEAN);

		try {
			$record = $this->directorySyncService->run(
				connection: $connection,
				dryRun: $dryRun,
				confirmRemovals: $confirmRemovals
			);
		} catch (Throwable $exception) {
			return new JSONResponse(
				['error' => $exception->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($record);

	}//end run()

	/**
	 * The runs a directory connection has had, newest first.
	 *
	 * @return JSONResponse The run records.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function runs(): JSONResponse {
		// The run log's register and schema, spelled out rather than read from
		// SynchronizationLogService: its own constants are private, and this
		// change does not widen another change's class to borrow them.
		$filters = [
			'register' => 'integriq',
			'schema' => 'synchronization_log',
		];

		$connectionId = (string)$this->request->getParam('connection', '');
		if ($connectionId !== '') {
			$filters['synchronizationId'] = $connectionId;
		}

		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => $filters,
				'limit' => (int)$this->request->getParam('limit', 50),
				'offset' => (int)$this->request->getParam('offset', 0),
			]
		);

		// The run records, narrowed to directory runs below.
		$rows = [];
		foreach (($matches['results'] ?? $matches) as $match) {
			if (($match instanceof ObjectEntity) === false) {
				continue;
			}

			$object = (array)$match->getObject();
			// Only directory runs: the run log is shared with every other
			// synchronization, and a screen that mixed them would be reporting
			// about something adjacent to what it claims.
			if ((($object['result']['kind'] ?? '')) !== 'directory') {
				continue;
			}

			$rows[] = $object;
		}

		return new JSONResponse(['results' => $rows, 'total' => count($rows)]);

	}//end runs()

	/**
	 * Find one directory connection by its uuid.
	 *
	 * @param string $id The connection uuid.
	 *
	 * @return ObjectEntity|null The connection, or null when there is none.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	private function findConnection(string $id): ?ObjectEntity {
		foreach ($this->directorySource->findConnections() as $connection) {
			if ((string)$connection->getUuid() === $id) {
				return $connection;
			}
		}

		return null;

	}//end findConnection()
}//end class
