<?php

/**
 * Characterisation test for ActivityOverview::rows.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Portal;

use OCA\Integriq\Service\Portal\ActivityOverview;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the activity overview.
 */
class ActivityOverviewGoldenTest extends TestCase {

	/**
	 * The overview over the given rows, unmapped activities and verzoeken.
	 *
	 * @param array<int, array<string, mixed>> $tableRows The mapping rows.
	 * @param array<int, array<string, mixed>> $unmapped  The unmapped activities.
	 * @param mixed                            $verzoeken The read's answer.
	 *
	 * @return array<string, mixed>
	 */
	private function overview(array $tableRows, array $unmapped, mixed $verzoeken): array {
		$calls = [];
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (...$args) use (&$calls, $verzoeken): array {
				$calls[] = $args;
				return $verzoeken;
			}
		);
		$interfaces = (new \ReflectionClass(ActivityOverview::class))->getConstructor()->getParameters();
		$table = $this->createMock((string)$interfaces[1]->getType());
		$table->method('allRows')->willReturn($tableRows);
		$list = $this->createMock((string)$interfaces[2]->getType());
		$list->method('list')->willReturn(['activities' => $unmapped]);

		$rows = (new ActivityOverview($objects, $table, $list))->rows();

		return ['findAll' => $calls, 'rows' => $rows];
	}//end overview()

	/**
	 * The recorded overviews.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$entity = static function (array $data): ObjectEntity {
			$object = new ObjectEntity();
			$object->setObject($data);
			return $object;
		};
		$tableRows = [
			['id' => 'r-1', 'imowId' => 'nl.imow-gm0363.activiteit.1', 'activityName' => 'Bouwen'],
			['id' => 2, 'imowId' => '', 'activityId' => 'a-2', 'activityName' => 'Slopen'],
			['id' => 'r-3', 'imowId' => 'x', 'activityName' => 'Kappen'],
		];
		$verzoeken = ['results' => [
			$entity(['receivedAt' => '2026-01-02T10:00:00+00:00', 'mappedActivities' => [
				['mappingRow' => 'r-1', 'mapped' => true],
				['mappingRow' => '2', 'mapped' => true],
				['mappingRow' => 'r-3', 'mapped' => false],
				['mappingRow' => '', 'mapped' => true],
				'not an entry',
			]]),
			$entity(['receivedAt' => '2026-03-01T10:00:00+00:00', 'mappedActivities' => [['mappingRow' => 'r-1', 'mapped' => true], ['mappingRow' => 'r-3', 'mapped' => 'yes']]]),
			$entity(['receivedAt' => '2025-12-31T10:00:00+00:00', 'mappedActivities' => [['mappingRow' => '2', 'mapped' => true]]]),
			$entity(['mappedActivities' => [['mappingRow' => 'r-3', 'mapped' => true]]]),
			$entity(['receivedAt' => '2026-05-01T10:00:00+00:00', 'mappedActivities' => 'none']),
			['receivedAt' => '2027-01-01', 'mappedActivities' => [['mappingRow' => 'r-1', 'mapped' => true]]],
		]];
		$unmapped = [
			['imowId' => 'nl.imow.x', 'activityId' => 'ax', 'activityName' => 'Graven', 'count' => 3, 'lastSeen' => '2026-02-02'],
			['imowId' => '', 'activityId' => 'ay', 'activityName' => 'Dempen', 'count' => 1, 'lastSeen' => null],
		];
		$record = [
			'overview' => $this->overview($tableRows, $unmapped, $verzoeken),
			'plain list answer' => $this->overview($tableRows, [], [$entity(['receivedAt' => '2026-01-01', 'mappedActivities' => [['mappingRow' => 'r-3', 'mapped' => true]]])]),
			'nothing' => $this->overview([], [], []),
		];

		$path = __DIR__ . '/../../../fixtures/portal/activity-overview.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
