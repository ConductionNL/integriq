<?php

/**
 * StufZdsRetryJob retries one queued StUF-ZDS message by its id.
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

use OCA\Integriq\BackgroundJob\StufZdsRetryJob;
use OCA\Integriq\Service\StufZkn\StufZdsOutboundInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\StufZdsRetryJob
 */
class StufZdsRetryJobTest extends TestCase {

	private function runJob(StufZdsOutboundInterface $outbound, LoggerInterface $logger, mixed $argument): void {
		$job = new StufZdsRetryJob($this->createMock(ITimeFactory::class), $outbound, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}

	public function testTheMessageNamedInTheArgumentIsRetried(): void {
		$outbound = $this->createMock(StufZdsOutboundInterface::class);
		$outbound->expects($this->once())->method('retry')->with('msg-42');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->runJob($outbound, $logger, ['messageId' => 'msg-42']);
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function argumentsWithoutAMessage(): array {
		return [
			'null' => [null],
			'a bare string' => ['msg-42'],
			'no messageId' => [['other' => 'x']],
			'an empty messageId' => [['messageId' => '']],
		];
	}

	/**
	 * @dataProvider argumentsWithoutAMessage
	 */
	public function testAnArgumentWithoutAMessageIdRetriesNothing(mixed $argument): void {
		$outbound = $this->createMock(StufZdsOutboundInterface::class);
		$outbound->expects($this->never())->method('retry');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('StufZdsRetryJob: no messageId in the argument');

		$this->runJob($outbound, $logger, $argument);
	}

	public function testAFailedRetryIsLoggedWithTheMessageId(): void {
		$outbound = $this->createMock(StufZdsOutboundInterface::class);
		$outbound->method('retry')->willThrowException(new RuntimeException('ZDS endpoint down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('StufZdsRetryJob failed for {id}: {error}', ['id' => 'msg-7', 'error' => 'ZDS endpoint down']);

		$this->runJob($outbound, $logger, ['messageId' => 'msg-7']);
	}
}
