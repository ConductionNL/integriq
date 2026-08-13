<?php

/**
 * Unit tests for EventsController dead-letter endpoints.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\EventsController;
use OCA\OpenConnector\Exception\InvalidMessageStateException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dead-letter REST surface.
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-5
 */
class EventsControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var EventsController
	 */
	private EventsController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->orObjectService = $this->createMock(OrObjectService::class);
		$this->eventService = $this->createMock(EventService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->actionAuth = $this->createMock(ActionAuthService::class);

		$signatureService = $this->createMock(WebhookSignatureService::class);

		$this->controller = new EventsController(
			'openconnector',
			$this->request,
			$this->orObjectService,
			$this->eventService,
			$l,
			$this->userSession,
			$this->actionAuth,
			$signatureService
		);
	}//end setUp()

	/**
	 * REQ-DLR-001: the default listing returns only failed and abandoned rows.
	 *
	 * @return void
	 */
	public function testDeadLetterIndexDefaultsToFailedAndAbandoned(): void {
		$rows = [
			ObjectServiceMockBuilder::objectEntity($this, ['status' => 'failed'], 'm1'),
			ObjectServiceMockBuilder::objectEntity($this, ['status' => 'abandoned'], 'm2'),
			ObjectServiceMockBuilder::objectEntity($this, ['status' => 'discarded'], 'm3'),
		];
		$this->orObjectService->method('findAll')->willReturn(['results' => $rows, 'total' => 3]);
		$this->request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => $default
		);

		$response = $this->controller->deadLetterIndex();
		$data = $response->getData();

		$this->assertSame(2, $data['total']);
		$statuses = array_column($data['results'], 'status');
		$this->assertContains('failed', $statuses);
		$this->assertContains('abandoned', $statuses);
		$this->assertNotContains('discarded', $statuses);
	}//end testDeadLetterIndexDefaultsToFailedAndAbandoned()

	/**
	 * REQ-DLR-003: replay maps an invalid-state to HTTP 409.
	 *
	 * @return void
	 */
	public function testReplayReturns409OnInvalidState(): void {
		$this->eventService->method('replayMessage')
			->willThrowException(new InvalidMessageStateException('bad state'));

		$response = $this->controller->replay('m1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testReplayReturns409OnInvalidState()

	/**
	 * REQ-DLR-005: bulk replay rejects more than 100 ids.
	 *
	 * @return void
	 */
	public function testBulkReplayRejectsOverCap(): void {
		$ids = array_map(static fn (int $i) => 'id-' . $i, range(1, 101));
		$this->request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => $key === 'ids' ? $ids : $default
		);

		$response = $this->controller->bulkReplay();

		$this->assertSame(400, $response->getStatus());
	}//end testBulkReplayRejectsOverCap()

	/**
	 * REQ-DLR-005: bulk replay reports mixed per-id outcomes; a partial failure
	 * never aborts the batch.
	 *
	 * @return void
	 */
	public function testBulkReplayReportsMixedOutcomes(): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn ($key, $default = null) => $key === 'ids' ? ['A', 'B', 'C'] : $default
		);

		$this->eventService->method('replayMessage')->willReturnCallback(
			function (string $id) {
				if ($id === 'A') {
					return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'A');
				}

				if ($id === 'B') {
					throw new InvalidMessageStateException('delivered');
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('missing');
			}
		);

		$response = $this->controller->bulkReplay();
		$results = $response->getData()['results'];

		$this->assertSame('ok', $results['A']);
		$this->assertSame('invalid-state', $results['B']);
		$this->assertSame('not-found', $results['C']);
	}//end testBulkReplayReportsMixedOutcomes()

	/**
	 * TC-8 / REQ-005: subscribe() with an NC-native type propagates
	 * OCSForbiddenException when the per-family action is not granted — the
	 * coarse `event.subscribe` grant alone is insufficient.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005
	 */
	public function testSubscribeRejectsWhenFamilyActionNotGranted(): void {
		$this->request->method('getParams')->willReturn(['types' => ['com.nextcloud.files.node.created']]);

		$this->actionAuth->method('requireAction')->willReturnCallback(
			function ($user, string $action) {
				if ($action === 'event.subscribe-nextcloud-files') {
					throw new OCSForbiddenException("Action '{$action}' requires admin rights");
				}
			}
		);

		$this->expectException(OCSForbiddenException::class);
		$this->controller->subscribe();
	}//end testSubscribeRejectsWhenFamilyActionNotGranted()

	/**
	 * TC-9 / REQ-005: subscribe() succeeds once both the coarse
	 * `event.subscribe` grant AND the per-family
	 * `event.subscribe-nextcloud-files` grant pass (neither throws).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005
	 */
	public function testSubscribeSucceedsWhenFamilyActionGranted(): void {
		$this->request->method('getParams')->willReturn(['types' => ['com.nextcloud.files.node.created']]);
		// Neither requireAction call throws — mirrors a caller whose groups
		// hold both grants (or an admin — bypass logic lives in the real,
		// unchanged ActionAuthService, not in this controller).
		$this->actionAuth->method('requireAction');

		$saved = ObjectServiceMockBuilder::objectEntity($this, ['types' => ['com.nextcloud.files.node.created']], 'sub-uuid');
		$this->orObjectService->method('saveObject')->willReturn($saved);

		$response = $this->controller->subscribe();

		$this->assertSame(200, $response->getStatus());
	}//end testSubscribeSucceedsWhenFamilyActionGranted()

	/**
	 * TC-11 / REQ-005 regression: subscribing to ONLY a non-NC-native
	 * (OR-object) type triggers no per-family check — the pre-existing
	 * coarse-action-only posture is unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005
	 */
	public function testSubscribeToOrObjectTypeSkipsFamilyCheck(): void {
		$this->request->method('getParams')->willReturn(['types' => ['com.nextcloud.openregister.object.created']]);

		$calledActions = [];
		$this->actionAuth->method('requireAction')->willReturnCallback(
			function ($user, string $action) use (&$calledActions) {
				$calledActions[] = $action;
			}
		);

		$saved = ObjectServiceMockBuilder::objectEntity($this, ['types' => ['com.nextcloud.openregister.object.created']], 'sub-uuid');
		$this->orObjectService->method('saveObject')->willReturn($saved);

		$response = $this->controller->subscribe();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['event.subscribe'], $calledActions);
	}//end testSubscribeToOrObjectTypeSkipsFamilyCheck()

	/**
	 * REQ-005: updateSubscription() layers the same per-family gate as
	 * subscribe() — a request touching an NC-native type without the family
	 * grant is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-non-admin-subscription-requests-for-nc-native-types-must-be-gated-via-the-existing-adr-023-action-matrix-req-005
	 */
	public function testUpdateSubscriptionRejectsWhenFamilyActionNotGranted(): void {
		$this->request->method('getParams')->willReturn(['types' => ['com.nextcloud.calendar.object.created']]);

		$this->actionAuth->method('requireAction')->willReturnCallback(
			function ($user, string $action) {
				if ($action === 'event.subscribe-nextcloud-calendar') {
					throw new OCSForbiddenException("Action '{$action}' requires admin rights");
				}
			}
		);

		$this->expectException(OCSForbiddenException::class);
		$this->controller->updateSubscription('sub-uuid');
	}//end testUpdateSubscriptionRejectsWhenFamilyActionNotGranted()

	/**
	 * TC-25 / dead-letter-replay REQ-DLR-007: each dead-letter row carries
	 * its own resolved `actionKind` (default 'webhook' when the subscription
	 * has no `action`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-007
	 */
	public function testDeadLetterIndexSurfacesActionKindPerRow(): void {
		$rows = [
			ObjectServiceMockBuilder::objectEntity($this, ['status' => 'failed', 'subscription' => 'sub-webhook'], 'm1'),
			ObjectServiceMockBuilder::objectEntity($this, ['status' => 'failed', 'subscription' => 'sub-sync'], 'm2'),
		];
		$this->orObjectService->method('findAll')->willReturn(['results' => $rows, 'total' => 2]);
		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);

		$this->orObjectService->method('find')->willReturnCallback(
			function (string $id, ...$rest) {
				if ($id === 'sub-webhook') {
					return ObjectServiceMockBuilder::objectEntity($this, [], 'sub-webhook');
				}

				if ($id === 'sub-sync') {
					return ObjectServiceMockBuilder::objectEntity($this, ['action' => ['kind' => 'synchronization']], 'sub-sync');
				}

				throw new \OCP\AppFramework\Db\DoesNotExistException('missing');
			}
		);

		$response = $this->controller->deadLetterIndex();
		$rowsOut = $response->getData()['results'];

		$byId = [];
		foreach ($rowsOut as $row) {
			$byId[$row['subscription']] = $row;
		}

		$this->assertSame('webhook', $byId['sub-webhook']['actionKind']);
		$this->assertSame('synchronization', $byId['sub-sync']['actionKind']);
	}//end testDeadLetterIndexSurfacesActionKindPerRow()

	/**
	 * TC-26 / dead-letter-replay REQ-DLR-007: the Nextcloud-event provenance
	 * marker is derived from `event.source` (starts with `/nextcloud/`), NOT
	 * `event.type` — so an OR-object message sharing the `com.nextcloud.`
	 * TYPE prefix is correctly excluded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/dead-letter-replay/spec.md#requirement-dead-letter-listing-and-detail-must-surface-action-kind-and-nextcloud-event-provenance-req-dlr-007
	 */
	public function testDeadLetterIndexProvenanceUsesSourceNotType(): void {
		$rows = [
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['status' => 'failed', 'payload' => ['source' => '/nextcloud/files', 'type' => 'com.nextcloud.files.node.created']],
				'm-nc'
			),
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['status' => 'failed', 'payload' => ['source' => '/objects/person', 'type' => 'com.nextcloud.openregister.object.created']],
				'm-or'
			),
		];
		$this->orObjectService->method('findAll')->willReturn(['results' => $rows, 'total' => 2]);
		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);

		$response = $this->controller->deadLetterIndex();
		$rowsOut = $response->getData()['results'];

		$byId = [];
		foreach ($rowsOut as $row) {
			$byId[$row['uuid'] ?? $row['id'] ?? null] = $row;
		}

		$ncRow = current(array_filter($rowsOut, static fn ($r) => ($r['payload']['source'] ?? '') === '/nextcloud/files'));
		$orRow = current(array_filter($rowsOut, static fn ($r) => ($r['payload']['source'] ?? '') === '/objects/person'));

		$this->assertTrue($ncRow['nextcloudEvent']);
		$this->assertFalse($orRow['nextcloudEvent']);
	}//end testDeadLetterIndexProvenanceUsesSourceNotType()

	// -----------------------------------------------------------------------
	// messages() — GET /api/events/{id}/messages
	// -----------------------------------------------------------------------

	/**
	 * The wire contract: the event and its messages come back together, and
	 * the message query is narrowed to THAT event.
	 *
	 * The filter assertion is the load-bearing one. `findAll()` with a filter
	 * that failed to pin `event` would return every message in the register
	 * while the response shape stayed identical — a cross-tenant leak that a
	 * "returns 200 with a messages key" assertion cannot see.
	 *
	 * @return void
	 */
	public function testMessagesReturnsTheEventWithItsOwnMessagesOnly(): void {
		$event = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'order.created'], 'event-uuid-1');
		$this->orObjectService->method('find')->willReturn($event);
		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);

		$captured = null;
		$this->orObjectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$captured) {
				$captured = $config;
				return ['results' => [['id' => 'msg-1']], 'total' => 1];
			}
		);

		$response = $this->controller->messages(42);
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('order.created', $data['event']['name']);
		$this->assertSame([['id' => 'msg-1']], $data['messages']);

		$this->assertSame('openconnector', $captured['filters']['register']);
		$this->assertSame('event_message', $captured['filters']['schema']);
		$this->assertSame(
			'event-uuid-1',
			$captured['filters']['event'],
			'messages() must pin the message query to the requested event; an unpinned filter returns every message in the register'
		);
	}//end testMessagesReturnsTheEventWithItsOwnMessagesOnly()

	/**
	 * An unknown event id is a 404, not a 200 with an empty list.
	 *
	 * @return void
	 */
	public function testMessagesReturns404WhenTheEventDoesNotExist(): void {
		$this->orObjectService->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('missing'));

		$response = $this->controller->messages(999);

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testMessagesReturns404WhenTheEventDoesNotExist()

	/**
	 * The endpoint is `#[NoAdminRequired]`, so ADR-023 action authorization is
	 * the ONLY thing standing between any authenticated user and another
	 * user's event messages. Assert the action is actually demanded, and by
	 * its exact name — a typo'd action name resolves to no rule and the check
	 * silently passes.
	 *
	 * @return void
	 */
	public function testMessagesDemandsTheEventMessagesAction(): void {
		$event = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'order.created'], 'event-uuid-1');
		$this->orObjectService->method('find')->willReturn($event);
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);

		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'event.messages');

		$this->controller->messages(42);
	}//end testMessagesDemandsTheEventMessagesAction()

	// -----------------------------------------------------------------------
	// subscriptionMessages() — GET /api/events/subscriptions/{id}/messages
	// -----------------------------------------------------------------------

	/**
	 * The wire contract, plus the redaction that makes it safe to serve.
	 *
	 * A subscription carries its webhook `signingSecret`. Returning it verbatim
	 * would hand any caller who can read a subscription the key needed to forge
	 * signed deliveries, so redaction is part of this endpoint's contract and
	 * not a cosmetic detail.
	 *
	 * @return void
	 */
	public function testSubscriptionMessagesRedactsSigningSecretsAndPinsTheQuery(): void {
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'name' => 'webhook-1',
				'protocolSettings' => [
					'signingSecret' => 's3cr3t-current',
					'previousSigningSecret' => 's3cr3t-previous',
				],
			],
			'sub-uuid-1'
		);
		$this->orObjectService->method('find')->willReturn($subscription);
		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);

		$captured = null;
		$this->orObjectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$captured) {
				$captured = $config;
				return ['results' => [['id' => 'msg-9']], 'total' => 1];
			}
		);

		$response = $this->controller->subscriptionMessages('sub-uuid-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 'msg-9']], $data['messages']);

		$settings = $data['subscription']['protocolSettings'];
		$this->assertSame('**********', $settings['signingSecret']);
		$this->assertSame('**********', $settings['previousSigningSecret']);
		$this->assertStringNotContainsString(
			's3cr3t',
			json_encode($data),
			'no signing secret may appear anywhere in the serialised response'
		);

		$this->assertSame('event_message', $captured['filters']['schema']);
		$this->assertSame(
			'sub-uuid-1',
			$captured['filters']['subscription'],
			'subscriptionMessages() must pin the message query to the requested subscription'
		);
	}//end testSubscriptionMessagesRedactsSigningSecretsAndPinsTheQuery()

	/**
	 * An unknown subscription id is a 404.
	 *
	 * @return void
	 */
	public function testSubscriptionMessagesReturns404WhenTheSubscriptionDoesNotExist(): void {
		$this->orObjectService->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('missing'));

		$response = $this->controller->subscriptionMessages('nope');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testSubscriptionMessagesReturns404WhenTheSubscriptionDoesNotExist()

	/**
	 * ADR-023 action authorization is demanded, by its exact name.
	 *
	 * @return void
	 */
	public function testSubscriptionMessagesDemandsTheSubscriptionMessagesAction(): void {
		$subscription = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'webhook-1'], 'sub-uuid-1');
		$this->orObjectService->method('find')->willReturn($subscription);
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$this->request->method('getParam')->willReturnCallback(static fn ($key, $default = null) => $default);

		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'event.subscription-messages');

		$this->controller->subscriptionMessages('sub-uuid-1');
	}//end testSubscriptionMessagesDemandsTheSubscriptionMessagesAction()
}//end class
