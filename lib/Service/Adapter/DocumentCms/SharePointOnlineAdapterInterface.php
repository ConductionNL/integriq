<?php

/**
 * Contract of the SharePointOnlineAdapter.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Adapter\DocumentCms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DocumentCms;

/**
 * Public operations of {@see SharePointOnlineAdapter} that other components call.
 */
interface SharePointOnlineAdapterInterface {
	/**
	 * Look a SharePoint site, library or folder up in Graph, with a credential the administrator picked.
	 *
	 * Three kinds, one per step of the setup dialog: `sites` (a search over
	 * site names, `search` required), `drives` (the document libraries of
	 * `siteId`) and `children` (the items in `driveId`, at its root or in the
	 * folder `itemId`). Ids are URL-encoded into the Graph path, so a value
	 * can never reach another Graph resource.
	 *
	 * When Graph refuses (401 or 403) the answer is a 403 that names the Graph
	 * permission the step needs and repeats Graph's own code and message, so
	 * the dialog can tell the administrator what to grant.
	 *
	 * @param string               $credentialId The brokered credential's uuid.
	 * @param string               $kind         `sites`, `drives` or `children`.
	 * @param array<string, mixed> $params       `search`, `siteId`, `driveId` and `itemId`, as the kind needs.
	 *
	 * @return array{status: int, items: list<array<string, mixed>>, error?: string, permission?: string, graphCode?: string, graphMessage?: string}
	 *
	 * @spec openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
	 */
	public function lookup(string $credentialId, string $kind, array $params): array;
}
