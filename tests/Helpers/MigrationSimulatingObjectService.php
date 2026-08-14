<?php

/**
 * An OpenRegister ObjectService double for the inline-secret EXECUTOR tests.
 *
 * It extends {@see RenderBoundarySimulatingObjectService}'s intent but carries
 * the two things the executor needs beyond a rendered read:
 *
 *   1. ENTITY COLUMNS. `organisation` and `owner` are entity columns, NOT keys
 *      inside `object`. The executor reads them via `$entity->getOrganisation()`
 *      / `$entity->getOwner()` to mint a source credential at organisation scope,
 *      so each stored source carries `owner` + `organisation` alongside `object`.
 *   2. A PERSISTING saveObject(). The executor writes the nested `{credentialRef}`
 *      and nulls the inline value, then saves; a test proves the migration by
 *      RE-READING raw (`_render: false`) afterwards. So saveObject() replaces the
 *      stored object array in full and records every call for assertions.
 *
 * The render boundary itself is reproduced exactly as in
 * {@see RenderBoundarySimulatingObjectService}: `writeOnly` secret fields are
 * stripped on every rendered read and survive ONLY under `_render: false`. That
 * keeps the raw-read contract (which the planner shares) under test rather than
 * the double's return value.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Helpers;

use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Reproduces the render boundary AND entity columns AND persisting saves.
 */
class MigrationSimulatingObjectService extends OrObjectService {

	/**
	 * Stored sources, keyed by uuid: {object, owner, organisation}.
	 *
	 * @var array<string, array{object: array<string, mixed>, owner: ?string, organisation: ?string}>
	 */
	public array $stored = [];

	/**
	 * Every raw/rendered read served, for contract assertions.
	 *
	 * @var array<int, array{uuid: string, _render: bool, _rbac: bool}>
	 */
	public array $reads = [];

	/**
	 * Every saveObject() call, for round-trip assertions.
	 *
	 * @var array<int, array{uuid: ?string, object: array<string, mixed>, _rbac: bool, _multitenancy: bool}>
	 */
	public array $saves = [];

	/**
	 * When set, saveObject() throws for this uuid (save-failure isolation test).
	 *
	 * @var string|null
	 */
	public ?string $failSaveForUuid = null;

	/**
	 * Constructor — deliberately does not call the stub's constructor.
	 */
	public function __construct() {

	}//end __construct()

	/**
	 * Seed one source with its object data + entity columns.
	 *
	 * @param string $uuid The source uuid.
	 * @param array<string, mixed> $object The object data (may hold inline secrets).
	 * @param string|null $owner The owner UID.
	 * @param string|null $organisation The organisation UUID.
	 *
	 * @return void
	 */
	public function seed(string $uuid, array $object, ?string $owner, ?string $organisation): void {
		$this->stored[$uuid] = [
			'object' => $object,
			'owner' => $owner,
			'organisation' => $organisation,
		];
	}//end seed()

	/**
	 * Reproduce ObjectService::find() including the render boundary + entity columns.
	 *
	 * @param string|int $id The object uuid.
	 * @param string|null $register Unused.
	 * @param string|null $schema Unused.
	 * @param bool $_rbac Recorded for the contract assertion.
	 * @param bool $_multitenancy Unused.
	 * @param bool $_render When true, strip writeOnly secrets (mirrors openregister#389).
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
		$this->reads[] = ['uuid' => (string)$id, '_render' => $_render, '_rbac' => $_rbac];

		$entry = ($this->stored[(string)$id] ?? null);
		if ($entry === null) {
			return null;
		}

		$data = $entry['object'];
		if ($_render === true) {
			foreach (InlineSecretMigrationPlanner::SECRET_FIELDS as $writeOnlyField) {
				unset($data[$writeOnlyField]);
			}
		}

		return $this->makeEntity(uuid: (string)$id, data: $data, entry: $entry);
	}//end find()

	/**
	 * List sources (rendered — identity only, exactly as the planner expects).
	 *
	 * @param array $config Unused beyond shape.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array{results: ObjectEntity[], total: int}
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$results = [];
		foreach ($this->stored as $uuid => $entry) {
			$data = $entry['object'];
			foreach (InlineSecretMigrationPlanner::SECRET_FIELDS as $writeOnlyField) {
				unset($data[$writeOnlyField]);
			}

			$results[] = $this->makeEntity(uuid: (string)$uuid, data: $data, entry: $entry);
		}

		return ['results' => $results, 'total' => count($results)];
	}//end findAll()

	/**
	 * Persist a save: replace the stored object array in full, and record the call.
	 *
	 * @param array|ObjectEntity $object The full object data to persist.
	 * @param string|null $register Unused.
	 * @param string|null $schema Unused.
	 * @param string|null $uuid The target uuid.
	 * @param bool $_rbac Recorded for the system-context assertion.
	 * @param bool $_multitenancy Recorded.
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
		$data = $object;
		if ($object instanceof ObjectEntity === true) {
			$data = $object->getObject();
		}

		if (is_array($data) === false) {
			$data = [];
		}

		$target = (string)($uuid ?? ($data['id'] ?? ($data['uuid'] ?? '')));

		$this->saves[] = [
			'uuid' => $uuid,
			'object' => $data,
			'_rbac' => $_rbac,
			'_multitenancy' => $_multitenancy,
		];

		if ($this->failSaveForUuid !== null && $target === $this->failSaveForUuid) {
			throw new \RuntimeException('save failed (simulated)');
		}

		if ($target !== '' && isset($this->stored[$target]) === true) {
			$this->stored[$target]['object'] = $data;
		}

		$entry = ($this->stored[$target] ?? ['object' => $data, 'owner' => null, 'organisation' => null]);
		return $this->makeEntity(uuid: $target, data: $data, entry: $entry);
	}//end saveObject()

	/**
	 * Build an ObjectEntity carrying uuid + object + owner + organisation columns.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 * @param array{object: array<string, mixed>, owner: ?string, organisation: ?string} $entry The stored entry.
	 *
	 * @return ObjectEntity
	 */
	private function makeEntity(string $uuid, array $data, array $entry): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		if ($entry['owner'] !== null) {
			$entity->setOwner($entry['owner']);
		}

		if ($entry['organisation'] !== null) {
			$entity->setOrganisation($entry['organisation']);
		}

		return $entity;
	}//end makeEntity()
}//end class
