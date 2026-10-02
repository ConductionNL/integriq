<?php

/**
 * A record the source stopped carrying can be retired with declared values.
 *
 * `sourceConfig.disappearanceValues` names the values a non-deleting
 * disappearance policy writes onto the target, so a course marketplace set
 * archives a withdrawn learniq Course instead of deleting it, and nothing is
 * retired when the fetch was incomplete.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-withdrawn-course-is-retired-never-deleted-req-cmkt-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class SynchronizationServiceRetireValuesTest extends TestCase {
	private const SYNC_ID = 'sync-uuid-course-marketplace';

	/** @var SynchronizationService */
	private $service;

	/** @var array<int, array{object: array, schema: mixed, uuid: mixed}> */
	private array $saved = [];

	/** @var int */
	private int $updateTargetCalls = 0;

	protected function setUp(): void {
		parent::setUp();

		$contracts = [];
		$objects = [];
		foreach (['course-a', 'course-b'] as $i => $targetId) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['synchronizationId' => self::SYNC_ID, 'originId' => 'go1-' . $i, 'targetId' => $targetId],
				'contract-uuid-' . $i
			);
			$objects[$targetId] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['code' => 'GO1-' . $i, 'name' => 'Cursus ' . $i, 'lifecycle' => 'published'],
				$targetId
			);
		}

		$orObjectService = $this->getMockBuilder(OrObjectService::class)->disableOriginalConstructor()->getMock();
		$orObjectService->method('findAll')->willReturn(['results' => $contracts, 'total' => count($contracts)]);
		$orObjectService->method('find')->willReturnCallback(fn (...$args) => $objects[(string)$args[0]]);
		$orObjectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$this->saved[] = ['object' => $object];
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'saved');
			}
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === IEventDispatcher::class) ? $this->createMock(IEventDispatcher::class) : null
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$this->service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$callService,
					$this->createMock(MappingService::class),
					$container,
					$orObjectService,
					$this->createMock(ObjectService::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(\OCA\Integriq\Service\SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['updateTarget'])
			->getMock();

		$this->service->method('updateTarget')->willReturnCallback(
			function (array $synchronizationContract, ...$rest) {
				$this->updateTargetCalls++;
				return $synchronizationContract;
			}
		);
	}//end setUp()

	/**
	 * Run the disappearance step with course-b gone from the source.
	 *
	 * @param bool $fetchComplete Whether the fetch read every page.
	 *
	 * @return array|null The guard info the run reports.
	 */
	private function runWithCourseBWithdrawn(bool $fetchComplete): ?array {
		$guardInfo = null;
		$this->service->deleteInvalidObjects(
			synchronization: [
				'id' => self::SYNC_ID,
				'uuid' => self::SYNC_ID,
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'sourceConfig' => [
					'disappearancePolicy' => 'keepAndFlag',
					'disappearanceValues' => ['lifecycle' => 'archived'],
				],
			],
			synchronizedTargetIds: ['course-a'],
			fetchComplete: $fetchComplete,
			forceDeletion: true,
			guardInfo: $guardInfo
		);

		return $guardInfo;
	}//end runWithCourseBWithdrawn()

	/**
	 * The saves that wrote a learniq course, not a contract.
	 *
	 * @return array<int, array>
	 */
	private function courseSaves(): array {
		$courses = [];
		foreach ($this->saved as $save) {
			if (array_key_exists('code', $save['object']) === true) {
				$courses[] = $save['object'];
			}
		}

		return $courses;
	}//end courseSaves()

	/**
	 * GIVEN a course missing from a complete fetch WHEN the run finishes THEN
	 * the course is archived and not deleted.
	 *
	 * @return void
	 */
	public function testACourseMissingFromACompleteFetchIsArchivedNotDeleted(): void {
		$guardInfo = $this->runWithCourseBWithdrawn(fetchComplete: true);

		$courses = $this->courseSaves();
		$this->assertCount(1, $courses, 'Only the withdrawn course is written.');
		$this->assertSame('GO1-1', $courses[0]['code']);
		$this->assertSame('archived', $courses[0]['lifecycle']);
		$this->assertSame('Cursus 1', $courses[0]['name'], 'The course keeps its values.');
		$this->assertSame(0, $this->updateTargetCalls, 'Nothing is deleted.');
		$this->assertSame(1, $guardInfo['flaggedCount']);
	}//end testACourseMissingFromACompleteFetchIsArchivedNotDeleted()

	/**
	 * GIVEN an incomplete fetch WHEN the run finishes THEN nothing is retired.
	 *
	 * @return void
	 */
	public function testAnIncompleteFetchRetiresNothing(): void {
		$this->runWithCourseBWithdrawn(fetchComplete: false);

		$this->assertSame([], $this->courseSaves());
		$this->assertSame(0, $this->updateTargetCalls);
	}//end testAnIncompleteFetchRetiresNothing()
}//end class
