<?php

/**
 * An OpenRegister ObjectService test double that reproduces the RENDER BOUNDARY.
 *
 * This is the honest fake at the heart of the inline-secret-migration tests
 * (ocon#151 phase C / ocon#215). The whole defect class it guards is that a
 * `source`'s credential fields are `writeOnly: true`, and OpenRegister strips
 * `writeOnly` properties on EVERY rendered read — admins, `_rbac: false`, and the
 * `@self.relations` mirror included (openregister#389/#429) — while a
 * `_render: false` read returns the raw entity with secrets intact.
 *
 * So this double strips {@see InlineSecretMigrationPlanner::SECRET_FIELDS} on any
 * rendered read and keeps them only when the caller passed `_render: false`. Any
 * production code that forgets `_render: false` therefore observes NO secret here
 * — exactly as it would in production — which turns the CONTRACT (which read
 * context was used) into the thing under test rather than the double's return
 * value. It is what makes the "delete `_render: false` and a test fails" mutation
 * guard real.
 *
 * It is a Helper (autoloaded via the Tests PSR-4 map) rather than an inline class
 * so both the planner tests and the repair-step tests share ONE definition.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-raw-secret-read
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Reproduces OpenRegister's render boundary for source-secret tests.
 */
class RenderBoundarySimulatingObjectService extends OrObjectService {

	/**
	 * Every read this double served, as a list of the arguments that matter.
	 *
	 * @var array<int, array{uuid: string, _render: bool, _rbac: bool}>
	 */
	public array $reads = [];

	/**
	 * The raw stored objects, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $stored = [];

	/**
	 * The organisation stamped on every entity this double returns.
	 *
	 * `getOrganisation()` is a magic method via `Entity::__call`, so callers that
	 * resolve through the broker need it set on the returned entity rather than
	 * mocked (see #1015).
	 *
	 * @var string
	 */
	public string $organisation = 'org-1';

	/**
	 * Constructor — deliberately does not call the stub's constructor.
	 */
	public function __construct() {

	}//end __construct()

	/**
	 * Every field the render boundary strips, across every migratable schema.
	 *
	 * `SECRET_FIELDS` alone is the `source` answer. Since the migration grew a
	 * schema map (`MIGRATABLE`), a double that stripped only `source`'s fields
	 * would let a `sender_identity` test pass while production stripped
	 * `smimePrivateKey` — which is exactly the gap integriq#2104 review
	 * 5264751700 blocker 1 found. The union keeps this double honest as the map
	 * grows: add a schema to `MIGRATABLE` and its fields are stripped here too.
	 *
	 * @return array<int, string> The write-only field names, de-duplicated.
	 */
	public static function writeOnlyFields(): array {
		$fields = InlineSecretMigrationPlanner::SECRET_FIELDS;
		foreach (InlineSecretMigrationPlanner::MIGRATABLE as $definition) {
			$fields = array_merge($fields, ($definition['fields'] ?? []));
		}

		return array_values(array_unique($fields));

	}//end writeOnlyFields()

	/**
	 * Reproduce ObjectService::find() including the render boundary.
	 *
	 * @param string|int $id The object id/uuid.
	 * @param string|null $register Unused.
	 * @param string|null $schema Unused.
	 * @param bool $_rbac Unused by the writeOnly strip (that is the point).
	 * @param bool $_multitenancy Unused.
	 * @param bool $_render When true, strip writeOnly properties.
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

		$data = ($this->stored[(string)$id] ?? null);
		if ($data === null) {
			return null;
		}

		if ($_render === true) {
			// openregister#389/#429: the writeOnly strip is a HARD render-boundary
			// rule. It is NOT gated on $_rbac and NOT gated on SystemOperationContext.
			foreach (self::writeOnlyFields() as $writeOnlyField) {
				unset($data[$writeOnlyField]);
			}
		}

		$entity = new ObjectEntity();
		$entity->setUuid((string)$id);
		$entity->setObject($data);
		// Positional arg: Entity::__call's setter uses $args[0].
		$entity->setOrganisation($this->organisation);
		return $entity;
	}//end find()

	/**
	 * List the stored objects (rendered, as the planner expects for identity only).
	 *
	 * @param array $config Unused beyond shape.
	 * @param bool $_rbac Unused.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array{results: ObjectEntity[], total: int}
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$results = [];
		foreach ($this->stored as $uuid => $data) {
			$entity = new ObjectEntity();
			$entity->setUuid((string)$uuid);
			// Identity listing goes through the render boundary too.
			foreach (self::writeOnlyFields() as $writeOnlyField) {
				unset($data[$writeOnlyField]);
			}

			$entity->setObject($data);
			$results[] = $entity;
		}

		return ['results' => $results, 'total' => count($results)];
	}//end findAll()
}//end class
