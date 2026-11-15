<?php

/**
 * Unit tests for EventsController's unsubscribe, pull, signing-secret rotation and dead-letter detail.
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

use OCA\Integriq\Controller\EventsController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicyInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Removing and pulling a subscription, rotating its signing secret, and the detail of a dead-lettered message.
 *
 * @covers \OCA\Integriq\Controller\EventsController
 * @uses   \OCA\Integriq\Service\Security\SubscriptionSecretMasker
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
final class EventsControllerSubscriptionActionsTest extends TestCase {

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var EventServiceInterface&MockObject */
	private EventServiceInterface $events;

	/** @var ActionAuthServiceInterface&MockObject */
	private ActionAuthServiceInterface $actionAuth;

	/** @var SubscriptionSigningPolicyInterface&MockObject */
	private SubscriptionSigningPolicyInterface $signing;

	/** @var IUser|null */
	private ?IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects    = $this->createMock(OrObjectService::class);
		$this->events     = $this->createMock(EventServiceInterface::class);
		$this->actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$this->signing    = $this->createMock(SubscriptionSigningPolicyInterface::class);
		$this->user       = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The controller for one request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return EventsController
	 */
	private function controller(array $params = []): EventsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new EventsController(
			'integriq',
			$request,
			$this->objects,
			$this->events,
			$l,
			$session,
			$this->actionAuth,
			$this->signing,
			$this->createMock(EgressGuardInterface::class)
		);
	}//end controller()

	/**
	 * Unsubscribing and pulling refuse without a signed-in user.
	 *
	 * @return void
	 */
	public function testUnsubscribeAndPullRefuseWithoutAUser(): void {
		$this->user = null;
		$this->objects->expects($this->never())->method('find');
		$controller = $this->controller();

		$this->assertSame(401, $controller->unsubscribe('sub-1')->getStatus());
		$this->assertSame(401, $controller->pull('sub-1')->getStatus());
	}//end testUnsubscribeAndPullRefuseWithoutAUser()

	/**
	 * Unsubscribing deletes the found subscription.
	 *
	 * @return void
	 */
	public function testUnsubscribeDeletesTheSubscription(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'event.unsubscribe');
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'sub-1'));
		$this->objects->expects($this->once())->method('deleteObject')->with('sub-1');

		$response = $this->controller()->unsubscribe('sub-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData());
	}//end testUnsubscribeDeletesTheSubscription()

	/**
	 * Unsubscribing from an unknown subscription answers 404 and deletes nothing.
	 *
	 * @return void
	 */
	public function testUnsubscribeFromAnUnknownSubscriptionIs404(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->objects->expects($this->never())->method('deleteObject');

		$response = $this->controller()->unsubscribe('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Subscription not found'], $response->getData());
	}//end testUnsubscribeFromAnUnknownSubscriptionIs404()

	/**
	 * Pulling a pull subscription passes the limit and cursor and answers the events.
	 *
	 * @return void
	 */
	public function testPullAnswersTheEvents(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->user, 'event.pull');
		$subscription = ObjectServiceMockBuilder::objectEntity($this, ['style' => 'pull'], 'sub-1');
		$this->objects->method('find')->willReturn($subscription);
		$this->events->expects($this->once())->method('pullEvents')->with($subscription, 10, 'c-5')->willReturn(['events' => [], 'cursor' => 'c-6']);

		$response = $this->controller(['limit' => 10, 'cursor' => 'c-5'])->pull('sub-1');

		$this->assertSame(['events' => [], 'cursor' => 'c-6'], $response->getData());
	}//end testPullAnswersTheEvents()

	/**
	 * Pulling defaults to a hundred events from the start.
	 *
	 * @return void
	 */
	public function testPullDefaults(): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['style' => 'pull'], 'sub-1'));
		$this->events->expects($this->once())->method('pullEvents')->with($this->anything(), 100, null)->willReturn([]);

		$this->controller()->pull('sub-1');
	}//end testPullDefaults()

	/**
	 * A push subscription cannot be pulled, and an unknown one answers 404.
	 *
	 * @return void
	 */
	public function testPullRefusesAPushOrUnknownSubscription(): void {
		$this->events->expects($this->never())->method('pullEvents');
		$this->objects->method('find')->willReturnCallback(function (...$args) {
			if ($args[0] === 'push-1') {
				return ObjectServiceMockBuilder::objectEntity($this, ['style' => 'push'], 'push-1');
			}
			throw new DoesNotExistException('gone');
		});
		$controller = $this->controller();

		$push = $controller->pull('push-1');
		$this->assertSame(400, $push->getStatus());
		$this->assertSame(['error' => 'Subscription is not pull-based'], $push->getData());

		$this->assertSame(404, $controller->pull('nope')->getStatus());
	}//end testPullRefusesAPushOrUnknownSubscription()

	/**
	 * Rotating keeps the current secret as the previous one, stores the new one, and shows it once.
	 *
	 * @return void
	 */
	public function testRotateStoresANewSecretAndKeepsThePreviousOne(): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['sink' => 'https://x', 'protocolSettings' => ['signingSecret' => 'old', 'method' => 'POST']], 'sub-1'));
		$this->signing->method('generateSecret')->willReturn('new-secret');
		$saved = null;
		$this->objects->expects($this->once())->method('saveObject')
			->willReturnCallback(function (...$args) use (&$saved) {
				$saved = $args[0];
				$this->assertContains('sub-1', $args);
				return ObjectServiceMockBuilder::objectEntity($this, $args[0], 'sub-1');
			});

		$data = $this->controller()->rotateSigningSecret('sub-1')->getData();

		$this->assertSame('new-secret', $saved['protocolSettings']['signingSecret']);
		$this->assertSame('old', $saved['protocolSettings']['previousSigningSecret']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $saved['protocolSettings']['secretRotatedAt']);
		$this->assertSame('https://x', $saved['sink']);
		$this->assertSame('new-secret', $data['signingSecret']);
		$this->assertSame('**********', $data['subscription']['protocolSettings']['signingSecret']);
		$this->assertSame('**********', $data['subscription']['protocolSettings']['previousSigningSecret']);
		$this->assertSame('POST', $data['subscription']['protocolSettings']['method']);
	}//end testRotateStoresANewSecretAndKeepsThePreviousOne()

	/**
	 * Without a secret there is nothing to rotate; an unknown subscription answers 404.
	 *
	 * @return void
	 */
	public function testRotateRefusals(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$this->objects->method('find')->willReturnCallback(function (...$args) {
			return match ($args[0]) {
				'none'  => ObjectServiceMockBuilder::objectEntity($this, [], 'none'),
				'empty' => ObjectServiceMockBuilder::objectEntity($this, ['protocolSettings' => ['signingSecret' => '']], 'empty'),
				default => throw new DoesNotExistException('gone'),
			};
		});
		$controller = $this->controller();

		foreach (['none', 'empty'] as $id) {
			$response = $controller->rotateSigningSecret($id);
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'No signing secret to rotate; generate one first'], $response->getData());
		}

		$missing = $controller->rotateSigningSecret('nope');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Subscription not found'], $missing->getData());
	}//end testRotateRefusals()

	/**
	 * The dead-letter detail shows the subscription and the message's action kind and origin.
	 *
	 * @return void
	 */
	public function testDeadLetterShowIncludesTheSubscription(): void {
		$this->objects->method('find')->willReturnCallback(function (...$args) {
			if ($args[0] === 'msg-1') {
				return ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-1', 'payload' => ['source' => '/nextcloud/files']], 'msg-1');
			}
			return ObjectServiceMockBuilder::objectEntity($this, ['sink' => 'https://x', 'protocol' => 'http', 'status' => 'active', 'action' => ['kind' => 'flow']], 'sub-1');
		});

		$data = $this->controller()->deadLetterShow('msg-1')->getData();

		$this->assertSame(['sink' => 'https://x', 'protocol' => 'http', 'status' => 'active'], $data['subscription']);
		$this->assertSame('flow', $data['message']['actionKind']);
		$this->assertTrue($data['message']['nextcloudEvent']);
	}//end testDeadLetterShowIncludesTheSubscription()

	/**
	 * A message without a known subscription is a webhook with none shown.
	 *
	 * @return void
	 */
	public function testDeadLetterShowWithoutAKnownSubscription(): void {
		$this->objects->method('find')->willReturnCallback(function (...$args) {
			return match ($args[0]) {
				'msg-1' => ObjectServiceMockBuilder::objectEntity($this, ['subscription' => 'sub-gone', 'payload' => ['source' => 'https://elsewhere']], 'msg-1'),
				'msg-2' => ObjectServiceMockBuilder::objectEntity($this, [], 'msg-2'),
				default => throw new DoesNotExistException('gone'),
			};
		});
		$controller = $this->controller();

		$gone = $controller->deadLetterShow('msg-1')->getData();
		$this->assertNull($gone['subscription']);
		$this->assertSame('webhook', $gone['message']['actionKind']);
		$this->assertFalse($gone['message']['nextcloudEvent']);

		$bare = $controller->deadLetterShow('msg-2')->getData();
		$this->assertNull($bare['subscription']);
		$this->assertSame('webhook', $bare['message']['actionKind']);
	}//end testDeadLetterShowWithoutAKnownSubscription()

	/**
	 * The detail of an unknown message answers 404.
	 *
	 * @return void
	 */
	public function testDeadLetterShowOfAnUnknownMessageIs404(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller()->deadLetterShow('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Message not found'], $response->getData());
	}//end testDeadLetterShowOfAnUnknownMessageIs404()
}//end class
