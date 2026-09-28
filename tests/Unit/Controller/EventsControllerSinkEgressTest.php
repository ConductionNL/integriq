<?php

/**
 * The subscription endpoints refuse a sink the egress guard refuses.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/events-async-api-products/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EventsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integriq#2212: `subscribe()` and `updateSubscription()` saved the sink as
 * given, so a sink on the metadata address was stored and later posted to.
 * They now refuse it with a 400 before anything is saved.
 *
 * @spec openspec/changes/events-async-api-products/design.md
 */
class EventsControllerSinkEgressTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * @var OrObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * The controller under test.
	 *
	 * @var EventsController
	 */
	private EventsController $controller;

	/**
	 * Set up the controller with every action granted and a DNS-free guard.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->orObjectService = $this->createMock(OrObjectService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []) => vsprintf($text, $parameters));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$guard = new class extends EgressGuard {
			/**
			 * Public hosts resolve to a public address; literals to themselves.
			 *
			 * @param string $host The host.
			 *
			 * @return array<int, string>
			 */
			protected function addressesOf(string $host): array {
				if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
					return [$host];
				}

				return ['93.184.216.34'];
			}
		};

		$this->controller = new EventsController(
			'integriq',
			$this->request,
			$this->orObjectService,
			$this->createMock(EventService::class),
			$l10n,
			$userSession,
			$this->createMock(ActionAuthService::class),
			$this->createMock(WebhookSignatureService::class),
			$guard
		);
	}//end setUp()

	/**
	 * A metadata sink is refused on create, and nothing is saved.
	 *
	 * @return void
	 */
	public function testSubscribeRefusesAMetadataSink(): void {
		$this->request->method('getParams')->willReturn(
			['types' => ['com.example.thing.created'], 'style' => 'push', 'sink' => 'http://169.254.169.254/latest/meta-data/']
		);
		$this->orObjectService->expects($this->never())->method('saveObject');

		$response = $this->controller->subscribe();

		$this->assertSame(400, $response->getStatus());
		$this->assertStringContainsString('169.254.169.254', (string)$response->getData()['error']);
	}//end testSubscribeRefusesAMetadataSink()

	/**
	 * A private sink is refused on update, and nothing is saved.
	 *
	 * @return void
	 */
	public function testUpdateSubscriptionRefusesAPrivateSink(): void {
		$this->request->method('getParams')->willReturn(['sink' => 'https://10.0.0.5/hook']);
		$this->orObjectService->expects($this->never())->method('saveObject');

		$response = $this->controller->updateSubscription('sub-uuid-1');

		$this->assertSame(400, $response->getStatus());
	}//end testUpdateSubscriptionRefusesAPrivateSink()

	/**
	 * A public https sink is saved as before.
	 *
	 * @return void
	 */
	public function testSubscribeKeepsAPublicHttpsSink(): void {
		$body = ['types' => ['com.example.thing.created'], 'style' => 'push', 'sink' => 'https://receiver.example.org/hook'];
		$this->request->method('getParams')->willReturn($body);
		$this->orObjectService->expects($this->once())->method('saveObject')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, $body, 'sub-uuid-1'));

		$response = $this->controller->subscribe();

		$this->assertSame(200, $response->getStatus());
	}//end testSubscribeKeepsAPublicHttpsSink()

	/**
	 * A pull subscription carries no sink and is not judged.
	 *
	 * @return void
	 */
	public function testSubscribeWithoutASinkIsNotJudged(): void {
		$body = ['types' => ['com.example.thing.created'], 'style' => 'pull'];
		$this->request->method('getParams')->willReturn($body);
		$this->orObjectService->expects($this->once())->method('saveObject')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, $body, 'sub-uuid-1'));

		$this->assertSame(200, $this->controller->subscribe()->getStatus());
	}//end testSubscribeWithoutASinkIsNotJudged()
}//end class
