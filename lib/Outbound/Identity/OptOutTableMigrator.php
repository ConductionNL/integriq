<?php

/**
 * Integriq OptOutTableMigrator.
 *
 * Copies the opt-outs stored in OpenRegister (`recipient_opt_out`) into
 * integriq's own table, once per opt-out. Run by the MigrateOptOutsToTable
 * repair step; an upgrade or maintenance:repair that runs it again copies
 * nothing new. The OpenRegister objects are left in place as read-only
 * history.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

use DateTimeImmutable;
use OCA\Integriq\Db\OptOut;
use OCA\Integriq\Db\OptOutMapper;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Throwable;

/**
 * One-way copy of the OpenRegister opt-outs into the table.
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
class OptOutTableMigrator {

	/**
	 * How many objects one read fetches.
	 *
	 * @var int
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the old opt-outs (read only).
	 * @param OptOutMapper $mapper Writes the table.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly OptOutMapper $mapper,
	) {

	}//end __construct()

	/**
	 * Copy every OpenRegister opt-out the table does not hold yet.
	 *
	 * The read is an engine read (`_rbac: false`): a repair step has no user,
	 * and the schema is deny-all. Nothing is written to OpenRegister.
	 *
	 * @return array{read:int,copied:int,present:int,skipped:int} What it did.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function migrate(): array {
		$result = ['read' => 0, 'copied' => 0, 'present' => 0, 'skipped' => 0];
		$offset = 0;

		do {
			$rows = $this->readPage(offset: $offset);
			foreach ($rows as $row) {
				$result['read']++;
				$optOut = $this->fromObject(object: $row->getObject(), uuid: (string)$row->getUuid());
				if ($optOut === null) {
					$result['skipped']++;
					continue;
				}

				$created = $this->mapper->insertIfAbsent($optOut)['created'];
				if ($created === true) {
					$result['copied']++;
					continue;
				}

				$result['present']++;
			}

			$offset += self::PAGE;
		} while (count($rows) === self::PAGE);

		return $result;

	}//end migrate()

	/**
	 * One page of `recipient_opt_out` objects.
	 *
	 * @param int $offset Skip this many.
	 *
	 * @return list<ObjectEntity> The objects.
	 */
	private function readPage(int $offset): array {
		$matches = $this->objectService->findAll(
			config: [
				'limit' => self::PAGE,
				'offset' => $offset,
				'filters' => [
					'register' => MessageRecorder::REGISTER,
					'schema' => OptOutRegistry::SCHEMA,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		return array_values(
			array_filter($results, static fn (mixed $row): bool => $row instanceof ObjectEntity)
		);

	}//end readPage()

	/**
	 * The table row for one old opt-out, or null when it names no address.
	 *
	 * @param array<string,mixed> $object The object body.
	 * @param string $uuid The object uuid.
	 *
	 * @return OptOut|null The row.
	 */
	private function fromObject(array $object, string $uuid): ?OptOut {
		$address = strtolower(trim((string)($object['address'] ?? '')));
		if ($address === '') {
			return null;
		}

		$scope = (string)($object['scope'] ?? OptOutRegistry::SCOPE_INSTANCE);
		if ($scope !== OptOutRegistry::SCOPE_CASE) {
			$scope = OptOutRegistry::SCOPE_INSTANCE;
		}

		$caseRef = (string)($object['caseRef'] ?? '');
		if ($scope === OptOutRegistry::SCOPE_INSTANCE) {
			$caseRef = '';
		}

		$optOut = new OptOut();
		$optOut->setAddress($address);
		$optOut->setScope($scope);
		$optOut->setCaseRef($caseRef);
		$optOut->setSource((string)($object['source'] ?? 'openregister'));
		$optOut->setCreatedAt($this->timestamp(value: (string)($object['createdAt'] ?? '')));
		$optOut->setDedupeKey(OptOut::keyFor(address: $address, scope: $scope, caseRef: $caseRef));
		$optOut->setLegacyUuid($uuid);

		return $optOut;

	}//end fromObject()

	/**
	 * A stored date as a unix timestamp, or now when it does not parse.
	 *
	 * @param string $value The ISO 8601 date.
	 *
	 * @return int The timestamp.
	 */
	private function timestamp(string $value): int {
		if (trim($value) === '') {
			return time();
		}

		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Throwable) {
			return time();
		}

	}//end timestamp()

}//end class
