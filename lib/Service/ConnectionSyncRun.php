<?php

/**
 * Integriq ConnectionSyncRun: the sync of one app's connection declaration —
 * the app and its installed version, its stored rows by key, and the running
 * summary of what the sync created, updated, deleted or left unchanged.
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
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One app's declaration sync in progress. Built and used by ConnectionRegistryService only.
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-turns-declaration-files-into-connection-rows-req-conn-001
 */
final class ConnectionSyncRun {
	/**
	 * The app's stored rows, by connection key.
	 *
	 * @var array<string,array{uuid:string,data:array<string,mixed>}>
	 */
	public array $existing = [];

	/**
	 * The running summary.
	 *
	 * @var array{created:int,updated:int,deleted:int,unchanged:int,skipped:string[]}
	 */
	public array $summary = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => []];

	/**
	 * Constructor.
	 *
	 * @param string $app     The app id.
	 * @param string $version The app's installed version.
	 */
	public function __construct(
		public readonly string $app,
		public readonly string $version,
	) {
	}//end __construct()
}//end class
