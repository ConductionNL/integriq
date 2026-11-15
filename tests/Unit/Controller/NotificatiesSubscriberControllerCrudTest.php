<?php

/**
 * Unit tests for NotificatiesSubscriberController's abonnement CRUD and callback failures.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\NotificatiesSubscriberController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use OCA\Integriq\Service\NotificatiesSubscriberServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Listing, creating, updating and deleting abonnementen, and a callback whose processing fails.
 *
 * @covers \OCA\Integriq\Controller\NotificatiesSubscriberController
 */
final class NotificatiesSubscriberControllerCrudTest extends TestCase {

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var NotificatiesSubscriberServiceInterface&MockObject */
	private NotificatiesSubscriberServiceInterface $subscribers;

	/** @var OpenRegisterCredentialBridgeInterface&MockObject */
	private OpenRegisterCredentialBridgeInterface $credentials;

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var ActionAuthServiceInterface&MockObject */
	private ActionAuthServiceInterface $actionAuth;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IUser|null */
	private ?IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request     = $this->createMock(IRequest::class);
		$this->subscribers = $this->createMock(NotificatiesSubscriberServiceInterface::class);
		$this->credentials = $this->createMock(OpenRegisterCredentialBridgeInterface::class);
		$this->objects     = $this->createMock(OrObjectService::class);
		$this->actionAuth  = $this->createMock(ActionAuthServiceInterface::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
		$this->user        = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The controller.
	 *
	 * @return NotificatiesSubscriberController
	 */
	private function controller(): NotificatiesSubscriberController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new NotificatiesSubscriberController(
			'integriq',
			$this->request,
			$this->subscribers,
			$this->credentials,
			$this->objects,
			$this->actionAuth,
			$session,
			$l,
			$this->logger
		);
	}//end controller()

	/**
	 * Every management action refuses without a signed-in user.
	 *
	 * @return void
	 */
	public function testManagementRefusesWithoutAUser(): void {
		$this->user = null;
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->subscribers->expects($this->never())->method($this->anything());
		$controller = $this->controller();

		foreach ([$controller->index(), $controller->create(), $controller->update('a-1'), $controller->destroy('a-1')] as $response) {
			$this->assertSame(401, $response->getStatus());
			$this->assertSame(['error' => 'Not authenticated'], $response->getData());
		}
	}//end testManagementRefusesWithoutAUser()

