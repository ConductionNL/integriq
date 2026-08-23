<?php

/**
 * Unit tests for SmsDispatchService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/notifynl-sms-channel/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\DeliveryResult;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SMS send + delivery-status polling/callback lifecycle.
 *
 * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md
 */
class SmsDispatchServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var LogSmsProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * @var RestNotifyNlProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $notifyNlProvider;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var SmsDispatchService
	 */
	private SmsDispatchService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->logProvider = $this->createMock(LogSmsProvider::class);
		$this->logProvider->method('getProviderId')->willReturn('log');
		$this->notifyNlProvider = $this->createMock(RestNotifyNlProvider::class);
		$this->notifyNlProvider->method('getProviderId')->willReturn('notifynl');
		$this->eventService = $this->createMock(EventService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SmsDispatchService(
			$this->objectService,
			$this->logProvider,
			$this->notifyNlProvider,
			$this->eventService,
			$this->l,
			$this->logger,
			new RawSourceResolver($this->objectService, $this->logger)
		);

	}//end setUp()

	/**
	 * Build a real ObjectEntity for a given data payload (magic getters need the real Entity path).
	 *
	 * @param array $data The object data.
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'uuid-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	/**
	 * resolveProvider selects the log provider by default and for explicit "log".
	 *
	 * @return void
	 */
	public function testResolveProviderDefaultsToLog(): void {
		$this->assertSame($this->logProvider, $this->service->resolveProvider([]));
		$this->assertSame($this->logProvider, $this->service->resolveProvider(['provider' => 'log']));

	}//end testResolveProviderDefaultsToLog()

	/**
	 * resolveProvider selects the NotifyNL provider for configuration.provider=notifynl.
	 *
	 * @return void
	 */
	public function testResolveProviderSelectsNotifyNlWhenConfigured(): void {
		$this->assertSame($this->notifyNlProvider, $this->service->resolveProvider(['provider' => 'notifynl']));

	}//end testResolveProviderSelectsNotifyNlWhenConfigured()

	/**
	 * sendMessage throws a descriptive error when no active SMS source exists.
	 *
	 * @return void
	 */
	public function testSendMessageThrowsWhenNoSourceConfigured(): void {
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(SmsProviderException::class);

		$this->service->sendMessage(to: '+31612345678', body: 'hi');

	}//end testSendMessageThrowsWhenNoSourceConfigured()

	/**
	 * sendMessage rejects an unnormalisable recipient before creating any record.
	 *
	 * @return void
	 */
	public function testSendMessageThrowsOnInvalidPhoneNumber(): void {
		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(SmsProviderException::class);

		$this->service->sendMessage(to: 'not-a-number', body: 'hi');

	}//end testSendMessageThrowsOnInvalidPhoneNumber()

	/**
	 * A successful send creates a queued->sent sms_message and emits one delivery-status event.
	 *
	 * @return void
	 */
	public function testSendMessageCreatesAndSendsMessage(): void {
		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);

		$created = $this->entity(
			['recipientMsisdn' => '+31612345678', 'status' => 'queued', 'attempts' => []],
			'sms-uuid-1'
		);
		$updated = $this->entity(
			[
				'recipientMsisdn' => '+31612345678',
				'status' => 'queued',
				'providerMessageId' => 'MOCK-SMS-1',
				'attempts' => [['at' => '2026-01-01T00:00:00+00:00', 'error' => null]],
			],
			'sms-uuid-1'
		);

		$this->objectService->expects($this->exactly(2))
			->method('saveObject')
			->willReturnOnConsecutiveCalls($created, $updated);

		$this->logProvider->method('send')->willReturn(new DeliveryResult('MOCK-SMS-1', 'queued', null));

		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				SmsDispatchService::EVENT_TYPE_DELIVERY_STATUS,
				$this->anything(),
				'sms-uuid-1',
				$this->callback(static fn (array $data) => ($data['status'] ?? null) === 'queued')
			);

		$result = $this->service->sendMessage(to: '0612345678', body: 'hello', options: ['templateId' => 'tmpl-1']);

		$this->assertSame('MOCK-SMS-1', $result->getObject()['providerMessageId']);

	}//end testSendMessageCreatesAndSendsMessage()

	/**
	 * A provider failure marks the message failed (never throws out of sendMessage) and emits one event.
	 *
	 * @return void
	 */
	public function testSendMessageMarksFailedOnProviderException(): void {
		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);

		$created = $this->entity(['recipientMsisdn' => '+31612345678', 'status' => 'queued', 'attempts' => []], 'sms-uuid-1');
		$failed = $this->entity(
			['recipientMsisdn' => '+31612345678', 'status' => 'failed', 'detail' => 'gateway down', 'attempts' => [['at' => 'now', 'error' => 'gateway down']]],
			'sms-uuid-1'
		);

		$this->objectService->expects($this->exactly(2))
			->method('saveObject')
			->willReturnOnConsecutiveCalls($created, $failed);

		$this->logProvider->method('send')->willThrowException(new SmsProviderException(message: 'gateway down'));

		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				SmsDispatchService::EVENT_TYPE_DELIVERY_STATUS,
				$this->anything(),
				'sms-uuid-1',
				$this->callback(static fn (array $data) => ($data['status'] ?? null) === 'failed')
			);

		$result = $this->service->sendMessage(to: '0612345678', body: 'hello');

		$this->assertSame('failed', $result->getObject()['status']);

	}//end testSendMessageMarksFailedOnProviderException()

	/**
	 * pollStatus throws when the message uuid is unknown.
	 *
	 * @return void
	 */
	public function testPollStatusThrowsWhenMessageNotFound(): void {
		$this->objectService->method('find')->willReturn(null);

		$this->expectException(SmsProviderException::class);

		$this->service->pollStatus(uuid: 'missing-uuid');

	}//end testPollStatusThrowsWhenMessageNotFound()

	/**
	 * pollStatus throws when the message has no providerMessageId yet.
	 *
	 * @return void
	 */
	public function testPollStatusThrowsWhenNoProviderMessageIdYet(): void {
		$this->objectService->method('find')->willReturn($this->entity(['status' => 'queued'], 'sms-uuid-1'));

		$this->expectException(SmsProviderException::class);

		$this->service->pollStatus(uuid: 'sms-uuid-1');

	}//end testPollStatusThrowsWhenNoProviderMessageIdYet()

	/**
	 * pollStatus persists and emits an event when the provider reports a changed status.
	 *
	 * @return void
	 */
	public function testPollStatusUpdatesOnStatusChange(): void {
		$message = $this->entity(['status' => 'sent', 'providerMessageId' => 'MOCK-SMS-1'], 'sms-uuid-1');
		$this->objectService->method('find')->willReturn($message);

		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);

		$this->logProvider->method('fetchStatus')->willReturn(new DeliveryResult('MOCK-SMS-1', 'delivered', null));

		$updated = $this->entity(['status' => 'delivered', 'providerMessageId' => 'MOCK-SMS-1'], 'sms-uuid-1');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($updated);
		$this->eventService->expects($this->once())->method('emitCloudEvent');

		$result = $this->service->pollStatus(uuid: 'sms-uuid-1');

		$this->assertSame('delivered', $result->getObject()['status']);

	}//end testPollStatusUpdatesOnStatusChange()

	/**
	 * pollStatus is a no-op (no persistence, no event) when the provider reports an unchanged status.
	 *
	 * @return void
	 */
	public function testPollStatusNoOpWhenStatusUnchanged(): void {
		$message = $this->entity(['status' => 'delivered', 'providerMessageId' => 'MOCK-SMS-1'], 'sms-uuid-1');
		$this->objectService->method('find')->willReturn($message);

		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);

		$this->logProvider->method('fetchStatus')->willReturn(new DeliveryResult('MOCK-SMS-1', 'delivered', null));

		$this->objectService->expects($this->never())->method('saveObject');
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->pollStatus(uuid: 'sms-uuid-1');

		$this->assertSame('delivered', $result->getObject()['status']);

	}//end testPollStatusNoOpWhenStatusUnchanged()

	/**
	 * handleStatusCallback ignores an unsupported status without persisting anything.
	 *
	 * @return void
	 */
	public function testHandleStatusCallbackIgnoresUnsupportedStatus(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->service->handleStatusCallback(providerMessageId: 'MOCK-SMS-1', status: 'not-a-real-status', detail: null);

		$this->assertNull($result);

	}//end testHandleStatusCallbackIgnoresUnsupportedStatus()

	/**
	 * handleStatusCallback returns null (never throws) for an unknown providerMessageId.
	 *
	 * @return void
	 */
	public function testHandleStatusCallbackReturnsNullForUnknownProviderMessageId(): void {
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service->handleStatusCallback(providerMessageId: 'unknown-id', status: 'delivered', detail: null);

		$this->assertNull($result);

	}//end testHandleStatusCallbackReturnsNullForUnknownProviderMessageId()

	/**
	 * handleStatusCallback applies the reported status to the matching message and emits one event.
	 *
	 * @return void
	 */
	public function testHandleStatusCallbackUpdatesMatchingMessage(): void {
		$message = $this->entity(['status' => 'sent', 'providerMessageId' => 'MOCK-SMS-1'], 'sms-uuid-1');
		$this->objectService->method('findAll')->willReturn(['results' => [$message], 'total' => 1]);

		$updated = $this->entity(['status' => 'delivered', 'providerMessageId' => 'MOCK-SMS-1'], 'sms-uuid-1');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($updated);
		$this->eventService->expects($this->once())->method('emitCloudEvent');

		$result = $this->service->handleStatusCallback(providerMessageId: 'MOCK-SMS-1', status: 'delivered', detail: 'ok');

		$this->assertSame('delivered', $result->getObject()['status']);

	}//end testHandleStatusCallbackUpdatesMatchingMessage()
}//end class
