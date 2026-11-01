<?php

/**
 * Contract of the RecordProvenanceService.
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
 * Public operations of {@see RecordProvenanceService} that other components call.
 */
interface RecordProvenanceServiceInterface {
	/**
	 * Where one record came from.
	 *
	 * @param string $recordId The OpenRegister object uuid.
	 *
	 * @return array<string, mixed> `synced: false` for a record no synchronization wrote, else the provenance.
	 *
	 * @spec openspec/changes/record-provenance-tab/specs/synchronization/spec.md#requirement-a-record-says-where-it-was-synchronized-from
	 */
	public function provenance(string $recordId): array;
}
