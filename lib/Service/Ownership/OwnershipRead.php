<?php

/**
 * Integriq OwnershipRead.
 *
 * The contract and the synchronisation one ownership answer is derived from.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Ownership
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Ownership;

/**
 * Value object for one ownership read (ADR-011).
 *
 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
 */
final class OwnershipRead {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $contract        The target's synchronisation contract.
	 * @param array<string, mixed>|null $synchronization Its synchronisation, null when it could not be read.
	 */
	public function __construct(
		public readonly array $contract,
		public readonly ?array $synchronization,
	) {
	}//end __construct()
}//end class
