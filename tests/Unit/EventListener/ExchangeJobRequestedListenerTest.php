<?php

/**
 * The two exchange request listeners never throw into the sender.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\Integriq\EventListener\ExchangeJobRequestedListener;
use OCA\Integriq\EventListener\ExchangeMappingRequestedListener;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * REQ-001 and REQ-002 wiring.
 */
class ExchangeJobRequestedListenerTest extends TestCase {

	/**
	 * A store failure becomes a named refusal.
	 *
	 * @return void
	 */
	public function testAStoreFailureBecomesARefusal(): void {
		$jobs = $this->createMock(ExchangeJobService::class);
		$jobs->method('handleRequest')->willThrowException(new RuntimeException('database gone'));
		$listener = new ExchangeJobRequestedListener($jobs, $this->createMock(ExchangeRejectionService::class), $this->createMock(LoggerInterface::class));
		$event = new ExchangeJobRequestedEvent('learniq', 'bron-rod', 'export');

		$listener->handle($event);

		$this->assertSame('store-failed', $event->getRefusal()['code']);
		$this->assertTrue($event->isHandled());

	}//end testAStoreFailureBecomesARefusal()

	/**
	 * A migrated job's rejections are written once each, only when it was created now.
	 *
	 * @return void
	 */
	public function testAMigratedJobsRejectionsAreWritten(): void {
		$created = new ObjectEntity();
		$created->setUuid('job-new');
		$jobs = $this->createMock(ExchangeJobService::class);
		$jobs->method('handleRequest')->willReturn($created);
		$rejections = $this->createMock(ExchangeRejectionService::class);
		$rejections->expects($this->exactly(2))->method('migrate')->with($created, $this->isType('array'));
		$listener = new ExchangeJobRequestedListener($jobs, $rejections, $this->createMock(LoggerInterface::class));

		$listener->handle(
			new ExchangeJobRequestedEvent(
				ownerApp: 'learniq',
				target: 'bron-rod',
				direction: 'export',
				history: ['legacyId' => 'l-1', 'status' => 'partial', 'rejections' => [['errorCode' => 'BRON-101'], ['errorCode' => 'BRON-102'], 'junk']]
			)
		);

	}//end testAMigratedJobsRejectionsAreWritten()

	/**
	 * Other events are ignored; a mapping store failure becomes a refusal.
	 *
	 * @return void
	 */
	public function testTheMappingListener(): void {
		$jobs = $this->createMock(ExchangeJobService::class);
		$jobs->expects($this->once())->method('handleMappingRequest')->willThrowException(new RuntimeException('nope'));
		$listener = new ExchangeMappingRequestedListener($jobs, $this->createMock(LoggerInterface::class));
		$event = new ExchangeMappingRequestedEvent('learniq', 'learniq-x', 'x', 'x', ['a' => 'b']);

		$listener->handle(new Event());
		$listener->handle($event);

		$this->assertSame('store-failed', $event->getRefusal()['code']);

	}//end testTheMappingListener()
}//end class
