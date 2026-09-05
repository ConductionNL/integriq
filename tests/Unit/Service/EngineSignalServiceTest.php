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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EngineSignalService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
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

		$service = new EngineSignalService(logger: $logger);
		$delivered = $service->deliver(
			data: ['engineRunUuid' => 'run-1', 'signalNodeId' => 'approve-1'],
			decision: 'approved',
			user: $user,
			comment: 'fine'
		);

		$this->assertFalse($delivered);

	}//end testDeliverWithoutSignalServiceReturnsFalseAndLogs()
}//end class
