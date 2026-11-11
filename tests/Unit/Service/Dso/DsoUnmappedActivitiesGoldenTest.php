<?php

/**
 * Golden test for DsoUnmappedActivities::list.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Service\Dso\DsoUnmappedActivities;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the unmapped-activity list: grouping, counts, last seen, ordering, the
 * entries without an identifier, and activities a row was added for later.
 */
class DsoUnmappedActivitiesGoldenTest extends TestCase {

	/**
	 * An entity holding the given object.
	 *
	 * @param array $data The object.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return $entity;

	}//end entity()

	/**
	 * The list over the given rows and verzoeken.
	 *
	 * @return void
	 */
	public function testTheList(): void {
		$rows      = [self::entity(['id' => 1, 'activityId' => 'ACT-LATER', 'active' => true])];
		$verzoeken = [
			self::entity(
				[
					'activityUnmapped' => true,
					'receivedAt' => '2026-03-01',
					'mappedActivities' => [
						['mapped' => false, 'imowId' => 'imow-A', 'activityName' => 'Bouwen'],
						['mapped' => true, 'activityId' => 'ACT-OK'],
						'not-an-entry',
						['mapped' => false, 'activityName' => 'Zonder id'],
						['mapped' => false, 'activityId' => 'ACT-LATER'],
						['mapped' => false, 'activityId' => 'ACT-B', 'underlying' => ['imowId' => 'imow-U']],
					],
				]
			),
			self::entity(['activityUnmapped' => false, 'mappedActivities' => [['mapped' => false, 'activityId' => 'ACT-IGNORED']]]),
			self::entity(
				[
					'activityUnmapped' => true,
					'receivedAt' => '2026-02-01',
					'mappedActivities' => [
						['mapped' => false, 'imowId' => 'imow-A', 'activityName' => 'Bouwen (oud)'],
						['mapped' => false, 'activityId' => 'ACT-C'],
						['activityId' => 'ACT-D'],
					],
				]
			),
			self::entity(['activityUnmapped' => true, 'mappedActivities' => 'none']),
		];

		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config=[]) use ($rows, $verzoeken): array {
				if (($config['filters']['schema'] ?? null) === DsoActivityTable::SCHEMA) {
					return ['results' => $rows];
				}

				return ['results' => array_merge($verzoeken, ['not-an-entity'])];
			}
		);
		$table = new DsoActivityTable($objectService);
		$list  = (new DsoUnmappedActivities($objectService, $table, new DsoActivityMapper($table)))->list();

		$golden = __DIR__.'/../../../fixtures/dso/unmapped-activities.json';
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($golden, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents($golden), true), $list);

	}//end testTheList()
}//end class
