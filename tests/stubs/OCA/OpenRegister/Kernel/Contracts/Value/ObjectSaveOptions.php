<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions.
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
 * Where and how saveObject() writes one object.
 */
final class ObjectSaveOptions
{
	/**
	 * @param ObjectFindTarget $target        The register and schema to save in.
	 * @param string|null      $uuid          The uuid to save under, or null for a new object.
	 * @param array|null       $extend        Properties to extend.
	 * @param array|null       $uploadedFiles Uploaded files.
	 */
	public function __construct(
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?string $uuid=null,
		public readonly ?array $extend=[],
		public readonly ?array $uploadedFiles=null,
	) {
	}//end __construct()
}//end class
