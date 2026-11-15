<?php

/**
 * RegistrySubscriptionRequestedListener logging Unit Tests
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

use OCA\Integriq\EventListener\RegistrySubscriptionRequestedListener;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandlerInterface;
use OCA\Integriq\Service\Registry\SubscriptionResult;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A fulfilled request is logged as fulfilled, a failure as failed, and a declined one not at all.
 *
 * @covers \OCA\Integriq\EventListener\RegistrySubscriptionRequestedListener
 * @uses   \OCA\Integriq\Service\Registry\SubscriptionResult
 */
class RegistrySubscriptionRequestedListenerLoggingTest extends TestCase {

	private SubscriptionRequestHandlerInterface&MockObject $handler;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->handler = $this->createMock(SubscriptionRequestHandlerInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Handle one BRP subscription request for object-1.
	 *
	 * @return void
	 */
	private function handleRequest(): void {
		(new RegistrySubscriptionRequestedListener($this->handler, $this->logger))
			->handle(new RegistrySubscriptionRequestedEvent('object-1', 'register-1', 'schema-1', 'brp', '999999011'));
	}//end handleRequest()

	public function testAFulfilledRequestIsLoggedWithRegistryAndObject(): void {
		$this->handler->method('handle')->willReturn(SubscriptionResult::active(identity: '999999011', reference: 's-1'));
		$this->logger->expects($this->once())
			->method('info')
			->with('registry-subscription.request.fulfilled', ['registry' => 'brp', 'objectUuid' => 'object-1']);
		$this->logger->expects($this->never())->method('error');

		$this->handleRequest();
	}//end testAFulfilledRequestIsLoggedWithRegistryAndObject()

	public function testADeclinedRequestIsNotLoggedAgain(): void {
		$this->handler->method('handle')->willReturn(null);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->handleRequest();
	}//end testADeclinedRequestIsNotLoggedAgain()

	public function testAFailingHandlerIsLoggedAsFailed(): void {
		$this->handler->method('handle')->willThrowException(new RuntimeException('registry unreachable'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'registry-subscription.request.failed',
				['registry' => 'brp', 'objectUuid' => 'object-1', 'exception' => 'registry unreachable']
			);

		$this->handleRequest();
	}//end testAFailingHandlerIsLoggedAsFailed()
}//end class
