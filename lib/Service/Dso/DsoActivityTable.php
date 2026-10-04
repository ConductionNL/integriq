<?php

/**
 * Integriq DSO Activity Table.
 *
 * Reads the `dso_activity_mapping` rows an administrator keeps in OpenRegister
 * (change dso-activity-mapping-table, design D1). The intake reads them as an
 * engine read of admin configuration: `_rbac` off, read only, the same posture
 * the endpoint runtime uses for `rule`. The intake account never needs read
 * rights on the table.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * The DSO activity mapping table, read from OpenRegister.
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
 */
class DsoActivityTable {

	/**
	 * The register that holds the table.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema of one row.
	 *
	 * @var string
	 */
	public const SCHEMA = 'dso_activity_mapping';

	/**
	 * Rows read per page.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The most pages read, so a store that ignores the offset cannot loop forever.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 20;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Reads the rows.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Every active row, in store order, each with its uuid under `id`.
	 *
	 * A row without `isActive` is active, as the schema's default says.
	 * A failing read is not caught: a verzoek mapped against a table that
	 * could not be read would look unmapped, and nobody would know why.
	 *
	 * @return array<int, array<string, mixed>> The active rows.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-3.1
	 */
	public function activeRows(): array {
		$active = [];
		foreach ($this->allRows() as $row) {
			if (($row['isActive'] ?? true) === false) {
				continue;
			}

			$active[] = $row;
		}

		return $active;

	}//end activeRows()

	/**
	 * Every row, active or not, each with its uuid under `id`.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.2
	 */
	public function allRows(): array {
		$rows = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$result = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA],
					'limit' => self::PAGE_SIZE,
					'offset' => ($page * self::PAGE_SIZE),
				],
				_rbac: false,
				_multitenancy: false
			);
			$entities = array_values((array)($result['results'] ?? $result));
			foreach ($entities as $entity) {
				if ($entity instanceof ObjectEntity === true) {
					$rows[] = ((array)$entity->getObject() + ['id' => (string)$entity->getUuid()]);
				}
			}

			if (count($entities) < self::PAGE_SIZE) {
				break;
			}
		}

		return $rows;

	}//end allRows()
}//end class
