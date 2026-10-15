<?php

/**
 * Document connection guard.
 *
 * The typed commands DocumentSearchRequested and DocumentFetchRequested
 * (connectors-graph-document-search D3) resolve the calling app's linked
 * Microsoft 365 connection. The drive search itself is organisation-wide
 * (D2, the application grant), so the only thing that limits WHO may use it
 * is that the asking app has a connection linked to an integriq source. This
 * guard answers that question and refuses an app without one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-graph-document-search/design.md#d3-typed-commands
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\Saas;

use OCA\Integriq\Service\ConnectionStore;
use Throwable;

/**
 * Decides whether a sibling app has a linked connection to search and fetch through.
 *
 * @spec openspec/changes/connectors-graph-document-search/design.md#d3-typed-commands
 */
class DocumentConnectionGuard {

	/**
	 * Constructor.
	 *
	 * @param ConnectionStore $connections Reads the connection rows.
	 */
	public function __construct(
		private readonly ConnectionStore $connections,
	) {
	}//end __construct()

	/**
	 * Whether the app has the connection linked to an integriq source.
	 *
	 * The row is the one declared by `$sourceApp` under `$connectionKey`. An empty
	 * key names the app's connection only when the app declares exactly one. A row
	 * counts as linked when its `source` points at a source that still exists.
	 * Any failure to read answers false: an unverifiable link is a refusal.
	 *
	 * @param string $sourceApp     The asking app id.
	 * @param string $connectionKey The app's connection key, or '' for its only one.
	 *
	 * @return bool True when the app has a linked connection.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/design.md#d3-typed-commands
	 */
	public function isLinked(string $sourceApp, string $connectionKey): bool {
		if ($sourceApp === '') {
			return false;
		}

		try {
			$rows = $this->connections->findRows(app: $sourceApp);
			if ($connectionKey !== '') {
				$rows = array_values(array_filter($rows, static fn (array $row): bool => (($row['data']['key'] ?? null) === $connectionKey)));
			}

			if (count($rows) !== 1) {
				return false;
			}

			$sourceId = (string)($rows[0]['data']['source'] ?? '');

			return ($sourceId !== '' && $this->connections->findSource(uuid: $sourceId) !== null);
		} catch (Throwable $e) {
			return false;
		}
	}//end isLinked()
}//end class
