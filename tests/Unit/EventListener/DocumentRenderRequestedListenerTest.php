<?php

/**
 * Unit tests for the listener that takes a document render request.
 *
 * A sibling app dispatches DocumentRenderRequestedEvent and reads the answer
 * off the same event: a job id when the render was taken, or a refusal with
 * a code it can act on. These tests pin what is handed to the render service
 * and which answer each outcome leaves on the event.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\DocumentRenderRequestedEvent;
use OCA\Integriq\EventListener\DocumentRenderRequestedListener;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Job id on success, a refusal code on each kind of failure.
 *
 * @covers \OCA\Integriq\EventListener\DocumentRenderRequestedListener
 * @covers \OCA\Integriq\Event\DocumentRenderRequestedEvent
 */
class DocumentRenderRequestedListenerTest extends TestCase {

	private DocumentGenerationServiceInterface&MockObject $service;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(DocumentGenerationServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A taken render hands the request on as given and leaves the job id, without a refusal.
	 *
	 * @return void
	 */
	public function testATakenRenderLeavesTheJobId(): void {
		$this->service->expects($this->once())
			->method('requestRender')
			->with('src-docs', 'tpl-besluit', ['naam' => 'Jan'], 'henk', 'dossiq')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'queued'], 'job-42'));
		$event = new DocumentRenderRequestedEvent('src-docs', 'tpl-besluit', ['naam' => 'Jan'], 'henk', 'dossiq');

		$this->listener()->handle($event);

		$this->assertSame('job-42', $event->getJobId());
		$this->assertNull($event->getRefusal());
	}//end testATakenRenderLeavesTheJobId()

	/**
	 * A request without a requester is passed on as coming from filinq, with no user.
	 *
	 * @return void
	 */
	public function testARequestWithoutARequesterComesFromFilinq(): void {
		$this->service->expects($this->once())
			->method('requestRender')
			->with('src-docs', 'tpl-1', [], '', 'filinq')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'job-1'));

		$this->listener()->handle(new DocumentRenderRequestedEvent('src-docs', 'tpl-1', []));
	}//end testARequestWithoutARequesterComesFromFilinq()

	/**
	 * A render service that is not configured refuses with `not-configured` and its own reason.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredServiceRefusesAsNotConfigured(): void {
		$this->service->method('requestRender')->willThrowException(new DocumentGenerationException('No render source "src-docs".'));
		$this->logger->expects($this->never())->method('error');
		$event = new DocumentRenderRequestedEvent('src-docs', 'tpl-1', []);

		$this->listener()->handle($event);

		$this->assertSame(['code' => 'not-configured', 'reason' => 'No render source "src-docs".'], $event->getRefusal());
		$this->assertNull($event->getJobId());
	}//end testAnUnconfiguredServiceRefusesAsNotConfigured()

	/**
	 * Any other failure is logged and refuses with `render-not-taken`.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsLoggedAndRefusedAsNotTaken(): void {
		$failure = new RuntimeException('register is read-only');
		$this->service->method('requestRender')->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[integriq] a document render could not be taken: register is read-only',
				['exception' => $failure, 'sourceId' => 'src-docs']
			);
		$event = new DocumentRenderRequestedEvent('src-docs', 'tpl-1', []);

		$this->listener()->handle($event);

		$this->assertSame(
			['code' => 'render-not-taken', 'reason' => 'The render could not be taken: register is read-only'],
			$event->getRefusal()
		);
		$this->assertNull($event->getJobId());
	}//end testAnyOtherFailureIsLoggedAndRefusedAsNotTaken()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->service->expects($this->never())->method('requestRender');

		$this->listener()->handle(new Event());
	}//end testAnotherEventIsIgnored()

	/**
	 * The listener under test.
	 *
	 * @return DocumentRenderRequestedListener
	 */
	private function listener(): DocumentRenderRequestedListener {
		return new DocumentRenderRequestedListener($this->service, $this->logger);
	}//end listener()
}//end class
