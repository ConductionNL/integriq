<?php

/**
 * Integriq ContractIdentity.
 *
 * The identity a synchronization contract is upserted on: its `uuid`, else a
 * non-numeric `id`; null, so OpenRegister creates, when it has neither. Both
 * SynchronizationContractService and SynchronizationService persist contracts
 * and read it the same way.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Reads a contract's upsert identity.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
 */
final class ContractIdentity {

	/**
	 * The OpenRegister identifier to upsert a contract on.
	 *
	 * WHY THIS EXISTS. `persist()` used to read `$object['uuid']` alone and then
	 * drop `$object['id']`, on the reasoning that `id` was a legacy *integer* and
	 * not an OpenRegister identifier. A contract payload carries no `uuid`
	 * property at all — its identity comes back from OpenRegister AS `id`, and
	 * that `id` is a uuid string. So the upsert key was always null and every
	 * save CREATED: four distinct contract objects for one
	 * (synchronizationId, originId), one per run, each carrying an identical
	 * `originHash`. `synchronization_contract` grew without bound — 528,656 rows
	 * on the dev instance.
	 *
	 * A numeric `id` is still refused, which is what the original comment was
	 * actually protecting against.
	 *
	 * @param array $object The contract payload.
	 *
	 * @return string|null The uuid to upsert on, or null to create.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	public static function forUpsert(array $object): ?string {
		$uuid = ($object['uuid'] ?? null);
		if ($uuid !== null && (string)$uuid !== '') {
			return (string)$uuid;
		}

		$id = ($object['id'] ?? null);
		if ($id === null || is_numeric($id) === true) {
			return null;
		}

		$id = (string)$id;
		if ($id === '') {
			return null;
		}

		return $id;
	}//end forUpsert()
}//end class
