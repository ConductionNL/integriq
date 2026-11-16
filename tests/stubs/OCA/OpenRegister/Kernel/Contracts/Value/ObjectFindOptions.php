<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions.
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
 * How find() reads one object.
 */
final class ObjectFindOptions
{
	/**
	 * @param mixed      $register The register (entity, slug or id), or null.
	 * @param mixed      $schema   The schema (entity, slug or id), or null.
	 * @param array|null $extend   Properties to extend.
	 * @param bool       $files    Whether to include files.
	 * @param bool       $render   Whether to render the object.
	 * @param bool       $audit    Whether to write an audit entry.
	 */
	public function __construct(
		public readonly mixed $register=null,
		public readonly mixed $schema=null,
		public readonly ?array $extend=[],
		public readonly bool $files=false,
		public readonly bool $render=true,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
