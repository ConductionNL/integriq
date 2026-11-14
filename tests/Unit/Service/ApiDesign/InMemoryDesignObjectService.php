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
	 * Find one object.
	 *
	 * @param mixed       $id            The uuid.
	 * @param string|null $register      The register.
	 * @param string|null $schema        The schema.
	 * @param bool        $_rbac         Unused.
	 * @param bool        $_multitenancy Unused.
	 * @param bool        $_render       Unused.
	 * @param bool        $_audit        Unused.
	 * @param array|null  $_extend       Unused.
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
	): ?ObjectEntity {
		$data = ($this->objects[(string)$schema][(string)$id] ?? null);
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
	 * Save an object, minting a uuid when none is given.
	 *
	 * @param mixed       $object        The data.
	 * @param string|null $register      The register.
	 * @param string|null $schema        The schema.
	 * @param string|null $uuid          The uuid.
	 * @param bool        $_rbac         Unused.
	 * @param bool        $_multitenancy Unused.
	 * @param bool        $silent        Unused.
	 * @param bool        $_validation   Unused.
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
	): ObjectEntity {
		$uuid = ($uuid ?? 'obj-' . $this->next++);
		$this->objects[(string)$schema][$uuid] = (array)$object;

		return self::entity(uuid: $uuid, data: (array)$object);

	}//end saveObject()

	/**
	 * Delete an object.
	 *
	 * @param string|null     $uuid            The uuid.
	 * @param string|int|null $register        The register.
	 * @param string|int|null $schema          The schema.
	 * @param bool            $_rbac           Unused.
	 * @param bool            $_multitenancy   Unused.
	 * @param bool            $_retentionSweep Unused.
	 * @param mixed           $currentUser     Unused.
	 * @param bool            $permanent       Unused.
	 *
	 * @return bool
	 */
	public function deleteObject(
		?string $uuid = null,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_retentionSweep = false,
		mixed $currentUser = null,
		bool $permanent = false,
	): bool {
		unset($this->objects[(string)$schema][(string)$uuid]);
		$this->deleted[] = (string)$uuid;

		return true;

	}//end deleteObject()

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
