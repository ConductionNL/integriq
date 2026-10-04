<?php

/**
 * Contract tests for the dso_activity_mapping register fragment, its demo
 * rows and the dso_verzoek mappedActivities shape.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\EventListener\DsoActivityMappingGuardListener;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The schema accepts a full row and refuses a broken one, the demo rows pass
 * both the schema and the guard listener, the production register ships no
 * rows, and old mappedActivities items still validate.
 */
class DsoActivityMappingFragmentTest extends TestCase {

	/**
	 * A full row.
	 *
	 * @return array<string, mixed>
	 */
	private function fullRow(): array {
		return [
			'imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen',
			'activityId' => 'Demo-0000-Bouwen',
			'activityName' => 'Demo: bouwen',
			'caseTypes' => [
				['reference' => 'https://catalogi.example/zaaktypen/1', 'title' => 'Demo bouwen', 'department' => 'Demo team'],
				['reference' => 'DEMO-2'],
			],
			'samenloopStrategy' => 'deelzaken',
			'samenloopRules' => [['withImowId' => 'nl.imow-pv0000.activiteit.DemoOther', 'strategy' => 'gecombineerd']],
			'isActive' => true,
			'note' => 'Demo',
		];
	}//end fullRow()

	/**
	 * The demo rows of the mock register, by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function demoRows(): array {
		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'), true);
		$rows = [];
		foreach ($mock['components']['objects'] as $object) {
			if (($object['@self']['schema'] ?? null) === DsoActivityTable::SCHEMA) {
				$this->assertSame('integriq', $object['@self']['register']);
				$slug = $object['@self']['slug'];
				unset($object['@self']);
				$rows[$slug] = $object;
			}
		}

		return $rows;
	}//end demoRows()

	/**
	 * The fragment adds the schema to the register, admin-only on every verb.
	 *
	 * @return void
	 */
	public function testTheSchemaIsOnTheRegisterAndAdminOnly(): void {
		$merged = RegisterSchemaValidator::descriptor();

		$this->assertContains(DsoActivityTable::SCHEMA, $merged['components']['registers']['integriq']['schemas']);
		$schema = $merged['components']['schemas'][DsoActivityTable::SCHEMA];
		$this->assertSame(
			['create' => ['admin'], 'read' => ['admin'], 'update' => ['admin'], 'delete' => ['admin']],
			$schema['authorization']
		);
		$this->assertArrayNotHasKey('anyOf', $schema, 'OpenRegister reads a schema-level anyOf as composition; the guard listener carries that rule');
	}//end testTheSchemaIsOnTheRegisterAndAdminOnly()

	/**
	 * A full row saves; a row without caseTypes, with empty caseTypes, with an
	 * imowId off the STAM pattern or with an unknown strategy is refused.
	 *
	 * @return void
	 */
	public function testTheSchemaAcceptsAFullRowAndRefusesBrokenOnes(): void {
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: DsoActivityTable::SCHEMA, object: $this->fullRow()));

		$withoutCaseTypes = $this->fullRow();
		unset($withoutCaseTypes['caseTypes']);
		$broken = [
			'no caseTypes' => $withoutCaseTypes,
			'empty caseTypes' => (['caseTypes' => [['title' => 'no reference']]] + $this->fullRow()),
			'imowId off the pattern' => (['imowId' => 'Bouwen'] + $this->fullRow()),
			'unknown bevoegd gezag' => (['imowId' => 'nl.imow-xx0000.activiteit.Demo'] + $this->fullRow()),
			'unknown strategy' => (['samenloopStrategy' => 'samen'] + $this->fullRow()),
			'rule off the pattern' => (['samenloopRules' => [['withImowId' => 'kappen', 'strategy' => 'deelzaken']]] + $this->fullRow()),
		];
		foreach ($broken as $name => $row) {
			$this->assertNotSame([], RegisterSchemaValidator::errors(schemaSlug: DsoActivityTable::SCHEMA, object: $row), $name);
		}
	}//end testTheSchemaAcceptsAFullRowAndRefusesBrokenOnes()

	/**
	 * Three demo rows, visibly fake, each accepted by the schema and by the
	 * guard listener: a seed row either one refused would be dropped on import.
	 *
	 * @return void
	 */
	public function testTheDemoRowsAreFakeAndPassTheSchemaAndTheGuard(): void {
		$rows = $this->demoRows();
		$this->assertCount(3, $rows);
		$this->assertCount(2, $rows['dso-activity-mapping-demo-milieu']['caseTypes'], 'One demo row has two case types');
		$this->assertNotEmpty($rows['dso-activity-mapping-demo-kappen']['samenloopRules'], 'One demo row has a samenloop rule');

		$stored = [];
		foreach ($rows as $slug => $row) {
			$this->assertStringStartsWith('nl.imow-gm0000.', $row['imowId'], $slug);
			$this->assertStringStartsWith('Demo:', $row['activityName'], $slug);
			$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: DsoActivityTable::SCHEMA, object: $row), $slug);

			$entity = new ObjectEntity();
			$entity->setUuid($slug);
			$entity->setObject($row);
			$event = new ObjectCreatingEvent($entity);
			$this->guard(stored: $stored)->handle($event);
			$this->assertFalse($event->isPropagationStopped(), $slug . ': ' . json_encode($event->getErrors()));
			$stored[] = $entity;
		}
	}//end testTheDemoRowsAreFakeAndPassTheSchemaAndTheGuard()

	/**
	 * The production register and its fragments ship no rows.
	 *
	 * @return void
	 */
	public function testTheProductionRegisterShipsNoRows(): void {
		foreach ((RegisterSchemaValidator::descriptor()['components']['objects'] ?? []) as $object) {
			$this->assertNotSame(DsoActivityTable::SCHEMA, ($object['@self']['schema'] ?? null));
		}
	}//end testTheProductionRegisterShipsNoRows()

	/**
	 * An item written before this change still validates, in both registers.
	 *
	 * @return void
	 */
	public function testAnOldMappedActivitiesItemStillValidates(): void {
		$old = [
			'verzoekId' => 'dso-old',
			'status' => 'mapped',
			'mappedActivities' => [
				['code' => 'k', 'description' => 'Kappen', 'mapped' => true, 'caseType' => 'X', 'samenloopStrategy' => 'gecombineerd'],
				['code' => 'u', 'description' => '', 'mapped' => false],
			],
		];
		$this->assertSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $old));

		$mock = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/integriq_mock_register.json'), true);
		$this->assertSame(
			RegisterSchemaValidator::descriptor()['components']['schemas']['dso_verzoek']['properties']['mappedActivities'],
			$mock['components']['schemas']['dso_verzoek']['properties']['mappedActivities'],
			'Both registers declare the same item'
		);
	}//end testAnOldMappedActivitiesItemStillValidates()

	/**
	 * The guard listener over these stored rows.
	 *
	 * @param array<int, ObjectEntity> $stored The rows already stored.
	 *
	 * @return DsoActivityMappingGuardListener
	 */
	private function guard(array $stored): DsoActivityMappingGuardListener {
		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturn(['results' => $stored]);
		$slug = static fn (string $value): object => new class($value) {
			/**
			 * @param string $value The slug.
			 */
			public function __construct(private string $value) {
			}

			/**
			 * @return string
			 */
			public function getSlug(): string {
				return $this->value;
			}
		};
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($slug('integriq'));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($slug(DsoActivityTable::SCHEMA));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DsoActivityMappingGuardListener(
			table: new DsoActivityTable($objectService),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end guard()
}//end class
