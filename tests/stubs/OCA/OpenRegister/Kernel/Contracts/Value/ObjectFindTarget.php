<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget.
 *
 * The value object openregister main's ObjectService takes in place of the
 * removed register:/schema: named arguments. OpenRegister is not installed in
 * the standalone composer dev-environment, so this holds the constructor
 * shape of openregister main's class, nothing more; with openregister
 * present the real class is loaded instead.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * The register and schema an object is found, saved or deleted in.
 */
final class ObjectFindTarget
{
	/**
	 * @param mixed $register The register (entity, slug or id), or null.
	 * @param mixed $schema   The schema (entity, slug or id), or null.
	 */
	public function __construct(
		public readonly mixed $register=null,
		public readonly mixed $schema=null,
	) {
	}//end __construct()
}//end class
