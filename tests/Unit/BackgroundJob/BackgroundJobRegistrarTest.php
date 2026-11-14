<?php

/**
 * Tests for the BackgroundJob component's contract bindings.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\AttachmentFetchJobScheduler;
use OCA\Integriq\BackgroundJob\AttachmentFetchSchedulerInterface;
use OCA\Integriq\BackgroundJob\BackgroundJobRegistrar;
use OCA\Integriq\BackgroundJob\EventFanOutSchedulerInterface;
use OCA\Integriq\BackgroundJob\MessageRetryJobScheduler;
use OCA\Integriq\BackgroundJob\MessageRetrySchedulerInterface;
use OCA\Integriq\BackgroundJob\OtelExportJobScheduler;
use OCA\Integriq\BackgroundJob\ProcessEventJobScheduler;
use OCA\Integriq\BackgroundJob\TraceExportSchedulerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The registrar binds every job scheduling contract to its scheduler.
 *
 * @covers \OCA\Integriq\BackgroundJob\BackgroundJobRegistrar
 */
class BackgroundJobRegistrarTest extends TestCase {
	private const EXPECTED = [
		EventFanOutSchedulerInterface::class => ProcessEventJobScheduler::class,
		MessageRetrySchedulerInterface::class => MessageRetryJobScheduler::class,
		TraceExportSchedulerInterface::class => OtelExportJobScheduler::class,
		AttachmentFetchSchedulerInterface::class => AttachmentFetchJobScheduler::class,
	];

	/**
	 * Each contract is aliased to its scheduler, in this order, and nothing else is registered.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testRegistersEachSchedulerAlias(): void {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->exactly(count: count(self::EXPECTED)))->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->expects($this->never())->method('registerService');

		(new BackgroundJobRegistrar())->register(context: $context);

		$this->assertSame(expected: self::EXPECTED, actual: $aliases);
	}

	/**
	 * Every bound scheduler implements its contract, so the alias resolves to a valid instance.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testEachSchedulerImplementsItsContract(): void {
		foreach (self::EXPECTED as $contract => $implementation) {
			$this->assertTrue(condition: interface_exists($contract), message: $contract);
			$this->assertContains(needle: $contract, haystack: class_implements($implementation), message: $implementation);
		}
	}
}
