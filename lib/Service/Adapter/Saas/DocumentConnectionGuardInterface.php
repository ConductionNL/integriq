<?php

/**
 * Contract of the DocumentConnectionGuard.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Adapter\Saas
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

namespace OCA\Integriq\Service\Adapter\Saas;

/**
 * Public operations of {@see DocumentConnectionGuard} that other components call.
 */
interface DocumentConnectionGuardInterface {
	/**
	 * Whether the app has the connection linked to an integriq source.
	 *
	 * The row is the one declared by `$sourceApp` under `$connectionKey`. An empty
	 * key names the app's connection only when the app declares exactly one. A row
	 * counts as linked when its `source` points at a source that still exists.
	 * Any failure to read answers false: an unverifiable link is a refusal.
	 *
	 * Limit: the link proves the app is connected to an integriq source, NOT that the
	 * source is a Microsoft 365 one. The Microsoft 365 adapter has no source row of its
	 * own (its credential is one app-config value, `microsoft-365_credential_id`), so
	 * there is nothing to compare a source against. Binding the app to Microsoft 365
	 * needs a Microsoft 365 source template or a connection-row declaration to match on.
	 *
	 * @param string $sourceApp     The asking app id.
	 * @param string $connectionKey The app's connection key, or '' for its only one.
	 *
	 * @return bool True when the app has a linked connection.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/design.md#d3-typed-commands
	 */
	public function isLinked(string $sourceApp, string $connectionKey): bool;
}
