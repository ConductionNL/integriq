<?php

/**
 * Unit tests for EngineSignalService.
 *
 * The bare test environment has no OpenRegister flow-signal service, which is
 * exactly the guarded case: delivery must answer false and log, never throw
 * and never pretend it delivered.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EngineSignalService;
use OCP\IUser;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\EngineSignalService
 */
class EngineSignalServiceTest extends TestCase {

	/**
	 * Without OpenRegister's signal service the delivery reports false and
	 * says why, so the approval node's heartbeat is visibly the fallback.
	 *
	 * @return void
	 */
	public function testDeliverWithoutSignalServiceReturnsFalseAndLogs(): void {
		if (class_exists('OCA\\OpenRegister\\Service\\Flow\\FlowRunSignalService') === true) {
			$this->markTestSkipped('A real FlowRunSignalService is present; the guarded branch cannot be exercised here.');
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with(
				$this->stringContains('resumes on its next heartbeat'),
				$this->callback(static fn (array $ctx): bool => $ctx['engineRunUuid'] === 'run-1')
			);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$service = new EngineSignalService(
			logger: $logger,
			container: $this->createMock(ContainerInterface::class)
		);
		$delivered = $service->deliver(
			data: ['engineRunUuid' => 'run-1', 'signalNodeId' => 'approve-1'],
			decision: 'approved',
			user: $user,
			comment: 'fine'
		);

		$this->assertFalse($delivered);

	}//end testDeliverWithoutSignalServiceReturnsFalseAndLogs()

	/**
	 * With the signal service present, the decision reaches the run through
	 * `answerByUuid()` as one RunSignal: payload, deciding user and node.
	 *
	 * Runs in its own process because it declares OpenRegister's classes,
	 * which would otherwise hide the guarded branch from the test above.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testDeliverAnswersTheRunWithOneRunSignal(): void {
		require_once __DIR__ . '/Doubles/RecordingFlowRunSignalService.php';

		$signals = new \OCA\OpenRegister\Service\Flow\FlowRunSignalService();
		if (property_exists($signals, 'answered') === false) {
			$this->markTestSkipped('A real FlowRunSignalService is present; this test drives a recording double.');
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($signals);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$service = new EngineSignalService(
			logger: $this->createMock(LoggerInterface::class),
			container: $container
		);
		$delivered = $service->deliver(
			data: ['engineRunUuid' => 'run-1', 'signalNodeId' => 'approve-1'],
			decision: 'approved',
			user: $user,
			comment: 'fine'
		);

		$this->assertTrue($delivered);
		$this->assertCount(1, $signals->answered);
		[$runUuid, $signal] = $signals->answered[0];
		$this->assertSame('run-1', $runUuid);
		$this->assertSame(['decision' => 'approved', 'decidedBy' => 'alice', 'comment' => 'fine'], $signal->payload);
		$this->assertSame('alice', $signal->actorUid);
		$this->assertSame('approve-1', $signal->nodeId);

	}//end testDeliverAnswersTheRunWithOneRunSignal()
}//end class
