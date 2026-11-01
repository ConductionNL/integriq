<?php

/**
 * Contract of the ConfigurationImportPreviewService.
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

use InvalidArgumentException;

/**
 * Public operations of {@see ConfigurationImportPreviewService} that other components call.
 *
 * The method signatures repeat those of ConfigurationImportPreviewService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.LongClassName)
 */
interface ConfigurationImportPreviewServiceInterface {
	/**
	 * Compute the full import preview for an OAS document (REQ-007 + REQ-009).
	 *
	 * @param array<string,mixed> $oas The OAS document (must contain `components`).
	 *
	 * @return array<string, array<int,array<string,mixed>>> Keys: creates, updates, collisions,
	 *                                                       unresolvedReferences, credentialsNeedingReentry.
	 *
	 * @throws InvalidArgumentException When the document has no top-level `components` key (mirrors importConfiguration()).
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	public function preview(array $oas): array;
}
