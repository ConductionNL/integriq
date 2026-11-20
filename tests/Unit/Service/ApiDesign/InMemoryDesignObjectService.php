<?php

/**
 * An in-memory OpenRegister object service for the API design check tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ApiDesign
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ApiDesign;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Objects by schema and uuid; findAll honours the schema filter only.
 */
class InMemoryDesignObjectService extends OrObjectService {

	/**
	 * Objects by schema, then uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $objects = [];

	/**
	 * Every deleted uuid.
	 *
	 * @var list<string>
	 */
	public array $deleted = [];

	/**
	 * The next uuid number.
	 *
	 * @var int
	 */
	private int $next = 1;

	/**
	 * No parent wiring.
	 */
	public function __construct() {

	}//end __construct()

	/**
	 * Put an object in place.
	 *
	 * @param string               $schema The schema.
	 * @param string               $uuid   The uuid.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return void
	 */
	public function put(string $schema, string $uuid, array $data): void {
		$this->objects[$schema][$uuid] = $data;

	}//end put()

	/**
	 * Find one object, the way openregister main's find() takes it.
	 *
	 * Variadic so it overrides both the pure-unit stub's old signature and
	 * openregister main's; any call that does not pass an ObjectFindOptions as
	 * `options` (such as the removed `register:`/`schema:` named arguments)
	 * fails the test.
	 *
	 * @param mixed $id   The uuid.
	 * @param mixed $args `options` (ObjectFindOptions), then the access flags.
	 *
	 * @return ObjectEntity|null
	 */
	public function find($id, mixed ...$args): ?ObjectEntity {
		$options = self::argument(args: $args, name: 'options', class: ObjectFindOptions::class);
		$data    = ($this->objects[(string)$options->schema][(string)$id] ?? null);
		if ($data === null) {
			return null;
		}

		return self::entity(uuid: (string)$id, data: $data);

	}//end find()

	/**
	 * Every object of the filtered schema.
	 *
	 * @param array $config        The config with filters.
	 * @param bool  $_rbac         Unused.
	 * @param bool  $_multitenancy Unused.
	 *
	 * @return array{results: list<ObjectEntity>, total: int}
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$schema = (string)($config['filters']['schema'] ?? '');
		$results = [];
		foreach (($this->objects[$schema] ?? []) as $uuid => $data) {
			$results[] = self::entity(uuid: (string)$uuid, data: $data);
		}

		return ['results' => $results, 'total' => count($results)];

	}//end findAll()

	/**
	 * Save an object, minting a uuid when the options give none.
	 *
	 * @param mixed $object The data.
	 * @param mixed $args   `options` (ObjectSaveOptions), then the access flags.
	 *
	 * @return ObjectEntity
	 */
	public function saveObject($object, mixed ...$args): ObjectEntity {
		$options = self::argument(args: $args, name: 'options', class: ObjectSaveOptions::class);
		$uuid    = ($options->uuid ?? 'obj-' . $this->next++);
		$this->objects[(string)$options->target->schema][$uuid] = (array)$object;

		return self::entity(uuid: $uuid, data: (array)$object);

	}//end saveObject()

	/**
	 * Delete an object.
	 *
	 * @param mixed $args `deletion` (ObjectDeletion), then the access flags.
	 *
	 * @return bool
	 */
	public function deleteObject(mixed ...$args): bool {
		$deletion = self::argument(args: $args, name: 'deletion', class: ObjectDeletion::class);
		unset($this->objects[(string)$deletion->target->schema][$deletion->uuid]);
		$this->deleted[] = $deletion->uuid;

		return true;

	}//end deleteObject()

	/**
	 * The named (or first) argument, which must be of openregister main's value class.
	 *
	 * @param array<int|string, mixed> $args  The call's arguments after the first.
	 * @param string                   $name  The parameter name on openregister main.
	 * @param class-string<T>          $class The value class.
	 *
	 * @return T
	 *
	 * @template T of object
	 */
	private static function argument(array $args, string $name, string $class): object {
		$value = ($args[$name] ?? $args[0] ?? null);
		if ($value instanceof $class === false) {
			throw new \LogicException(message: "Expected {$name} to be {$class}, as on openregister main.");
		}

		return $value;

	}//end argument()

	/**
	 * An entity for data.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;

	}//end entity()
}//end class
