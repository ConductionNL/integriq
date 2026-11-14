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

use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\IDBConnection;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\DeliveryResult;
use OCA\Integriq\Service\Sms\LogSmsProvider;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCA\Integriq\Service\Sms\SmsProviderRegistry;
use OCA\Integriq\Service\Sms\TwilioSmsProvider;
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
	 * The opt-out services over in-memory tables.
	 *
	 * @var OptOutFixture
	 */
	private OptOutFixture $optOuts;

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
		$this->optOuts = new OptOutFixture($this, $this->createMock(IDBConnection::class));

		$this->service = new SmsDispatchService(
			$this->objectService,
			$this->logProvider,
			$this->notifyNlProvider,
			$this->eventService,
			$this->l,
			$this->logger,
			new RawSourceResolver($this->objectService, $this->logger),
			$this->optOuts->gate()
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
	 * A provider value no binding answers to resolves to a refusing binding naming it, not to log.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
	 */
	public function testResolveProviderRefusesAnUnknownValue(): void {
		$provider = $this->service->resolveProvider(['provider' => 'twillio']);

		$this->assertNotSame($this->logProvider, $provider);
		$this->assertSame('twillio', $provider->getProviderId());

	}//end testResolveProviderRefusesAnUnknownValue()

	/**
	 * A registry given to the dispatcher answers for the providers it holds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function testResolveProviderAsksTheRegistry(): void {
		$twilio = $this->createMock(TwilioSmsProvider::class);
		$twilio->method('getProviderId')->willReturn('twilio');
		$service = new SmsDispatchService(
			$this->objectService,
			$this->logProvider,
			$this->notifyNlProvider,
			$this->eventService,
			$this->l,
			$this->logger,
			new RawSourceResolver($this->objectService, $this->logger),
			$this->optOuts->gate(),
			new SmsProviderRegistry(logProvider: $this->logProvider, notifyNl: $this->notifyNlProvider, twilio: $twilio)
		);

		$this->assertSame($twilio, $service->resolveProvider(['provider' => 'twilio']));
		$this->assertSame($this->logProvider, $service->resolveProvider([]));

	}//end testResolveProviderAsksTheRegistry()

	/**
	 * A send through a mistyped provider is stored failed, naming the value, and the log binding sends nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
	 */
	public function testSendThroughAnUnknownProviderIsStoredFailedNamingIt(): void {
		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'twillio']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);
		$this->logProvider->expects($this->never())->method('send');

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->entity($object, 'sms-uuid-unknown');
			}
		);

		$result = $this->service->sendMessage(to: '0612345678', body: 'hello');
		$data = $result->getObject();

		$this->assertSame('twillio', $saved[0]['provider']);
		$this->assertSame('failed', $data['status']);
		$this->assertStringContainsString('"twillio"', (string)$data['detail']);

	}//end testSendThroughAnUnknownProviderIsStoredFailedNamingIt()

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
	 * An opted-out number is refused before the message is stored and
	 * before the provider is called, with the code the controller maps to 409.
	 *
	 * @return void
	 */
	public function testSendMessageRefusesAnOptedOutNumber(): void {
		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);
		$this->optOuts->registry()->add('+31612345678');
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logProvider->expects($this->never())->method('send');

		try {
			$this->service->sendMessage(to: '0612345678', body: 'hello', options: ['category' => 'service']);
			$this->fail('an opted-out number must not be sent to');
		} catch (SmsProviderException $exception) {
			$this->assertSame('opted-out', $exception->getErrorCode());
		}

		$this->assertCount(1, $this->optOuts->log->ofKind('suppressed'));

	}//end testSendMessageRefusesAnOptedOutNumber()

	/**
	 * A security SMS to the same number still goes out, with no stop text.
	 *
	 * @return void
	 */
	public function testSendMessageSendsAnExemptMessageDespiteAnOptOut(): void {
		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);
		$this->optOuts->registry()->add('+31612345678');
		$this->objectService->method('saveObject')->willReturn($this->entity(['status' => 'queued', 'attempts' => []], 'sms-uuid-1'));
		$this->logProvider->expects($this->once())
			->method('send')
			->with($this->anything(), '+31612345678', 'Uw code is 123456', $this->callback(static fn (array $o): bool => isset($o['personalisation']['unsubscribe']) === false))
			->willReturn(new DeliveryResult('MOCK-SMS-2', 'queued', null));

		$this->service->sendMessage(to: '0612345678', body: 'Uw code is 123456', options: ['category' => 'security']);

		$this->assertCount(1, $this->optOuts->log->ofKind('override'));

	}//end testSendMessageSendsAnExemptMessageDespiteAnOptOut()

	/**
	 * An allowed SMS carries the short stop text, in the body and as
	 * personalisation for a NotifyNL template, at most 50 characters.
	 *
	 * @return void
	 */
	public function testSendMessageAddsTheShortStopText(): void {
		$this->optOuts->baseUrl = 'https://gem.nl';
		$source = $this->entity(['type' => 'sms', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);
		$this->objectService->method('saveObject')->willReturn($this->entity(['status' => 'queued', 'attempts' => []], 'sms-uuid-1'));
		$sent = [];
		$this->logProvider->method('send')->willReturnCallback(
			function (array $config, string $to, string $body, array $options) use (&$sent): DeliveryResult {
				$sent = ['body' => $body, 'options' => $options];
				return new DeliveryResult('MOCK-SMS-3', 'queued', null);
			}
		);

		$this->service->sendMessage(to: '0612345678', body: 'Uw afspraak is morgen.', options: ['category' => 'reminder']);

		$text = (string)$sent['options']['personalisation']['unsubscribe'];
		$this->assertLessThanOrEqual(50, mb_strlen($text));
		$this->assertStringStartsWith('Stop: gem.nl/apps/integriq/u/', $text);
		$this->assertSame("Uw afspraak is morgen.\n" . $text, $sent['body']);
		$this->assertArrayNotHasKey('category', $sent['options'], 'the decision options do not reach the provider');

	}//end testSendMessageAddsTheShortStopText()

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
	/**
	 * A send naming an enabled SMS source goes through that source, looked up
	 * by its slug, and the stored message says which source sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function testSendThroughANamedSourceUsesThatSourceAndStoresItsSlug(): void {
		$source = $this->entity(
			['slug' => 'sms-twee', 'type' => 'sms', 'isEnabled' => true, 'configuration' => ['provider' => 'log', 'senderId' => 'Twee']],
			'source-2'
		);
		$filters = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$filters, $source): array {
				$filters[] = $config['filters'];
				return ['results' => [$source], 'total' => 1];
			}
		);

		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->entity($object, 'sms-uuid-1');
			}
		);

		$sentWith = null;
		$this->logProvider->expects($this->once())->method('send')->willReturnCallback(
			function (array $config, string $to, string $body, array $options) use (&$sentWith): DeliveryResult {
				$sentWith = [$config, $options];
				return new DeliveryResult('MOCK-SMS-9', 'sent', null);
			}
		);

		$result = $this->service->sendMessage(
			to: '0612345678',
			body: 'hello',
			options: ['templateId' => 'tmpl-1', 'sourceSlug' => 'sms-twee']
		);

		$this->assertSame(
			[['register' => 'integriq', 'schema' => 'source', 'slug' => 'sms-twee']],
			$filters
		);
		$this->assertSame('sms-twee', $saved[0]['sourceSlug']);
		$this->assertSame('sms-twee', $result->getObject()['sourceSlug']);
		$this->assertSame(['provider' => 'log', 'senderId' => 'Twee'], $sentWith[0]);
		$this->assertArrayNotHasKey('sourceSlug', $sentWith[1]);
		$this->assertSame('sent', $result->getObject()['status']);

	}//end testSendThroughANamedSourceUsesThatSourceAndStoresItsSlug()

	/**
	 * Without a slug the first enabled SMS source sends, as before, and its slug is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function testSendWithoutASlugUsesTheFirstEnabledSmsSource(): void {
		$source = $this->entity(['slug' => 'sms-een', 'type' => 'sms', 'isEnabled' => true, 'configuration' => ['provider' => 'log']]);
		$filters = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$filters, $source): array {
				$filters[] = $config['filters'];
				return ['results' => [$source], 'total' => 1];
			}
		);
		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->entity($object, 'sms-uuid-1');
			}
		);
		$this->logProvider->method('send')->willReturn(new DeliveryResult('MOCK-SMS-1', 'sent', null));

		$this->service->sendMessage(to: '0612345678', body: 'hello', options: ['sourceSlug' => '  ']);

		$this->assertSame([['register' => 'integriq', 'schema' => 'source', 'type' => 'sms', 'isEnabled' => true]], $filters);
		$this->assertSame('sms-een', $saved[0]['sourceSlug']);

	}//end testSendWithoutASlugUsesTheFirstEnabledSmsSource()

	/**
	 * Slugs that are no enabled SMS source.
	 *
	 * @return array<string, array{0: list<ObjectEntity>}>
	 */
	public static function notAnSmsSource(): array {
		$entity = static function (array $data): ObjectEntity {
			$source = new ObjectEntity();
			$source->setObject($data);
			$source->setUuid('');
			return $source;
		};

		return [
			'an api source' => [[$entity(['slug' => 'brp-haalcentraal', 'type' => 'api', 'isEnabled' => true])]],
			'a disabled sms source' => [[$entity(['slug' => 'brp-haalcentraal', 'type' => 'sms', 'isEnabled' => false])]],
			'a different slug' => [[$entity(['slug' => 'other', 'type' => 'sms', 'isEnabled' => true])]],
			'no source' => [[]],
		];

	}//end notAnSmsSource()

	/**
	 * A slug that is not an enabled SMS source is refused naming the slug,
	 * before anything is stored or sent.
	 *
	 * @param list<ObjectEntity> $found What the slug lookup finds.
	 *
	 * @return void
	 *
	 * @dataProvider notAnSmsSource
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function testANamedSourceThatIsNotAnEnabledSmsSourceIsRefused(array $found): void {
		$this->objectService->method('findAll')->willReturn(['results' => $found, 'total' => count($found)]);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logProvider->expects($this->never())->method('send');

		try {
			$this->service->sendMessage(to: '0612345678', body: 'hello', options: ['sourceSlug' => 'brp-haalcentraal']);
			$this->fail('A send through a source that is not an enabled SMS source must be refused.');
		} catch (SmsProviderException $exception) {
			$this->assertSame(SmsDispatchService::ERROR_UNKNOWN_SOURCE, $exception->getErrorCode());
			$this->assertSame('"%s" is not an enabled SMS source.', $exception->getMessage());
		}

	}//end testANamedSourceThatIsNotAnEnabledSmsSourceIsRefused()

	/**
	 * A status read goes through the source the message was sent through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function testPollStatusReadsThroughTheSourceTheMessageNames(): void {
		$message = $this->entity(['status' => 'sent', 'providerMessageId' => 'MOCK-SMS-1', 'sourceSlug' => 'sms-twee'], 'sms-uuid-1');
		$this->objectService->method('find')->willReturn($message);

		$source = $this->entity(['slug' => 'sms-twee', 'type' => 'sms', 'isEnabled' => true, 'configuration' => ['provider' => 'notifynl']], '');
		$filters = [];
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use (&$filters, $source): array {
				$filters[] = $config['filters'];
				return ['results' => [$source], 'total' => 1];
			}
		);
		$this->logProvider->expects($this->never())->method('fetchStatus');
		$this->notifyNlProvider->expects($this->once())->method('fetchStatus')
			->with(['provider' => 'notifynl'], 'MOCK-SMS-1')
			->willReturn(new DeliveryResult('MOCK-SMS-1', 'delivered', null));
		$this->objectService->method('saveObject')->willReturnCallback(
			fn (array $object): ObjectEntity => $this->entity($object, 'sms-uuid-1')
		);

		$result = $this->service->pollStatus(uuid: 'sms-uuid-1');

		$this->assertSame([['register' => 'integriq', 'schema' => 'source', 'slug' => 'sms-twee']], $filters);
		$this->assertSame('delivered', $result->getObject()['status']);

	}//end testPollStatusReadsThroughTheSourceTheMessageNames()
}//end class
