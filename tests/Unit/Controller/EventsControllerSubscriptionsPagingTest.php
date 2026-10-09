<?php

/**
 * Paging parameters of GET /api/events/subscriptions.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/events-cloudevents/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\EventsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `limit` and `offset` page the list; they are not properties to filter on.
 *
 * Every other query parameter is a property filter, so a `limit` left in the
 * filters asked OpenRegister for subscriptions whose `limit` property is
 * "500". None has one, and a paged read came back empty.
 *
 * @spec openspec/specs/events-cloudevents/spec.md
 */
class EventsControllerSubscriptionsPagingTest extends TestCase {

	/**
	 * Paging parameters page the read and stay out of the filters.
	 *
	 * @return void
	 */
	public function testPagingParametersAreNotPropertyFilters(): void {
		$params = ['limit' => '500', 'offset' => '20', 'status' => 'active', '_route' => 'x'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$config = null;
		$orObjectService = $this->createMock(OrObjectService::class);
		$orObjectService->expects($this->once())
			->method('findAll')
			->willReturnCallback(
				function (array $given) use (&$config): array {
					$config = $given;
					return ['results' => []];
				}
			);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = new EventsController(
			'integriq',
			$request,
			$orObjectService,
			$this->createMock(EventService::class),
			$l10n,
			$userSession,
			$this->createMock(ActionAuthService::class),
			$this->createMock(WebhookSignatureService::class)
		);

		$controller->subscriptions();

		$this->assertSame(
			['register' => 'integriq', 'schema' => 'event_subscription', 'status' => 'active'],
			$config['filters']
		);
		$this->assertSame(500, $config['limit']);
		$this->assertSame(20, $config['offset']);
	}//end testPagingParametersAreNotPropertyFilters()
}//end class
