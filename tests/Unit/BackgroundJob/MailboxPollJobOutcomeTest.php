<?php

/**
 * MailboxPollJob logs its sweep summary, and a failed sweep as an error instead of crashing cron.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\MailboxPollJob;
use OCA\Integriq\Service\Mail\MailboxSourceHandlerInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\MailboxPollJob
 */
class MailboxPollJobOutcomeTest extends TestCase {

	public function testTheSweepSummaryIsLoggedAsItsContext(): void {
		$handler = $this->createMock(MailboxSourceHandlerInterface::class);
		$handler->expects($this->once())->method('pollAll')->willReturn(['sources' => 2, 'messages' => 5]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('MailboxPollJob: mailbox sweep complete', ['sources' => 2, 'messages' => 5]);
		$logger->expects($this->never())->method('error');

		$job = new MailboxPollJob($this->createMock(ITimeFactory::class), $handler, $logger);
		$job->run(null);

		$this->assertSame(300, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testAFailedSweepIsLoggedAsAnError(): void {
		$failure = new RuntimeException('IMAP login refused');
		$handler = $this->createMock(MailboxSourceHandlerInterface::class);
		$handler->method('pollAll')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())->method('error')
			->with('MailboxPollJob: mailbox sweep failed: IMAP login refused', ['exception' => $failure]);

		(new MailboxPollJob($this->createMock(ITimeFactory::class), $handler, $logger))->run(null);
	}
}
