<?php

/**
 * The deletion path once the guard has LET IT THROUGH.
 *
 * `SynchronizationServiceDeletionRatioGuardTest` covers the guard: thresholds,
 * force, restriction, zero contracts, incomplete fetch. Every one of its cases
 * ends in ZERO deletions, so the loop that actually deletes — resolving each
 * candidate's contract, scope-checking its target, and calling updateTarget —
 * had no coverage at all.
 *
 * That matters because the loop is where deletion becomes irreversible, and
 * because its lookups were rebuilt: the originId map and the contract bodies
 * used to be materialised for EVERY contract the synchronization has ever had,
 * and are now built only for the candidates being deleted. Identical behaviour
 * is the claim; these are the tests that make it one.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class SynchronizationServiceDeletionPathTest extends TestCase {
	private const SYNC_ID = 'sync-uuid-delete-path';

	/** @var SynchronizationService */
	private $service;

	/** @var mixed */
	private $orObjectService;

	/** @var array<int, array{targetId: mixed, action: string}> */
	private array $updateTargetCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);

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
					$this->orObjectService,
					$this->createMock(ObjectService::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
				]
			)
			->onlyMethods(['updateTarget'])
			->getMock();

		// Record what the loop asked to delete, and hand back a contract array so
		// the persist branch is exercised rather than skipped.
		// SLOT 3 is `$action`. The signature is
		// (contract, &$targetObject, $action, $mutationType, $trace, $synchronization),
		// and PHPUnit pads a callback positionally with every declared default —
		// so naming the second parameter `$action` silently receives
		// `$targetObject` instead, and the type error blames the closure.
		$this->service->method('updateTarget')->willReturnCallback(
			function (array $synchronizationContract, ?array $targetObject = [], ?string $action = 'save', ...$rest) {
				$this->updateTargetCalls[] = [
					'targetId' => ($synchronizationContract['targetId'] ?? null),
					'action' => (string)$action,
				];

				return $synchronizationContract;
			}
		);

	}

	/**
	 * Seed the synchronization's contracts, and decide which targets still
	 * resolve in scope.
	 *
	 * @param array<int, string> $targetIds Contracts to seed, one per target.
	 * @param array<int, string>|null $inScope Targets the scope-check resolves;
	 *                                        null means all of them.
	 *
	 * @return void
	 */
	private function seed(array $targetIds, ?array $inScope = null): void {
		$contracts = [];
		foreach ($targetIds as $i => $targetId) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				[
					'synchronizationId' => self::SYNC_ID,
					'originId' => 'origin-' . $i,
					'targetId' => $targetId,
				],
				'contract-uuid-' . $i
			);
		}

		$this->orObjectService->method('findAll')->willReturn(['results' => $contracts, 'total' => count($contracts)]);

		$resolvable = ($inScope ?? $targetIds);
		$this->orObjectService->method('find')->willReturnCallback(
			function (...$args) use ($resolvable) {
				$id = (string)$args[0];
				if (in_array($id, $resolvable, true) === false) {
					// A target that lives in another register/schema, or was
					// removed out of band. Either way: not ours to delete.
					throw new DoesNotExistException('out of scope');
				}

				return ObjectServiceMockBuilder::objectEntity($this, [], $id);
			}
		);
	}

	/**
	 * @param array $synchronizedTargetIds Targets the source still has.
	 * @param array $sourceConfig Extra source configuration.
	 *
	 * @return int The number reported deleted.
	 */
	private function runDeletion(array $synchronizedTargetIds, array $sourceConfig = []): int {
		$method = new ReflectionMethod(SynchronizationService::class, 'deleteInvalidObjects');
		$method->setAccessible(true);

		$guardInfo = null;

		// Built as a variable so the trailing by-reference `$guardInfo` parameter
		// binds; an inline array literal cannot carry a reference.
		$args = [
			[
				'id' => self::SYNC_ID,
				'uuid' => self::SYNC_ID,
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'sourceConfig' => $sourceConfig,
			],
			$synchronizedTargetIds,
			false,
			[],
			true,
			true,
			&$guardInfo,
		];

		return $method->invokeArgs($this->service, $args);
	}

	/**
	 * The base case the guard tests never reach: a contract whose target has
	 * disappeared from the source IS deleted, and the survivors are not.
	 */
	public function testOnlyTargetsMissingFromTheSourceAreDeleted(): void {
		$this->seed(['target-1', 'target-2', 'target-3', 'target-4']);

		// The source still has 1, 2 and 4. Only target-3 is gone.
		$deleted = $this->runDeletion(['target-1', 'target-2', 'target-4']);

		$this->assertSame(1, $deleted);
		$this->assertCount(1, $this->updateTargetCalls);
		$this->assertSame('target-3', $this->updateTargetCalls[0]['targetId']);
		$this->assertSame('delete', $this->updateTargetCalls[0]['action']);
	}

	/**
	 * THE control that matters most on a delete path: a source that still has
	 * everything deletes NOTHING. An off-by-one in the diff shows up here as a
	 * silent data loss, which is not something to discover in production.
	 */
	public function testAnUnchangedSourceDeletesNothing(): void {
		$targets = ['target-1', 'target-2', 'target-3', 'target-4'];
		$this->seed($targets);

		$this->assertSame(0, $this->runDeletion($targets));
		$this->assertSame([], $this->updateTargetCalls);
	}

	/**
	 * A candidate whose target no longer resolves in THIS register/schema is
	 * skipped rather than deleted. The scope-check exists because a uuid can
	 * collide across magic tables (OR#1638 / hydra#309), and deleting on a
	 * collision removes a foreign object.
	 */
	public function testATargetOutsideThisScopeIsNotDeleted(): void {
		$this->seed(
			targetIds: ['target-1', 'target-2', 'target-3'],
			// target-2 does not resolve here — it belongs to something else.
			inScope: ['target-1', 'target-3']
		);

		// The source dropped BOTH target-2 and target-3.
		$deleted = $this->runDeletion(['target-1']);

		$this->assertSame(1, $deleted, 'Only the in-scope missing target is deleted');
		$this->assertCount(1, $this->updateTargetCalls);
		$this->assertSame('target-3', $this->updateTargetCalls[0]['targetId']);
	}

	/**
	 * Every missing target is deleted, not just the first — the loop must not
	 * stop early. Kept under the ratio guard's 10% by leaving most contracts
	 * present in the source.
	 */
	public function testEveryMissingTargetIsDeleted(): void {
		$targets = [];
		for ($i = 1; $i <= 40; $i++) {
			$targets[] = 'target-' . $i;
		}

		$this->seed($targets);

		// Drop 3 of 40 (7.5%, under the 10% guard).
		$stillPresent = array_values(array_diff($targets, ['target-11', 'target-22', 'target-33']));

		$this->assertSame(3, $this->runDeletion($stillPresent));

		$deletedIds = array_column($this->updateTargetCalls, 'targetId');
		sort($deletedIds);
		$this->assertSame(['target-11', 'target-22', 'target-33'], $deletedIds);
	}

	/**
	 * The lookups the delete loop needs are built for the CANDIDATES, not for
	 * every contract the synchronization has ever had — that materialised a full
	 * body per contract and was a large part of why a 19,822-object run stopped
	 * finishing. This pins the behaviour that must survive that change: the
	 * contract handed to updateTarget is the RIGHT one for its target.
	 */
	public function testTheDeletedContractIsTheOneMatchingItsTarget(): void {
		$this->seed(['target-1', 'target-2', 'target-3']);

		$this->runDeletion(['target-1', 'target-2']);

		$this->assertCount(1, $this->updateTargetCalls);
		$this->assertSame('target-3', $this->updateTargetCalls[0]['targetId']);
	}

	/**
	 * A synchronization with no contracts at all has nothing to diff against,
	 * and must not read an empty contract list as "the source deleted
	 * everything".
	 */
	public function testNoContractsDeletesNothing(): void {
		$this->seed([]);

		$this->assertSame(0, $this->runDeletion(['target-1', 'target-2']));
		$this->assertSame([], $this->updateTargetCalls);
	}
}
