<?php

/**
 * An OpenRegister ObjectService double that also takes main's option values.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * openregister main reads and writes one object through `find(id:, options:
 * ObjectFindOptions)` and `saveObject(object:, options: ObjectSaveOptions)`;
 * the register and schema named arguments are gone there. The shared stub
 * still models the older signature for the code not yet moved, so lib/ code
 * written against main's API is tested by mocking this subclass, which adds
 * the trailing `options` parameter and nothing else.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;

/**
 * ObjectService with main's `options` parameter on find() and saveObject().
 */
class MainApiObjectService extends ObjectService {

	/**
	 * Find one object.
	 *
	 * @param mixed                  $id            The object id.
	 * @param string|null            $register      Older form, unused on main.
	 * @param string|null            $schema        Older form, unused on main.
	 * @param bool                   $_rbac         Apply RBAC.
	 * @param bool                   $_multitenancy Apply multitenancy.
	 * @param bool                   $_render       Render the object.
	 * @param bool                   $_audit        Audit the read.
	 * @param array|null             $_extend       Properties to expand.
	 * @param ObjectFindOptions|null $options       Where to look, as on main.
	 *
	 * @return ObjectEntity|null
	 */
	public function find(
		$id,
		?string $register = null,
		?string $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
		?array $_extend = [],
		?ObjectFindOptions $options = null,
	): ?ObjectEntity {
		return null;
	}//end find()

	/**
	 * Save one object.
	 *
	 * @param mixed                  $object        The object data or entity.
	 * @param string|null            $register      Older form, unused on main.
	 * @param string|null            $schema        Older form, unused on main.
	 * @param string|null            $uuid          Older form, unused on main.
	 * @param bool                   $_rbac         Apply RBAC.
	 * @param bool                   $_multitenancy Apply multitenancy.
	 * @param bool                   $silent        Skip events.
	 * @param bool                   $_validation   Validate.
	 * @param ObjectSaveOptions|null $options       Target and uuid, as on main.
	 *
	 * @return ObjectEntity
	 */
	public function saveObject(
		$object,
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?ObjectSaveOptions $options = null,
	): ObjectEntity {
		return new ObjectEntity();
	}//end saveObject()
}//end class
