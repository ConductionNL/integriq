<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion.
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
 * Which object deleteObject() removes.
 */
final class ObjectDeletion
{
	/**
	 * @param string           $uuid        The object's uuid.
	 * @param ObjectFindTarget $target      The register and schema it lives in.
	 * @param mixed            $currentUser The acting user, or null.
	 * @param bool             $permanent   Whether to delete permanently.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly mixed $currentUser=null,
		public readonly bool $permanent=false,
	) {
	}//end __construct()
}//end class
