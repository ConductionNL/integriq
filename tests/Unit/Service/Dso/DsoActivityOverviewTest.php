<?php

/**
 * Unit tests for DsoActivityOverview.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Controller\DsoActivityMappingController;
use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoActivityOverview;
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
 * Every row with when a verzoek last matched it, then the unmapped activities.
 */
class DsoActivityOverviewTest extends TestCase {

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
	 * The object store fake: rows and verzoeken by schema; the unmapped read
	 * filters on activityUnmapped.
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

				if (($config['filters']['activityUnmapped'] ?? null) === true) {
					return ['results' => array_values(array_filter($this->verzoeken, static fn (ObjectEntity $verzoek): bool => ($verzoek->getObject()['activityUnmapped'] ?? false) === true))];
				}

				return ['results' => $this->verzoeken];
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * Store a mapping row.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The row.
	 *
	 * @return void
	 */
	private function row(string $uuid, array $data): void {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$this->rows[] = $entity;
	}//end row()

	/**
	 * Store a verzoek as intake writes it, mapped against the current rows.
	 *
	 * @param string                           $receivedAt   When it arrived.
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
	 * @return DsoActivityOverview
	 */
	private function service(): DsoActivityOverview {
		$objectService = $this->objectService();
		$table = new DsoActivityTable($objectService);

		return new DsoActivityOverview($objectService, $table, new DsoUnmappedActivities($objectService, $table, new DsoActivityMapper($table)));
	}//end service()

	/**
	 * A row gets the newest arrival among the verzoeken that matched it, a row
	 * nothing matched gets null, and an unmapped activity follows as a row.
	 *
	 * @return void
	 */
	public function testRowsCarryTheirLastArrivalAndTheUnmappedFollow(): void {
		$this->row('kap', ['activityId' => 'kap', 'activityName' => 'Kappen van een houtopstand', 'caseTypes' => [['reference' => 'OV', 'variant' => 'regulier']]]);
		$this->row('bouw', ['activityId' => 'bouw', 'activityName' => 'Bouwen van een bouwwerk', 'caseTypes' => [['reference' => 'OV']]]);
		$this->receive('2026-10-05T09:00:00+00:00', [['activityId' => 'kap', 'activityName' => 'Kappen']]);
		$this->receive('2026-10-06T09:00:00+00:00', [['activityId' => 'kap', 'activityName' => 'Kappen']]);
		$this->receive('2026-10-07T10:00:00+00:00', [['activityId' => 'onbekend', 'activityName' => 'Onbekende activiteit']]);

		$rows = $this->service()->rows()['results'];

		$this->assertSame(['kap', 'bouw', 'unmapped:activityId:onbekend'], array_column($rows, 'id'));
		$this->assertSame('2026-10-06T09:00:00+00:00', $rows[0]['lastReceivedAt']);
		$this->assertSame('regulier', $rows[0]['caseTypes'][0]['variant']);
		$this->assertFalse($rows[0]['unmapped']);
		$this->assertNull($rows[1]['lastReceivedAt']);
		$this->assertTrue($rows[2]['unmapped']);
		$this->assertSame(1, $rows[2]['waiting']);
		$this->assertSame('Onbekende activiteit', $rows[2]['activityName']);
		$this->assertSame('2026-10-07T10:00:00+00:00', $rows[2]['lastReceivedAt']);
	}//end testRowsCarryTheirLastArrivalAndTheUnmappedFollow()

	/**
	 * The mapper hands a case type's variant on with it.
	 *
	 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-a-case-type-entry-of-a-mapping-row-may-name-its-variant-req-dso-013
	 *
	 * @return void
	 */
	public function testTheMapperHandsTheVariantOn(): void {
		$this->row('kap', ['activityId' => 'kap', 'activityName' => 'Kappen', 'caseTypes' => [['reference' => 'OV', 'title' => 'Omgevingsvergunning', 'variant' => 'regulier']]]);

		$fields = (new DsoActivityMapper(new DsoActivityTable($this->objectService())))->mapRequest(activiteiten: [['activityId' => 'kap']]);

		$this->assertSame([['reference' => 'OV', 'title' => 'Omgevingsvergunning', 'variant' => 'regulier']], $fields['mappedActivities'][0]['caseTypes']);
	}//end testTheMapperHandsTheVariantOn()

	/**
	 * The endpoint is an admin setting and answers the overview.
	 *
	 * @return void
	 */
	public function testTheEndpointIsAdminOnly(): void {
		$attributes = (new ReflectionMethod(DsoActivityMappingController::class, 'overview'))->getAttributes(AuthorizedAdminSetting::class);
		$this->assertCount(1, $attributes);
		$this->assertSame(IntegriqAdmin::class, ($attributes[0]->getArguments()[0] ?? $attributes[0]->getArguments()['settings'] ?? null));

		$service = $this->service();
		$objectService = $this->objectService();
		$table = new DsoActivityTable($objectService);
		$controller = new DsoActivityMappingController(
			$this->createMock(IRequest::class),
			new DsoUnmappedActivities($objectService, $table, new DsoActivityMapper($table)),
			$service
		);
		$this->assertSame(['results' => []], $controller->overview()->getData());
	}//end testTheEndpointIsAdminOnly()
}//end class
