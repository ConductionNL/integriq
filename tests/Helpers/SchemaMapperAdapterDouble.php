<?php

/**
 * A mapper adapter with the write methods EndpointService calls on a schema endpoint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;

/**
 * Declares createFromArray/updateFromArray/delete/getValidateHandler with the
 * parameter names the caller uses, so a mock of it accepts named arguments.
 */
class SchemaMapperAdapterDouble extends ObjectServiceMapperAdapter {

	/**
	 * @param array $object The object to create.
	 *
	 * @return mixed
	 */
	public function createFromArray(array $object): mixed {
		return null;
	}//end createFromArray()

	/**
	 * @param int|string $id     The object id.
	 * @param array      $object The full new body.
	 *
	 * @return mixed
	 */
	public function replaceFromArray(int|string $id, array $object): mixed {
		return null;
	}//end replaceFromArray()

	/**
	 * @param int|string $id     The object id.
	 * @param array      $object The partial body.
	 *
	 * @return mixed
	 */
	public function patchFromArray(int|string $id, array $object): mixed {
		return null;
	}//end patchFromArray()

	/**
	 * @param array $where The delete criteria.
	 *
	 * @return mixed
	 */
	public function delete(array $where): mixed {
		return false;
	}//end delete()

	/**
	 * @return mixed
	 */
	public function getValidateHandler(): mixed {
		return null;
	}//end getValidateHandler()
}//end class
