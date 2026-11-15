<?php

/**
 * Integriq ContractListing.
 *
 * What one list() call of SynchronizationContractProvider shares between its
 * rows: the synchronization names already looked up (so repeated
 * synchronizationIds across contracts resolve once, no N+1 lookups), and
 * whether the viewed object records a refused write-back. Built per list()
 * call and never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Integration;

/**
 * One listing of an object's synchronization contracts.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
final class ContractListing {

	/**
	 * Synchronization display names by synchronization id.
	 *
	 * @var array<string,string>
	 */
	public array $synchronizationNames = [];

	/**
	 * Whether the viewed object records a refused write-back.
	 *
	 * @var bool
	 */
	public bool $writeBackConflict = false;
}//end class
