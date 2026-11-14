<?php

/**
 * Integriq RegistrationStore.
 *
 * Reads registration targets and reads and writes registration records.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

use OCA\Integriq\Service\SystemWrite;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Integriq's own configuration, read and written as the system.
 *
 * Both schemas are admin-only, and a hand-over runs in the calling app's
 * background job with no administrator signed in, so every read and write
 * here runs as OpenRegister's system principal. Callers decide who may use a
 * target; this class only stores.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
 */
class RegistrationStore {

	public const REGISTER = 'integriq';

	public const TARGET_SCHEMA = 'registrationTarget';

	public const RECORD_SCHEMA = 'registrationRecord';

	/**
	 * Most rows one read returns.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService OpenRegister's object service.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Every registration target.
	 *
	 * @return array<int,array<string,mixed>> The targets' data.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	public function findTargets(): array {
		return array_column($this->rows(schema: self::TARGET_SCHEMA, filters: []), 'data');
	}//end findTargets()

	/**
	 * One registration target by slug.
	 *
	 * @param string $slug The target slug.
	 *
	 * @return array<string,mixed>|null The target's data, or null when there is none.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-administrator-defines-a-registration-target-of-one-of-four-kinds-req-sreg-001
	 */
	public function findTarget(string $slug): ?array {
		foreach ($this->rows(schema: self::TARGET_SCHEMA, filters: ['slug' => $slug]) as $row) {
			if (($row['data']['slug'] ?? null) === $slug) {
				return $row['data'];
			}
		}

		return null;
	}//end findTarget()

	/**
	 * The record of one submission at one target, for one app.
	 *
	 * @param string $target    The target slug.
	 * @param string $app       The calling app.
	 * @param string $reference The submission reference.
	 *
	 * @return array{uuid:string,data:array<string,mixed>}|null The record, or null when there is none.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function findRecord(string $target, string $app, string $reference): ?array {
		$filters = ['target' => $target, 'app' => $app, 'submissionReference' => $reference];
		foreach ($this->rows(schema: self::RECORD_SCHEMA, filters: $filters) as $row) {
			// The filter is a hint to OpenRegister, not a guarantee: match again.
			if (self::matches(data: $row['data'], filters: $filters) === true) {
				return $row;
			}
		}

		return null;
	}//end findRecord()

	/**
	 * Whether a row carries every filter value, compared as strings.
	 *
	 * @param array<string,mixed>  $data    The row data.
	 * @param array<string,string> $filters The filters.
	 *
	 * @return bool
	 */
	private static function matches(array $data, array $filters): bool {
		foreach ($filters as $key => $value) {
			if ((string)($data[$key] ?? '') !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * Create or replace a record.
	 *
	 * Null values are left out: OpenRegister saves with PUT semantics and
	 * would otherwise check them against the property formats.
	 *
	 * @param array<string,mixed> $data The record data.
	 * @param string|null         $uuid The record to replace, or null to create one.
	 *
	 * @return string The record's uuid.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemWrite is integriq's static elevation guard; there is no instance API.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	public function saveRecord(array $data, ?string $uuid = null): string {
		$payload = array_filter($data, static fn (mixed $value): bool => $value !== null);
		$saved   = SystemWrite::run(
			what: 'a registration record write',
			operation: fn () => $this->objectService->saveObject(
				object: $payload,
				register: self::REGISTER,
				schema: self::RECORD_SCHEMA,
				uuid: $uuid
			)
		);

		return (string)$saved->getUuid();
	}//end saveRecord()

	/**
	 * The rows of one schema that match the filters.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return array<int,array{uuid:string,data:array<string,mixed>}>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemWrite is integriq's static elevation guard; there is no instance API.
	 */
	private function rows(string $schema, array $filters): array {
		$result = SystemWrite::run(
			what: 'a registration target read',
			operation: fn () => $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema] + $filters, 'limit' => self::READ_LIMIT],
				_rbac: false,
				_multitenancy: false
			)
		);

		$rows = [];
		foreach (($result['results'] ?? $result) as $entity) {
			if ($entity instanceof ObjectEntity === true && is_array($entity->getObject()) === true) {
				$rows[] = ['uuid' => (string)$entity->getUuid(), 'data' => $entity->getObject()];
			}
		}

		return $rows;
	}//end rows()
}//end class
