<?php

/**
 * Unit tests for NotifyNlEmailService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\OutboundSendGateInterface;
use OCA\Integriq\Service\NotifyNlEmailService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Service\Sms\DeliveryResult;
use OCA\Integriq\Service\Sms\RestNotifyNlProvider;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * NotifyNL e-mail: validated, asked, recorded, handed over; callbacks move the right recipient.
 *
 * @covers \OCA\Integriq\Service\NotifyNlEmailService
 * @uses   \OCA\Integriq\Service\Security\RawSourceResolver
 * @uses   \OCA\Integriq\Service\Sms\DeliveryResult
 * @uses   \OCA\Integriq\Exception\SmsProviderException
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
 */
class NotifyNlEmailServiceTest extends TestCase {

	/**
	 * @var \OCA\OpenRegister\Service\ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * @var RestNotifyNlProvider&MockObject
	 */
	private $notifyNl;

	/**
	 * @var OutboundSendGateInterface&MockObject
	 */
	private $gate;

	/**
	 * @var MessageRecorder&MockObject
	 */
	private $recorder;

	/**
	 * @var NotifyNlEmailService
	 */
	private NotifyNlEmailService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->notifyNl = $this->createMock(RestNotifyNlProvider::class);
		$this->gate = $this->createMock(OutboundSendGateInterface::class);
		$this->recorder = $this->createMock(MessageRecorder::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));

		$this->service = new NotifyNlEmailService(
			objectService: $this->objectService,
			rawSourceResolver: new RawSourceResolver($this->objectService, new NullLogger()),
			notifyNl: $this->notifyNl,
			gate: $this->gate,
			recorder: $this->recorder,
			l: $l10n,
			logger: new NullLogger()
		);

	}//end setUp()

	/**
	 * Enabled SMS sources: an SMS-only one and a NotifyNL one.
	 *
	 * @return void
	 */
	private function sources(): void {
		$cm = new ObjectEntity();
		$cm->setObject(['slug' => 'sms-cm', 'type' => 'sms', 'isEnabled' => true, 'configuration' => ['provider' => 'cmcom']]);
		$cm->setUuid('');
		$notify = new ObjectEntity();
		$notify->setObject(['slug' => 'notifynl', 'type' => 'sms', 'isEnabled' => true, 'configuration' => ['provider' => 'notifynl', 'templateMapping' => ['ontvangstbevestiging' => 'tpl-uuid']]]);
		$notify->setUuid('');
		$this->objectService->method('findAll')->willReturn(['results' => [$cm, $notify], 'total' => 2]);

	}//end sources()

	/**
	 * A mail for a mapped template goes to NotifyNL with the log row as reference, and is handed over with NotifyNL's id.
	 *
	 * @return void
	 */
	public function testAMailIsSentRecordedAndHandedOver(): void {
		$this->sources();
		$this->gate->expects($this->once())->method('check')
			->with('mail', 'reminder', 'jan@example.nl', ['caseRef' => 'ZAAK-1', 'sourceApp' => 'dossiq', 'correlationId' => 'obj-1'])
			->willReturn(['send' => true, 'category' => 'reminder']);
		$this->gate->method('compose')->willReturn(['body' => '', 'unsubscribeLink' => 'https://x/unsub', 'headers' => []]);
		$this->gate->expects($this->once())->method('open')
			->with('notifynl', 'obj-1', 'ontvangstbevestiging', '', 'jan@example.nl', $this->anything(), ['send' => true, 'category' => 'reminder'])
			->willReturn('row-1');
		$this->notifyNl->expects($this->once())->method('sendEmail')
			->with(
				['provider' => 'notifynl', 'templateMapping' => ['ontvangstbevestiging' => 'tpl-uuid']],
				'jan@example.nl',
				'ontvangstbevestiging',
				['naam' => 'Jan', 'unsubscribe' => 'https://x/unsub'],
				'row-1'
			)
			->willReturn(new DeliveryResult('notify-1', 'queued', null));
		$this->gate->expects($this->once())->method('handedOver')->with('row-1', 'jan@example.nl', 'notify-1');
		$this->gate->expects($this->never())->method('failed');

		$result = $this->service->sendEmail(
			emailAddress: ' jan@example.nl ',
			templateId: 'ontvangstbevestiging',
			personalisation: ['naam' => 'Jan'],
			options: ['category' => 'reminder', 'caseRef' => 'ZAAK-1', 'sourceApp' => 'dossiq', 'objectUri' => 'obj-1']
		);

		$this->assertSame(['id' => 'row-1', 'reference' => 'notify-1', 'status' => 'sent'], $result);

	}//end testAMailIsSentRecordedAndHandedOver()

	/**
	 * Requests that are refused before anything is sent or recorded.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function refusedBeforeSending(): array {
		return [
			'no domain' => ['jan@', 'tpl', 'invalid-address'],
			'a domain without a dot' => ['jan@localhost', 'tpl', 'invalid-address'],
			'no at sign' => ['jan.example.nl', 'tpl', 'invalid-address'],
			'empty' => ['', 'tpl', 'invalid-address'],
			'no template' => ['jan@example.nl', '  ', 'missing-template'],
		];

	}//end refusedBeforeSending()

	/**
	 * An invalid address or a missing template is refused; nothing is sent, asked or recorded.
	 *
	 * @param string $address  The address.
	 * @param string $template The template.
	 * @param string $code     The expected code.
	 *
	 * @return void
	 *
	 * @dataProvider refusedBeforeSending
	 */
	public function testARequestThatCannotBeSentIsRefusedFirst(string $address, string $template, string $code): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->gate->expects($this->never())->method('check');
		$this->gate->expects($this->never())->method('open');
		$this->notifyNl->expects($this->never())->method('sendEmail');

		try {
			$this->service->sendEmail(emailAddress: $address, templateId: $template, personalisation: []);
			$this->fail('The request must be refused.');
		} catch (SmsProviderException $exception) {
			$this->assertSame($code, $exception->getErrorCode());
		}

	}//end testARequestThatCannotBeSentIsRefusedFirst()

	/**
	 * A slug that names no NotifyNL source is refused naming it; the opt-out list refusal is recorded.
	 *
	 * @return void
	 */
	public function testNoNotifyNlSourceAndAnOptOutAreRefused(): void {
		$this->sources();
		try {
			$this->service->sendEmail(emailAddress: 'jan@example.nl', templateId: 'tpl', personalisation: [], options: ['sourceSlug' => 'sms-cm']);
			$this->fail('A source that is not NotifyNL must be refused.');
		} catch (SmsProviderException $exception) {
			$this->assertSame(['unknown-source', 'No enabled NotifyNL source answers to "sms-cm".'], [$exception->getErrorCode(), $exception->getMessage()]);
		}

		$decision = ['send' => false, 'code' => 'opted-out', 'reason' => 'Opted out of reminders'];
		$this->gate->method('check')->willReturn($decision);
		$this->gate->expects($this->once())->method('recordRefusal')->with('notifynl', '', $decision, $this->anything());
		$this->gate->expects($this->never())->method('open');
		$this->notifyNl->expects($this->never())->method('sendEmail');

		$this->expectExceptionObject(new SmsProviderException(message: 'Opted out of reminders', errorCode: 'opted-out'));
		$this->service->sendEmail(emailAddress: 'jan@example.nl', templateId: 'tpl', personalisation: [], options: ['sourceSlug' => 'notifynl']);

	}//end testNoNotifyNlSourceAndAnOptOutAreRefused()

	/**
	 * A NotifyNL failure marks the recipient failed on the log row and is passed on.
	 *
	 * @return void
	 */
	public function testANotifyNlFailureIsRecorded(): void {
		$this->sources();
		$this->gate->method('check')->willReturn(['send' => true]);
		$this->gate->method('compose')->willReturn(['body' => '', 'unsubscribeLink' => null, 'headers' => []]);
		$this->gate->method('open')->willReturn('row-2');
		$this->notifyNl->method('sendEmail')->willThrowException(new SmsProviderException(message: 'NotifyNL responded with HTTP 400.'));
		$this->gate->expects($this->once())->method('failed')->with('row-2', 'jan@example.nl', 'send', 'NotifyNL responded with HTTP 400.');
		$this->gate->expects($this->never())->method('handedOver');

		$this->expectExceptionMessage('NotifyNL responded with HTTP 400.');
		$this->service->sendEmail(emailAddress: 'jan@example.nl', templateId: 'tpl', personalisation: []);

	}//end testANotifyNlFailureIsRecorded()

	/**
	 * A delivered or failed callback moves the recipient NotifyNL's id was handed over for.
	 *
	 * @return void
	 */
	public function testACallbackMovesTheMatchingRecipient(): void {
		$this->recorder->method('read')->with('row-1')->willReturn(
			['channel' => 'notifynl', 'recipients' => [['address' => 'piet@example.nl', 'reference' => 'other'], ['address' => 'jan@example.nl', 'reference' => 'notify-1']]]
		);
		$this->recorder->expects($this->once())->method('deliveryReported')->with('row-1', 'jan@example.nl', 'delivered');
		$this->recorder->expects($this->once())->method('recipientFailed')->with('row-1', 'jan@example.nl', 'delivery', 'permanent-failure');

		$this->assertTrue($this->service->applyCallback(reference: 'row-1', providerMessageId: 'notify-1', status: 'delivered', detail: 'delivered'));
		$this->assertTrue($this->service->applyCallback(reference: 'row-1', providerMessageId: 'notify-1', status: 'failed', detail: 'permanent-failure'));
		$this->assertFalse($this->service->applyCallback(reference: 'row-1', providerMessageId: 'notify-1', status: 'sent', detail: null));
		$this->assertFalse($this->service->applyCallback(reference: 'row-1', providerMessageId: 'notify-9', status: 'delivered', detail: null));
		$this->assertFalse($this->service->applyCallback(reference: '', providerMessageId: 'notify-1', status: 'delivered', detail: null));

	}//end testACallbackMovesTheMatchingRecipient()

	/**
	 * A callback naming no row, or a row on another channel, changes nothing.
	 *
	 * @return void
	 */
	public function testACallbackForAnotherRowChangesNothing(): void {
		$this->recorder->method('read')->willReturnCallback(
			static function (string $uuid): array {
				if ($uuid === 'gone') {
					throw new DoesNotExistException('No outbound message "gone".');
				}

				return ['channel' => 'mail', 'recipients' => [['address' => 'jan@example.nl', 'reference' => 'notify-1']]];
			}
		);
		$this->recorder->expects($this->never())->method('deliveryReported');
		$this->recorder->expects($this->never())->method('recipientFailed');

		$this->assertFalse($this->service->applyCallback(reference: 'gone', providerMessageId: 'notify-1', status: 'delivered', detail: null));
		$this->assertFalse($this->service->applyCallback(reference: 'mail-row', providerMessageId: 'notify-1', status: 'delivered', detail: null));

	}//end testACallbackForAnotherRowChangesNothing()
}//end class
