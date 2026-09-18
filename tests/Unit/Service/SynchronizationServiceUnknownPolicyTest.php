<?php

/**
 * A synchronisation carrying a policy the engine does not know deletes nothing.
 *
 * 🔴 THE MOST EXPENSIVE FAILURE THIS CHANGE CAN HAVE IS ALSO THE QUIETEST.
 * `deleteInvalidObjects()` removes every object the source stopped carrying.
 * If a misspelled `disappearancePolicy` were read as the default, a
 * synchronisation whose author wrote `markEnded ` with a trailing space, or
 * `mark-ended`, would DELETE the records they were trying to preserve, and the
 * run would report a perfectly ordinary deletion count.
 *
 * 🔑 THE ENGINE ALREADY REFUSES, AND NOTHING ASSERTED IT. `DisappearancePolicy`
 * is well covered as a value object, and the refusal it throws is covered there.
 * What was not covered is that the ENGINE catches that refusal, skips the whole
 * deletion step, and says why. A value object that throws into a caller which
 * swallows the throw is the same as no refusal at all, and nothing here would
 * have gone red.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Ownership\DisappearancePolicy;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The engine's refusal of a policy it does not know.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService::deleteInvalidObjects
 */
class SynchronizationServiceUnknownPolicyTest extends TestCase {

	/**
	 * The synchronisation's id.
	 */
	private const SYNC_ID = 'sync-unknown-policy';

	/**
	 * The service under test.
	 *
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * The dispatcher, so the guarded event can be asserted.
	 *
	 * @var IEventDispatcher
	 */
	private $eventDispatcher;

	/**
	 * The OpenRegister object service double.
	 *
	 * @var mixed
	 */
	private $orObjectService;

	/**
	 * Build the service with the same doubles the sibling guard tests use.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);

		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === IEventDispatcher::class) ? $this->eventDispatcher : null
		);

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
					$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
				]
			)
			->onlyMethods(['updateTarget'])
			->getMock();
	}//end setUp()

	/**
	 * A synchronisation payload carrying the given policy.
	 *
	 * @param mixed $policy What the author wrote.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function syncWithPolicy(mixed $policy): array {
		return [
			'id' => self::SYNC_ID,
			'uuid' => self::SYNC_ID,
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'sourceConfig' => [DisappearancePolicy::CONFIG_KEY => $policy],
		];
	}//end syncWithPolicy()

	/**
	 * 🔴 NOTHING IS DELETED UNDER A POLICY THE ENGINE DOES NOT KNOW.
	 *
	 * The count is the assertion that matters: a misspelled policy read as the
	 * default would delete every object the source stopped carrying, and report
	 * an ordinary deletion count while doing it.
	 *
	 * @return void
	 */
	public function testAMisspelledPolicyDeletesNothing(): void {
		$guardInfo = null;

		$deleted = $this->service->deleteInvalidObjects(
			synchronization: $this->syncWithPolicy('mark-ended'),
			synchronizedTargetIds: [],
			deleteRestriction: false,
			data: [],
			fetchComplete: true,
			forceDeletion: false,
			guardInfo: $guardInfo
		);

		$this->assertSame(0, $deleted, 'A policy the engine cannot read must not delete anything.');
	}//end testAMisspelledPolicyDeletesNothing()

	/**
	 * The run says WHY it deleted nothing, and names the key.
	 *
	 * "Deleted 0" on its own is indistinguishable from a source that dropped
	 * nothing, which is the reading that would let a broken synchronisation sit
	 * for months looking healthy.
	 *
	 * @return void
	 */
	public function testTheRunSaysWhyItSkipped(): void {
		$guardInfo = null;

		$this->service->deleteInvalidObjects(
			synchronization: $this->syncWithPolicy('mark-ended'),
			synchronizedTargetIds: [],
			fetchComplete: true,
			guardInfo: $guardInfo
		);

		$this->assertIsArray($guardInfo);
		$this->assertTrue($guardInfo['guarded']);
		$this->assertSame('unknown_disappearance_policy', $guardInfo['reason']);
		$this->assertStringContainsString(
			DisappearancePolicy::CONFIG_KEY,
			(string)$guardInfo['message'],
			'The message must name the key, or nobody knows which field to fix.'
		);
	}//end testTheRunSaysWhyItSkipped()

	/**
	 * 🔑 THE GUARDED EVENT IS DISPATCHED, so somebody outside the run can notice.
	 *
	 * A log line is read by whoever goes looking. The event is what lets an
	 * alert exist at all.
	 *
	 * @return void
	 */
	public function testTheGuardedEventIsDispatched(): void {
		$this->eventDispatcher->expects($this->atLeastOnce())->method('dispatchTyped');

		$guardInfo = null;
		$this->service->deleteInvalidObjects(
			synchronization: $this->syncWithPolicy('mark-ended'),
			synchronizedTargetIds: [],
			fetchComplete: true,
			guardInfo: $guardInfo
		);
	}//end testTheGuardedEventIsDispatched()

	/**
	 * A non-string declaration is refused the same way.
	 *
	 * A YAML or JSON edit can easily produce `true` or a list here, and reading
	 * that as the default would delete under a policy nobody wrote.
	 *
	 * @return void
	 */
	public function testANonStringPolicyIsRefusedToo(): void {
		$guardInfo = null;

		$deleted = $this->service->deleteInvalidObjects(
			synchronization: $this->syncWithPolicy(true),
			synchronizedTargetIds: [],
			fetchComplete: true,
			guardInfo: $guardInfo
		);

		$this->assertSame(0, $deleted);
		$this->assertSame('unknown_disappearance_policy', ($guardInfo['reason'] ?? null));
	}//end testANonStringPolicyIsRefusedToo()

	/**
	 * A policy the engine DOES know is not guarded on this reason.
	 *
	 * The control. Without it, an engine that guarded every run for any reason
	 * would pass every test above while deleting nothing, ever.
	 *
	 * @return void
	 */
	public function testAKnownPolicyIsNotGuardedForThisReason(): void {
		$guardInfo = null;

		$this->service->deleteInvalidObjects(
			synchronization: $this->syncWithPolicy(DisappearancePolicy::MARK_ENDED),
			synchronizedTargetIds: [],
			fetchComplete: true,
			guardInfo: $guardInfo
		);

		$this->assertNotSame(
			'unknown_disappearance_policy',
			($guardInfo['reason'] ?? null),
			'A policy the engine knows must not be refused as unknown.'
		);
	}//end testAKnownPolicyIsNotGuardedForThisReason()
}//end class
