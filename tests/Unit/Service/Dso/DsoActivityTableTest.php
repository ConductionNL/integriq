<?php

/**
 * Unit tests for DsoActivityTable.
 *
 * Replaces DsoActivityMapperTest, which pinned the 25 placeholder codes of
 * the retired built-in table (change dso-activity-mapping-table, task 3.4).
 * The mapping itself is tested through its caller, DsoIngestServiceTest.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The table is read as an engine read of admin configuration, page by page.
 */
class DsoActivityTableTest extends TestCase {

	/**
	 * An entity with this data and uuid.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * The read is `_rbac` and `_multitenancy` off, on the right schema, and
	 * an inactive row is left out while a row without `isActive` stays.
	 *
	 * @return void
	 */
	public function testActiveRowsIsAnEngineReadThatSkipsInactiveRows(): void {
		$calls = [];
		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$calls): array {
				$calls[] = [$config, $_rbac, $_multitenancy];
				return [
					'results' => [
						$this->entity('a', ['imowId' => 'x', 'isActive' => true]),
						$this->entity('b', ['imowId' => 'y', 'isActive' => false]),
						$this->entity('c', ['imowId' => 'z']),
					],
				];
			}
		);

		$rows = (new DsoActivityTable($objectService))->activeRows();

		$this->assertSame(['a', 'c'], array_column($rows, 'id'));
		$this->assertCount(1, $calls, 'Fewer rows than a page: one read');
		[$config, $rbac, $multitenancy] = $calls[0];
		$this->assertSame(['register' => 'integriq', 'schema' => 'dso_activity_mapping'], $config['filters']);
		$this->assertFalse($rbac);
		$this->assertFalse($multitenancy);
	}//end testActiveRowsIsAnEngineReadThatSkipsInactiveRows()

	/**
	 * A full page is followed by the next one.
	 *
	 * @return void
	 */
	public function testAFullPageReadsTheNextPage(): void {
		$offsets = [];
		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$offsets): array {
				$offsets[] = $config['offset'];
				if ($config['offset'] === 0) {
					$page = [];
					for ($i = 0; $i < $config['limit']; $i++) {
						$page[] = $this->entity('row-' . $i, ['activityId' => 'a' . $i]);
					}

					return ['results' => $page];
				}

				return ['results' => [$this->entity('last', ['activityId' => 'last'])]];
			}
		);

		$rows = (new DsoActivityTable($objectService))->allRows();

		$this->assertSame([0, 500], $offsets);
		$this->assertCount(501, $rows);
	}//end testAFullPageReadsTheNextPage()
}//end class
