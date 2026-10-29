<?php

/**
 * Contract of the RecordOwnershipService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Ownership
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

namespace OCA\Integriq\Service\Ownership;

/**
 * Public operations of {@see RecordOwnershipService} that other components call.
 */
interface RecordOwnershipServiceInterface {
	/**
	 * Who owns the object behind this reference.
	 *
	 * @param string $targetId The object's id at the target, which is its OpenRegister uuid.
	 *
	 * @return OwnershipState The ownership answer. Never null, never an exception for an unknown id.
	 *
	 * @spec openspec/specs/source-owned-records/spec.md
	 */
	public function forObject(string $targetId): OwnershipState;
}
