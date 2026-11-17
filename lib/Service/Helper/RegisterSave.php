<?php

/**
 * Integriq register save.
 *
 * The OpenRegister save options for an object in one register and schema,
 * optionally under a known uuid: the shape almost every integriq write to
 * OpenRegister takes.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Builds the save options of a write to one register and schema.
 */
final class RegisterSave {

	/**
	 * The save options for an object in this register and schema.
	 *
	 * @param Register|string|int|null $register The register.
	 * @param Schema|string|int|null   $schema   The schema.
	 * @param string|null              $uuid     The object's uuid, or null for a new object.
	 *
	 * @return ObjectSaveOptions
	 */
	public static function options(
		Register|string|int|null $register,
		Schema|string|int|null $schema,
		?string $uuid = null,
	): ObjectSaveOptions {
		return new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $uuid);
	}//end options()
}//end class
