<?php

/**
 * Contract of the SharePointPublicationSet.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Adapter\DocumentCms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/archive/2026-11-24-connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DocumentCms;

use InvalidArgumentException;

/**
 * Public operations of {@see SharePointPublicationSet} that other components call.
 */
interface SharePointPublicationSetInterface {
	/**
	 * The import document for one setup: the set filled with the administrator's choices.
	 *
	 * Every slug gets a suffix from the site, library and folder, so a second
	 * folder makes its own source, mapping, rule and synchronization instead of
	 * overwriting the first.
	 *
	 * @param array<string, mixed> $choices credentialId, siteId, driveId, folderId, folderName, catalog,
	 *                                      register, schema and requiresApproval.
	 *
	 * @return array{components: array<string, array<string, array<string, mixed>>>, slugs: array<string, string>}
	 *
	 * @throws InvalidArgumentException When a choice is missing or the declaration is refused.
	 *
	 * @spec openspec/changes/archive/2026-11-24-connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
	 */
	public function build(array $choices): array;
}
