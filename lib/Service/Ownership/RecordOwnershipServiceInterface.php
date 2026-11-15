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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

	/**
	 * Who owns the object, throwing when the contracts cannot be read.
	 *
	 * @param string $targetId The object's OpenRegister uuid.
	 *
	 * @return OwnershipState The ownership answer.
	 *
	 * @throws OwnershipReadFailed When a contract or synchronisation read fails.
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
	 */
	public function forObjectOrFail(string $targetId): OwnershipState;

	/**
	 * The answer for a record nobody outside maintains.
	 *
	 * @return OwnershipState A local ownership state.
	 *
	 * @spec openspec/specs/source-owned-records/spec.md#scenario-an-unknown-object-answers-local-rather-than-failing
	 */
	public function localState(): OwnershipState;
}
