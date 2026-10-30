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
	 * @param int|string $id            The object id.
	 * @param array      $object        The new body.
	 * @param bool       $updateVersion Whether to bump the version.
	 * @param bool       $patch         Whether this is a partial update.
	 *
	 * @return mixed
	 */
	public function updateFromArray(int|string $id, array $object, bool $updateVersion, bool $patch=false): mixed {
		return null;
	}//end updateFromArray()

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