	/**
	 * The list pages the abonnementen with the request's limit and offset, and answers their data.
	 *
	 * @return void
	 */
	public function testIndexListsTheAbonnementenOfOnePage(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'notificaties.list');
		$this->request->method('getParam')->willReturnMap([['limit', 50, '10'], ['offset', 0, '20']]);
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'notificaties_abonnement'], 'limit' => 10, 'offset' => 20])
			->willReturn(['results' => [ObjectServiceMockBuilder::objectEntity($this, ['channel' => 'zaken'])]]);

		$response = $this->controller()->index();

		$this->assertSame(['results' => [['channel' => 'zaken']]], $response->getData());
	}//end testIndexListsTheAbonnementenOfOnePage()

	/**
	 * A plain list answer is read as the abonnementen.
	 *
	 * @return void
	 */
	public function testIndexReadsAPlainList(): void {
		$this->request->method('getParam')->willReturnArgument(1);
		$this->objects->method('findAll')->willReturn([ObjectServiceMockBuilder::objectEntity($this, ['channel' => 'besluiten'])]);

		$this->assertSame(['results' => [['channel' => 'besluiten']]], $this->controller()->index()->getData());
	}//end testIndexReadsAPlainList()

	/**
	 * Creating passes the request without its internal parameters and answers the new abonnement.
	 *
	 * @return void
	 */
	public function testCreateDropsInternalParameters(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'notificaties.create');
		$this->request->method('getParams')->willReturn(['_route' => 'x', 'channel' => 'zaken', '_method' => 'POST']);
		$this->subscribers->expects($this->once())->method('createAbonnement')
			->with(['channel' => 'zaken'])
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['channel' => 'zaken', 'id' => 'a-1']));

		$response = $this->controller()->create();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['channel' => 'zaken', 'id' => 'a-1'], $response->getData());
	}//end testCreateDropsInternalParameters()

	/**
	 * A refused creation answers 400 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedCreateIs400(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->subscribers->method('createAbonnement')->willThrowException(new InvalidArgumentException('channel is required'));

		$response = $this->controller()->create();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'channel is required'], $response->getData());
	}//end testARefusedCreateIs400()

	/**
	 * Updating passes the id and the request without internal parameters.
	 *
	 * @return void
	 */
	public function testUpdatePassesTheIdAndCleanData(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'notificaties.update');
		$this->request->method('getParams')->willReturn(['_route' => 'x', 'filters' => ['bronorganisatie' => '1']]);
		$this->subscribers->expects($this->once())->method('updateAbonnement')
			->with('a-1', ['filters' => ['bronorganisatie' => '1']])
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['id' => 'a-1']));

		$this->assertSame(['id' => 'a-1'], $this->controller()->update('a-1')->getData());
	}//end testUpdatePassesTheIdAndCleanData()

	/**
	 * Updating an unknown abonnement answers 404; any other failure 400.
	 *
	 * @return void
	 */
	public function testUpdateFailures(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->subscribers->method('updateAbonnement')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('gone')),
			$this->throwException(new RuntimeException('bad filter'))
		);
		$controller = $this->controller();

		$missing = $controller->update('nope');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Abonnement not found'], $missing->getData());

		$bad = $controller->update('a-1');
		$this->assertSame(400, $bad->getStatus());
		$this->assertSame(['error' => 'bad filter'], $bad->getData());
	}//end testUpdateFailures()

	/**
	 * Deleting answers the removed abonnement, or 404 for an unknown one.
	 *
	 * @return void
	 */
	public function testDestroy(): void {
		$this->actionAuth->expects($this->exactly(2))->method('requireAction')->with($this->user, 'notificaties.delete');
		$this->subscribers->method('deleteAbonnement')->willReturnOnConsecutiveCalls(
			ObjectServiceMockBuilder::objectEntity($this, ['id' => 'a-1']),
			$this->throwException(new DoesNotExistException('gone'))
		);
		$controller = $this->controller();

		$this->assertSame(['id' => 'a-1'], $controller->destroy('a-1')->getData());
		$missing = $controller->destroy('nope');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Abonnement not found'], $missing->getData());
	}//end testDestroy()

	/**
	 * Make the callback authenticate: the abonnement's consumer presents a key.
	 *
	 * @return void
	 */
	private function authenticatedCallback(): void {
		$this->subscribers->method('findAbonnement')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['consumerId' => 'cons-1'], 'a-1')
		);
		$this->request->method('getHeader')->willReturn('secret-key');
		$this->request->method('getParams')->willReturn(['channel' => 'zaken']);
		$this->credentials->method('getResolvedConsumer')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, [], 'cons-1')
		);
	}//end authenticatedCallback()

	/**
	 * A notification the subscriber refuses answers 400 with the reason.
	 *
	 * @return void
	 */
	public function testARefusedNotificationIs400(): void {
		$this->authenticatedCallback();
		$this->subscribers->method('handleInboundNotification')->willThrowException(new InvalidArgumentException('unknown channel'));

		$response = $this->controller()->callback('a-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'unknown channel'], $response->getData());
	}//end testARefusedNotificationIs400()

	/**
	 * A notification whose processing fails is logged and answers 500 without the reason.
	 *
	 * @return void
	 */
	public function testAFailedNotificationIsLoggedAnd500(): void {
		$this->authenticatedCallback();
		$failure = new RuntimeException('db down');
		$this->subscribers->method('handleInboundNotification')->willThrowException($failure);
		$this->logger->expects($this->once())->method('error')->with(
			'[NotificatiesSubscriberController] inbound notification processing failed: db down',
			['exception' => $failure, 'abonnementId' => 'a-1']
		);

		$response = $this->controller()->callback('a-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'processing_failed'], $response->getData());
	}//end testAFailedNotificationIsLoggedAnd500()
}//end class
