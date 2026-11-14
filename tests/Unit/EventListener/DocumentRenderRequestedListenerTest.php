<?php

/**
 * DocumentRenderRequestedListener Unit Tests
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\DocumentRenderRequestedEvent;
use OCA\Integriq\EventListener\DocumentRenderRequestedListener;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A render request is taken as a job whose id is written back, or refused with a code that says why.
 *
 * @covers \OCA\Integriq\EventListener\DocumentRenderRequestedListener
 * @uses   \OCA\Integriq\Event\DocumentRenderRequestedEvent
 * @uses   \OCA\Integriq\Exception\DocumentGenerationException
 */
class DocumentRenderRequestedListenerTest extends TestCase {

	private DocumentGenerationServiceInterface&MockObject $service;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->service = $this->createMock(DocumentGenerationServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A render request from filinq.
	 *
	 * @return DocumentRenderRequestedEvent
	 */
	private function request(): DocumentRenderRequestedEvent {
		return new DocumentRenderRequestedEvent(
			sourceId: 'source-1',
			templateId: 'tpl-9',
			data: ['name' => 'Jan'],
			requestedBy: 'alice',
			requestedByApp: 'filinq'
		);
	}//end request()

	/**
	 * The listener under test.
	 *
	 * @return DocumentRenderRequestedListener
	 */
	private function listener(): DocumentRenderRequestedListener {
		return new DocumentRenderRequestedListener($this->service, $this->logger);
	}//end listener()

	public function testATakenRenderWritesTheJobIdBack(): void {
		$job = new ObjectEntity();
		$job->setUuid('job-42');
		$this->service->expects($this->once())
			->method('requestRender')
			->with('source-1', 'tpl-9', ['name' => 'Jan'], 'alice', 'filinq')
			->willReturn($job);
		$event = $this->request();

		$this->listener()->handle($event);

		$this->assertSame('job-42', $event->getJobId());
		$this->assertNull($event->getRefusal());
	}//end testATakenRenderWritesTheJobIdBack()

	public function testAnUnconfiguredSourceIsRefusedWithoutLogging(): void {
		$this->service->method('requestRender')->willThrowException(new DocumentGenerationException('No render source is configured.'));
		$this->logger->expects($this->never())->method('error');
		$event = $this->request();

		$this->listener()->handle($event);

		$this->assertSame(['code' => 'not-configured', 'reason' => 'No render source is configured.'], $event->getRefusal());
		$this->assertNull($event->getJobId());
	}//end testAnUnconfiguredSourceIsRefusedWithoutLogging()

	public function testAnyOtherFailureIsLoggedAndRefusedAsNotTaken(): void {
		$failure = new RuntimeException('database gone');
		$this->service->method('requestRender')->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[integriq] a document render could not be taken: database gone',
				['exception' => $failure, 'sourceId' => 'source-1']
			);
		$event = $this->request();

		$this->listener()->handle($event);

		$this->assertSame(['code' => 'render-not-taken', 'reason' => 'The render could not be taken: database gone'], $event->getRefusal());
		$this->assertNull($event->getJobId());
	}//end testAnyOtherFailureIsLoggedAndRefusedAsNotTaken()

	public function testOtherEventsAreIgnored(): void {
		$this->service->expects($this->never())->method('requestRender');

		$this->listener()->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
