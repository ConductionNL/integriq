<?php

/**
 * Integriq ConnectionStore.
 *
 * The only class that reads and writes `app_connection` rows and the sources they
 * link, through OpenRegister. Every call runs as a system operation: a report
 * can arrive from a non-admin request in another app, repair steps and cron
 * run without a user, and the `app_connection` and `source` schemas are admin-only.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Persistence for connection rows.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
 */
class ConnectionStore {

	/**
	 * The register every connection row lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The connection schema slug. Not `connection`: stackiq owns that global slug (umbrella D11).
	 *
	 * @var string
	 */
	public const SCHEMA = 'app_connection';

	/**
	 * The properties a row carries (umbrella design D3). Anything else in a
	 * read-back, such as `@self`, is dropped before a write.
	 *
	 * @var string[]
	 */
	public const PROPERTIES = [
		'slug',
		'app',
		'key',
		'title',
		'description',
		'order',
		'settingsUrl',
		'declaration',
		'declaredVersion',
		'status',
		'statusMessage',
		'checkedAt',
		'source',
		'lastProbe',
		'lastReport',
		'refreshedAt',
	];

	/**
	 * The most rows read in one call. A fleet declares tens, not thousands.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Read connection rows, optionally for one app.
	 *
	 * @param string|null $app The declaring app, or null for every row.
	 *
	 * @return array<int,array{uuid:string,data:array<string,mixed>}>
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function findRows(?string $app = null): array {
		$filters = ['register' => self::REGISTER, 'schema' => self::SCHEMA];
		if ($app !== null) {
			$filters['app'] = $app;
		}

		// RBAC and multitenancy off, as findEntity does. Under webcron there is
		// no user, so a scoped read answers the empty set, and the sync then
		// takes every declared row for new and writes it a second time.
		$result = $this->asSystem(
			operation: 			fn () => $this->objectService->findAll(
				config: ['filters' => $filters, 'limit' => self::READ_LIMIT],
				_rbac: false,
				_multitenancy: false
			)
		);

		$rows = [];
		foreach (($result['results'] ?? $result) as $entity) {
			if ($entity instanceof ObjectEntity === false) {
				continue;
			}

			$data = $entity->getObject();
			// The filter is a hint to OpenRegister, not a guarantee: match again.
			if (is_array($data) === false || ($app !== null && ($data['app'] ?? null) !== $app)) {
				continue;
			}

			$rows[] = ['uuid' => (string)$entity->getUuid(), 'data' => $data];
		}

		return $rows;
	}//end findRows()

	/**
	 * Read one connection row by uuid.
	 *
	 * @param string $uuid The row uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>}|null
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function findRow(string $uuid): ?array {
		$entity = $this->findEntity(uuid: $uuid, schema: self::SCHEMA);
		if ($entity === null || is_array($entity->getObject()) === false) {
			return null;
		}

		return ['uuid' => (string)$entity->getUuid(), 'data' => $entity->getObject()];
	}//end findRow()

	/**
	 * Create or replace a row.
	 *
	 * OpenRegister saves with PUT semantics, so an absent property is stored as
	 * null. Null values are therefore left out rather than sent, which keeps
	 * `format: uuid` and `format: date-time` validation from refusing them.
	 *
	 * @param array<string,mixed> $data The row data.
	 * @param string|null $uuid The uuid to replace, or null to create.
	 *
	 * @return string The saved row's uuid.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function save(array $data, ?string $uuid = null): string {
		$payload = $this->payload(data: $data);

		$saved = $this->asSystem(
			operation: 			fn () => $this->objectService->saveObject(
				object: $payload,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid
			)
		);

		return (string)$saved->getUuid();
	}//end save()

	/**
	 * Delete a row.
	 *
	 * @param string $uuid The row uuid.
	 *
	 * @return void
	 *
	 * @spec exclude Backend-only persistence seam for the declaration sync (REQ-CONN-002).
	 *   No controller or frontend reaches it, so it is not the ADR-022 pass-through gate 17 looks for.
	 */
	public function delete(string $uuid): void {
		$this->asSystem(
			operation: 			fn () => $this->objectService->deleteObject(uuid: $uuid, register: self::REGISTER, schema: self::SCHEMA)
		);
	}//end delete()

	/**
	 * Read a source by uuid.
	 *
	 * @param string $uuid The source uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	public function findSource(string $uuid): ?ObjectEntity {
		return $this->findEntity(uuid: $uuid, schema: 'source');
	}//end findSource()

	/**
	 * Read a source by its slug.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function findSourceBySlug(string $slug): ?ObjectEntity {
		$result = $this->asSystem(
			operation: 			fn () => $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => 'source', 'slug' => $slug]],
				_rbac: false,
				_multitenancy: false
			)
		);

		foreach (($result['results'] ?? $result) as $entity) {
			if ($entity instanceof ObjectEntity === true && (($entity->getObject()['slug'] ?? null) === $slug)) {
				return $entity;
			}
		}

		return null;
	}//end findSourceBySlug()

	/**
	 * Create a source.
	 *
	 * @param array<string,mixed> $payload The source data.
	 *
	 * @return ObjectEntity The created source.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function createSource(array $payload): ObjectEntity {
		return $this->asSystem(
			operation: 			fn () => $this->objectService->saveObject(object: $payload, register: self::REGISTER, schema: 'source')
		);
	}//end createSource()

	/**
	 * Keep only the D3 properties that hold a value.
	 *
	 * @param array<string,mixed> $data The row data.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function payload(array $data): array {
		$payload = [];
		foreach (self::PROPERTIES as $property) {
			if (array_key_exists($property, $data) === true && $data[$property] !== null) {
				$payload[$property] = $this->canonical(value: $data[$property]);
			}
		}

		return $payload;
	}//end payload()

	/**
	 * Sort object keys recursively, so two reads of the same data compare equal.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			$value[$key] = $this->canonical(value: $item);
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return $value;
	}//end canonical()

	/**
	 * Read one object of a schema, or null when it does not exist.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity|null
	 */
	private function findEntity(string $uuid, string $schema): ?ObjectEntity {
		try {
			return $this->asSystem(
			operation: 				fn () => $this->objectService->find(
					id: $uuid,
					register: self::REGISTER,
					schema: $schema,
					_rbac: false,
					_multitenancy: false
				)
			);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findEntity()

	/**
	 * Run an operation as OpenRegister's system principal.
	 *
	 * Refuses rather than degrading when the context is absent: see SystemWrite.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext is OpenRegister's static scope guard; there is no instance API.
	 */
	private function asSystem(callable $operation): mixed {
		// 🔴 NO FALLBACK. This used to call $operation() plainly when the
		// context was absent, which ran the identical write as whoever was
		// signed in and returned the same value, so nothing distinguished it
		// from having elevated. SystemWrite throws instead.
		return SystemWrite::run(what: 'a connection registry write', operation: $operation);
	}//end asSystem()
}//end class
