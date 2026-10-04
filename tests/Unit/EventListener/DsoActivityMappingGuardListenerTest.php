<?php

/**
 * Tests for DsoActivityMappingGuardListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\DsoActivityMappingGuardListener;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The refusals run on OpenRegister's own create and update path. The events
 * are the real OpenRegister classes (tests/stubs holds copies) and the table
 * read is the real DsoActivityTable over a fake object store.
 */
class DsoActivityMappingGuardListenerTest extends TestCase {

	/**
	 * The rows already stored.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $stored = [];

	/**
	 * The listener; the mappers resolve to the given schema slug.
	 *
	 * @param string $schemaSlug The schema slug.
	 *
	 * @return DsoActivityMappingGuardListener
	 */
	private function listener(string $schemaSlug = 'dso_activity_mapping'): DsoActivityMappingGuardListener {
		$objectService = $this->getMockBuilder(ORObjectService::class)->disableOriginalConstructor()->getMock();
		$objectService->method('findAll')->willReturnCallback(fn (): array => ['results' => $this->stored]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper->method('find')->willReturn($this->slugObject('integriq'));
		$schemaMapper->method('find')->willReturn($this->slugObject($schemaSlug));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new DsoActivityMappingGuardListener(
			table: new DsoActivityTable($objectService),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end listener()

	/**
	 * A slug-bearing value standing in for a Register or Schema.
	 *
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private function slugObject(string $slug): object {
		return new class($slug) {
			/**
			 * @param string $slug The slug.
			 */
			public function __construct(private string $slug) {
			}

			/**
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}//end slugObject()

	/**
	 * A row entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject(
			$data + ['activityName' => 'Demo', 'caseTypes' => [['reference' => 'DEMO']], 'samenloopStrategy' => 'deelzaken']
		);

		return $entity;
	}//end entity()

	/**
	 * A row with neither imowId nor activityId is refused.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdentifierIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity('new', []));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('dso_activity_identifier_missing', $event->getErrors()['code']);
		$this->assertSame(400, $event->getErrors()['status']);
	}//end testARowWithoutAnIdentifierIsRefused()

	/**
	 * A second active row with the same imowId is refused, on create and on update.
	 *
	 * @return void
	 */
	public function testASecondActiveRowWithTheSameImowIdIsRefused(): void {
		$this->stored = [$this->entity('first', ['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen'])];

		$create = new ObjectCreatingEvent($this->entity('second', ['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen']));
		$this->listener()->handle($create);
		$this->assertTrue($create->isPropagationStopped());
		$this->assertSame('dso_activity_imow_id_taken', $create->getErrors()['code']);
		$this->assertSame(409, $create->getErrors()['status']);

		$this->stored[] = $this->entity('other', ['imowId' => 'nl.imow-gm0000.activiteit.DemoKappen']);
		$old = $this->entity('other', ['imowId' => 'nl.imow-gm0000.activiteit.DemoKappen']);
		$update = new ObjectUpdatingEvent($this->entity('other', ['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen']), $old);
		$this->listener()->handle($update);
		$this->assertTrue($update->isPropagationStopped());
	}//end testASecondActiveRowWithTheSameImowIdIsRefused()

	/**
	 * Saving a row again, an inactive duplicate, a duplicate of an inactive
	 * row, an activityId-only row, and another schema's object all pass.
	 *
	 * @return void
	 */
	public function testWhatIsNotADuplicatePasses(): void {
		$this->stored = [
			$this->entity('first', ['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen']),
			$this->entity('old', ['imowId' => 'nl.imow-gm0000.activiteit.DemoKappen', 'isActive' => false]),
		];
		$cases = [
			'the same row again' => [$this->listener(), $this->entity('first', ['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen'])],
			'an inactive duplicate' => [$this->listener(), $this->entity('second', ['imowId' => 'nl.imow-gm0000.activiteit.DemoBouwen', 'isActive' => false])],
			'a duplicate of an inactive row' => [$this->listener(), $this->entity('third', ['imowId' => 'nl.imow-gm0000.activiteit.DemoKappen'])],
			'an activityId-only row' => [$this->listener(), $this->entity('fourth', ['activityId' => 'Demo-0000-Uitrit'])],
			'another schema' => [$this->listener(schemaSlug: 'rule'), $this->entity('rule', [])],
		];

		foreach ($cases as $name => [$listener, $entity]) {
			$event = new ObjectCreatingEvent($entity);
			$listener->handle($event);
			$this->assertFalse($event->isPropagationStopped(), $name);
		}
	}//end testWhatIsNotADuplicatePasses()
}//end class
