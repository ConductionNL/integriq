<?php

/**
 * Cross-tenant refusal tests for the event, body-capture and product
 * subscription controllers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Integriq\Controller\BodyCaptureController;
use OCA\Integriq\Controller\EventsController;
use OCA\Integriq\Controller\ProductSubscriptionsController;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicy;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ForeignOrganisation;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * A user of organisation A asks for events, subscriptions, sources or API
 * product subscriptions of organisation B: every lookup answers 404 and
 * nothing is read out, signed, captured or approved.
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 */
class EventsAndLogsTenantScopingTest extends TestCase {

	/**
	 * @var OrObjectService&MockObject
	 */
	private $objects;

	/**
	 * @var IL10N&MockObject
	 */
	private $l;

	/**
	 * @var IUserSession&MockObject
	 */
	private $session;

	/**
	 * Set up an ObjectService that holds only another organisation's object.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$foreign = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'organisation-b', 'approvalRequestId' => 'ar-1'], 'uuid-b');
		$this->objects = $this->createMock(originalClassName: OrObjectService::class);
		$this->objects->method('find')->willReturnCallback(ForeignOrganisation::find($foreign));
		$this->objects->expects($this->never())->method('saveObject');
		$this->objects->expects($this->never())->method('deleteObject');

		$this->l = $this->createMock(originalClassName: IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Reading the messages of, unsubscribing from, pulling from, signing or
	 * opening a dead letter of another organisation's event or subscription
	 * answers 404.
	 *
	 * @return void
	 */
	public function testEventsOfAnotherOrganisationAreRefused(): void {
		$events = $this->createMock(originalClassName: EventService::class);
		$events->expects($this->never())->method($this->anything());

		$controller = new EventsController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			orObjectService: $this->objects,
			eventService: $events,
			l: $this->l,
			userSession: $this->session,
			actionAuth: $this->createMock(originalClassName: ActionAuthService::class),
			signingPolicy: new SubscriptionSigningPolicy(signatures: $this->createMock(originalClassName: WebhookSignatureService::class)),
			egressGuard: new EgressGuard(),
		);

		$this->assertSame(expected: 404, actual: $controller->messages(42)->getStatus());
		$this->assertSame(expected: 404, actual: $controller->unsubscribe('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->subscriptionMessages('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->pull('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->generateSigningSecret('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->rotateSigningSecret('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->deadLetterShow('uuid-b')->getStatus());
	}//end testEventsOfAnotherOrganisationAreRefused()

	/**
	 * Opening or closing a body-capture window on another organisation's
	 * source answers 404 and saves nothing.
	 *
	 * @return void
	 */
	public function testBodyCaptureOnASourceOfAnotherOrganisationIsRefused(): void {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (['hours' => '2', 'reason' => 'incident 7'][$key] ?? $default)
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$clock = $this->createMock(originalClassName: ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-13T12:00:00+00:00'));

		$controller = new BodyCaptureController(
			appName: 'integriq',
			request: $request,
			userSession: $this->session,
			groupManager: $groups,
			objectService: $this->objects,
			policy: new BodyCapturePolicy(appConfig: $appConfig, clock: $clock),
			l: $this->l
		);

		$this->assertSame(expected: 404, actual: $controller->open('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->close('uuid-b')->getStatus());
	}//end testBodyCaptureOnASourceOfAnotherOrganisationIsRefused()

	/**
	 * Subscribing to another organisation's API product, or approving or
	 * rejecting its subscription, answers 404 and decides nothing.
	 *
	 * @return void
	 */
	public function testProductSubscriptionsOfAnotherOrganisationAreRefused(): void {
		$approvals = $this->createMock(originalClassName: ApprovalService::class);
		$approvals->expects($this->never())->method('find');

		$controller = new ProductSubscriptionsController(
			appName: 'integriq',
			request: $this->createMock(originalClassName: IRequest::class),
			approvalService: $approvals,
			orObjectService: $this->objects,
			userSession: $this->session,
			l: $this->l,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->assertSame(expected: 404, actual: $controller->subscribe('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->approve('uuid-b')->getStatus());
		$this->assertSame(expected: 404, actual: $controller->reject('uuid-b')->getStatus());
	}//end testProductSubscriptionsOfAnotherOrganisationAreRefused()

}//end class
