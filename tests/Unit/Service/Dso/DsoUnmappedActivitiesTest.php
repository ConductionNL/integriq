<?php

/**
 * Unit tests for DsoUnmappedActivities and its admin endpoint.
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
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Controller\DsoActivityMappingController;
use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Service\Dso\DsoUnmappedActivities;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The verzoeken are written by the real mapper, so the list reads the shape
 * intake really stores.
 */
class DsoUnmappedActivitiesTest extends TestCase {

	/**
	 * The mapping rows.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $rows = [];

	/**
	 * The stored verzoeken.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $verzoeken = [];

	/**
	 * The object store fake: rows and verzoeken by schema.
	 *
	 * @return ORObjectService
	 */
	private function objectService(): ORObjectService {
		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true): array {
				$this->assertFalse($_rbac, 'Engine read');
				if (($config['filters']['schema'] ?? null) === DsoActivityTable::SCHEMA) {
					return ['results' => $this->rows];
				}

				$this->assertTrue($config['filters']['activityUnmapped']);
				return ['results' => $this->verzoeken];
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * Store a verzoek as intake writes it, mapped against the current rows.
	 *
	 * @param string $receivedAt When it arrived.
	 * @param array<int, array<string, mixed>> $activiteiten The parsed activiteiten.
	 *
	 * @return void
	 */
	private function receive(string $receivedAt, array $activiteiten): void {
		$fields = (new DsoActivityMapper(new DsoActivityTable($this->objectService())))->mapRequest(activiteiten: $activiteiten);
		$entity = new ObjectEntity();
		$entity->setUuid('verzoek-' . count($this->verzoeken));
		$entity->setObject(['verzoekId' => 'v' . count($this->verzoeken), 'status' => 'mapped', 'receivedAt' => $receivedAt] + $fields);
		$this->verzoeken[] = $entity;
	}//end receive()

	/**
	 * The service under test.
	 *
	 * @return DsoUnmappedActivities
	 */
	private function service(): DsoUnmappedActivities {
		$objectService = $this->objectService();
		$table = new DsoActivityTable($objectService);

		return new DsoUnmappedActivities($objectService, $table, new DsoActivityMapper($table));
	}//end service()

	/**
	 * Activities are grouped by identifier, counted, and dated by their last
	 * verzoek; one a row now maps drops out; one without identifiers is counted.
	 *
	 * @return void
	 */
	public function testUnmappedActivitiesAreGroupedCountedAndDated(): void {
		$this->receive('2026-10-01T09:00:00+00:00', [['imowId' => 'nl.imow-gm0000.activiteit.DemoA', 'activityName' => 'A'], ['activityId' => 'Demo-B']]);
		$this->receive('2026-10-03T09:00:00+00:00', [['imowId' => 'nl.imow-gm0000.activiteit.DemoA'], ['activityName' => 'Zonder id']]);
		$this->receive('2026-10-02T09:00:00+00:00', [['imowId' => 'nl.imow-gm0000.activiteit.DemoC', 'activityId' => 'Demo-C']]);

		$row = new ObjectEntity();
		$row->setUuid('row-c');
		$row->setObject(['imowId' => 'nl.imow-gm0000.activiteit.DemoC', 'activityName' => 'C', 'caseTypes' => [['reference' => 'X']], 'samenloopStrategy' => 'deelzaken']);
		$this->rows = [$row];

		$list = $this->service()->list();

		$this->assertSame(
			[
				['imowId' => 'nl.imow-gm0000.activiteit.DemoA', 'activityId' => '', 'activityName' => 'A', 'count' => 2, 'lastSeen' => '2026-10-03T09:00:00+00:00'],
				['imowId' => '', 'activityId' => 'Demo-B', 'activityName' => '', 'count' => 1, 'lastSeen' => '2026-10-01T09:00:00+00:00'],
			],
			$list['activities']
		);
		$this->assertSame(1, $list['withoutIdentifier']);
		$this->assertSame(3, $list['scanned']);
	}//end testUnmappedActivitiesAreGroupedCountedAndDated()

	/**
	 * A verzoek stored before this change, with `code`, still shows up.
	 *
	 * @return void
	 */
	public function testAnOldEntryIsReadByItsCode(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('old');
		$entity->setObject(['activityUnmapped' => true, 'receivedAt' => '2026-09-01', 'mappedActivities' => [['code' => 'oud-01', 'description' => 'Oud', 'mapped' => false]]]);
		$this->verzoeken = [$entity];

		$this->assertSame(
			[['imowId' => '', 'activityId' => 'oud-01', 'activityName' => 'Oud', 'count' => 1, 'lastSeen' => '2026-09-01']],
			$this->service()->list()['activities']
		);
	}//end testAnOldEntryIsReadByItsCode()

	/**
	 * The endpoint is an admin setting and answers the service's list.
	 *
	 * @return void
	 */
	public function testTheEndpointIsAdminOnly(): void {
		$attributes = (new ReflectionMethod(DsoActivityMappingController::class, 'unmapped'))->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $attributes);
		$this->assertSame(IntegriqAdmin::class, ($attributes[0]->getArguments()[0] ?? $attributes[0]->getArguments()['settings'] ?? null));

		$response = (new DsoActivityMappingController($this->createMock(IRequest::class), $this->service()))->unmapped();
		$this->assertSame(['activities' => [], 'withoutIdentifier' => 0, 'scanned' => 0, 'limit' => 500], $response->getData());
	}//end testTheEndpointIsAdminOnly()
}//end class
