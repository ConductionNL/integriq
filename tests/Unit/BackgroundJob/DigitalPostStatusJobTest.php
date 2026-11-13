<?php

/**
 * DigitalPostStatusJob polls only sent messages, as the service account, and a refused account polls nothing.
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

use OCA\Integriq\BackgroundJob\DigitalPostStatusJob;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccountInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\DigitalPostStatusJob
 */
class DigitalPostStatusJobTest extends TestCase {

	/**
	 * An account that runs the operation as granted.
	 *
	 * @return DigitalPostAccountInterface The account.
	 */
	private function grantingAccount(): DigitalPostAccountInterface {
		$account = $this->createMock(DigitalPostAccountInterface::class);
		$account->expects($this->once())->method('runOrRefuse')->with('status poll')->willReturnCallback(
			static function (string $what, callable $operation, callable $refuse): void {
				$operation();
			}
		);
		return $account;
	}

	/**
	 * The stored messages, as OpenRegister would return them.
	 *
	 * @return OrObjectService The object service.
	 */
	private function objectsWithMessages(): OrObjectService {
		$entity = new ObjectEntity();
		$entity->setObject(['id' => 'm-1', 'status' => 'sent']);
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->with(
			['filters' => ['register' => 'integriq', 'schema' => 'digitalPostMessage']]
		)->willReturn(
			[
				'results' => [
					$entity,
					['id' => 'm-2', 'status' => 'sent'],
					['id' => 'm-3', 'status' => 'delivered'],
					['id' => 'm-4'],
					'junk',
				],
			]
		);
		return $objects;
	}

	public function testOnlySentMessagesArePolledAndAChangeIsLogged(): void {
		$service = $this->createMock(DigitalPostServiceInterface::class);
		$service->expects($this->once())->method('pollStatuses')
			->with([['id' => 'm-1', 'status' => 'sent'], ['id' => 'm-2', 'status' => 'sent']])->willReturn(2);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('digital-post.status.changed', ['count' => 2]);

		$job = new DigitalPostStatusJob($this->createMock(ITimeFactory::class), $service, $this->objectsWithMessages(), $logger, $this->grantingAccount());
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(600, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testNoChangeLogsNothing(): void {
		$service = $this->createMock(DigitalPostServiceInterface::class);
		$service->expects($this->once())->method('pollStatuses')->willReturn(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		(new DigitalPostStatusJob($this->createMock(ITimeFactory::class), $service, $this->objectsWithMessages(), $logger, $this->grantingAccount()))->poll();
	}

	public function testNoOpenMessagePollsNothing(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn([['id' => 'm-3', 'status' => 'failed']]);
		$service = $this->createMock(DigitalPostServiceInterface::class);
		$service->expects($this->never())->method('pollStatuses');

		(new DigitalPostStatusJob($this->createMock(ITimeFactory::class), $service, $objects, $this->createMock(LoggerInterface::class), $this->grantingAccount()))->poll();
	}

	public function testAnUnreadableRegisterIsWarnedAndGivesNoOpenMessages(): void {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('digital-post.status.read-failed', ['error' => 'register down']);

		$job = new DigitalPostStatusJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(DigitalPostServiceInterface::class),
			$objects,
			$logger,
			$this->createMock(DigitalPostAccountInterface::class)
		);

		$this->assertSame([], $job->openMessages());
	}

	public function testARefusedAccountPollsNothing(): void {
		$account = $this->createMock(DigitalPostAccountInterface::class);
		$account->expects($this->once())->method('runOrRefuse')->willReturnCallback(
			static function (string $what, callable $operation, callable $refuse): void {
				$refuse('no service account configured');
			}
		);
		$objects = $this->createMock(OrObjectService::class);
		$objects->expects($this->never())->method('findAll');
		$service = $this->createMock(DigitalPostServiceInterface::class);
		$service->expects($this->never())->method('pollStatuses');

		(new DigitalPostStatusJob($this->createMock(ITimeFactory::class), $service, $objects, $this->createMock(LoggerInterface::class), $account))->poll();
	}
}
