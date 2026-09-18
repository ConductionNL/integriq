<?php

/**
 * Unit tests for MessageRecorder, the redactor and the delivery states.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\ChannelReportingCapabilities;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\RecipientState;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests that the record answers "did it leave, for whom, and where did it stop".
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-every-outbound-message-is-recorded-per-recipient-and-per-step-req-ocl-001
 */
class MessageRecorderTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService|MockObject
	 */
	private $objectService;

	/**
	 * The record as it currently stands, which the double reads and writes.
	 *
	 * @var array<string,mixed>
	 */
	private array $stored = [];

	/**
	 * The recorder under test.
	 *
	 * @var MessageRecorder
	 */
	private MessageRecorder $recorder;

	/**
	 * Set up the recorder over a double that behaves like storage.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->stored = [];
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) {
				$this->stored = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'message-uuid');
			}
		);
		$this->objectService->method('find')->willReturnCallback(
			function () {
				return ObjectServiceMockBuilder::objectEntity($this, $this->stored, 'message-uuid');
			}
		);

		$this->recorder = new MessageRecorder(
			$this->objectService,
			new BodyRedactor(),
			new ChannelReportingCapabilities(),
		);

	}//end setUp()

	/**
	 * Three recipients, one failure: the record says which one and where.
	 *
	 * @return void
	 */
	public function testAPartialFailureNamesTheRecipientAndTheStep(): void {
		$this->start();

		$this->recorder->handedOver('message-uuid', 'jan@example.org', 'MAIL-1');
		$this->recorder->handedOver('message-uuid', 'piet@example.org', 'MAIL-2');
		$this->recorder->recipientFailed(
			'message-uuid',
			'gemachtigde@advocaat.example',
			'transport',
			'550 mailbox unavailable'
		);

		$this->assertSame(MessageRecorder::STATUS_PARTIAL, $this->stored['status']);

		$byAddress = [];
		foreach ($this->stored['recipients'] as $recipient) {
			$byAddress[$recipient['address']] = $recipient;
		}

		$this->assertSame(RecipientState::STATUS_SENT, $byAddress['jan@example.org']['status']);
		$this->assertSame(RecipientState::STATUS_FAILED, $byAddress['gemachtigde@advocaat.example']['status']);
		$this->assertSame('transport', $byAddress['gemachtigde@advocaat.example']['failedStep']);
		$this->assertSame('550 mailbox unavailable', $byAddress['gemachtigde@advocaat.example']['reason']);

	}//end testAPartialFailureNamesTheRecipientAndTheStep()

	/**
	 * A send that never reached a transport is still a record.
	 *
	 * @return void
	 */
	public function testASendThatNeverLeftIsStillRecorded(): void {
		$this->start();

		$this->recorder->stepFailed('message-uuid', 'render', 'The template names a field the case lacks.');

		$this->assertSame(MessageRecorder::STATUS_FAILED, $this->stored['status']);
		$this->assertSame('render', end($this->stored['steps'])['step']);
		foreach ($this->stored['recipients'] as $recipient) {
			$this->assertSame(RecipientState::STATUS_FAILED, $recipient['status']);
			$this->assertNotSame(RecipientState::REPORTED, $recipient['deliveryState']);
		}

	}//end testASendThatNeverLeftIsStillRecorded()

	/**
	 * Accepted by a transport is not delivered: silence stays `not reported`.
	 *
	 * @return void
	 */
	public function testSilenceIsNotDelivery(): void {
		$this->start('sms');

		$this->recorder->handedOver('message-uuid', 'jan@example.org');

		$recipient = $this->stored['recipients'][0];
		$this->assertSame(RecipientState::STATUS_SENT, $recipient['status']);
		$this->assertSame(RecipientState::NOT_REPORTED, $recipient['deliveryState']);

	}//end testSilenceIsNotDelivery()

	/**
	 * A channel that cannot report a read says so, rather than reporting none.
	 *
	 * @return void
	 */
	public function testAChannelWithoutReadReceiptsSaysSo(): void {
		$this->start('sms');

		$this->assertSame(RecipientState::UNSUPPORTED, $this->stored['recipients'][0]['readState']);
		$this->assertSame(RecipientState::NOT_REPORTED, $this->stored['recipients'][0]['deliveryState']);

	}//end testAChannelWithoutReadReceiptsSaysSo()

	/**
	 * A reporting channel records the receipt it actually got.
	 *
	 * @return void
	 */
	public function testAReportedDeliveryIsRecorded(): void {
		$this->start('sms');
		$this->recorder->handedOver('message-uuid', 'jan@example.org');

		$this->recorder->deliveryReported('message-uuid', 'jan@example.org', 'DELIVRD');

		$recipient = $this->stored['recipients'][0];
		$this->assertSame(RecipientState::REPORTED, $recipient['deliveryState']);
		$this->assertNotSame('', $recipient['deliveredAt']);
		$this->assertSame(
			RecipientState::UNSUPPORTED,
			$recipient['readState'],
			'a read is never inferred from a delivery'
		);

	}//end testAReportedDeliveryIsRecorded()

	/**
	 * A receipt on a channel that cannot report one is refused, because a
	 * state the channel cannot produce is a state nobody can trust.
	 *
	 * @return void
	 */
	public function testAReceiptOnASilentChannelIsRefused(): void {
		$this->start('mail');
		$this->recorder->handedOver('message-uuid', 'jan@example.org');

		$this->expectException(RuntimeException::class);
		$this->recorder->deliveryReported('message-uuid', 'jan@example.org');

	}//end testAReceiptOnASilentChannelIsRefused()

	/**
	 * An external address is a recipient like any other, and no account is
	 * invented for it.
	 *
	 * @return void
	 */
	public function testAnExternalAddressIsAFirstClassRecipient(): void {
		$this->start();

		$byAddress = [];
		foreach ($this->stored['recipients'] as $recipient) {
			$byAddress[$recipient['address']] = $recipient;
		}

		$this->assertTrue($byAddress['gemachtigde@advocaat.example']['external']);
		$this->assertSame('', $byAddress['gemachtigde@advocaat.example']['accountId']);
		$this->assertFalse($byAddress['jan@example.org']['external']);
		$this->assertCount(3, $this->stored['recipients']);

	}//end testAnExternalAddressIsAFirstClassRecipient()

	/**
	 * A failure for one recipient leaves the others where they were.
	 *
	 * @return void
	 */
	public function testAFailedExternalAddressLeavesTheOthersAlone(): void {
		$this->start();
		$this->recorder->handedOver('message-uuid', 'jan@example.org');
		$this->recorder->handedOver('message-uuid', 'piet@example.org');

		$this->recorder->stepFailed(
			'message-uuid',
			'transport',
			'relay refused the address',
			['gemachtigde@advocaat.example']
		);

		$byAddress = [];
		foreach ($this->stored['recipients'] as $recipient) {
			$byAddress[$recipient['address']] = $recipient;
		}

		$this->assertSame(RecipientState::STATUS_SENT, $byAddress['jan@example.org']['status']);
		$this->assertSame(RecipientState::STATUS_SENT, $byAddress['piet@example.org']['status']);
		$this->assertSame(RecipientState::STATUS_FAILED, $byAddress['gemachtigde@advocaat.example']['status']);

	}//end testAFailedExternalAddressLeavesTheOthersAlone()

	/**
	 * A credential in the render context never reaches storage.
	 *
	 * @return void
	 */
	public function testSecretsAreRedactedBeforeTheWrite(): void {
		$this->recorder->start(
			'zaak/1',
			'mail',
			'Ontvangstbevestiging',
			"Beste meneer,\n\nAuthorization: Bearer abcdef1234567890\n\nMet vriendelijke groet",
			[['address' => 'jan@example.org', 'accountId' => 'jan']],
			['context' => ['apiKey' => 'sk-live-01234567', 'nested' => ['password' => 'hunter2'], 'naam' => 'Jan']]
		);

		$this->assertStringNotContainsString('abcdef1234567890', $this->stored['body']);
		$this->assertStringContainsString(BodyRedactor::PLACEHOLDER, $this->stored['body']);
		$this->assertSame(BodyRedactor::PLACEHOLDER, $this->stored['context']['apiKey']);
		$this->assertSame(BodyRedactor::PLACEHOLDER, $this->stored['context']['nested']['password']);
		$this->assertSame('Jan', $this->stored['context']['naam']);

	}//end testSecretsAreRedactedBeforeTheWrite()

	/**
	 * Recording an outcome against a recipient the message never had fails,
	 * rather than recording it against nobody.
	 *
	 * @return void
	 */
	public function testAnUnknownRecipientIsRefused(): void {
		$this->start();

		$this->expectException(RuntimeException::class);
		$this->recorder->handedOver('message-uuid', 'iemand-anders@example.org');

	}//end testAnUnknownRecipientIsRefused()

	/**
	 * Open a record with three recipients, one of them external.
	 *
	 * @param string $channel The channel to record it on.
	 *
	 * @return void
	 */
	private function start(string $channel = 'mail'): void {
		$this->recorder->start(
			'zaak/2026-0042',
			$channel,
			'Ontvangstbevestiging',
			'Wij hebben uw brief ontvangen.',
			[
				['address' => 'jan@example.org', 'name' => 'Jan Burger', 'accountId' => 'jan'],
				['address' => 'piet@example.org', 'name' => 'Piet Behandelaar', 'accountId' => 'piet'],
				['address' => 'gemachtigde@advocaat.example', 'name' => 'Gemachtigde'],
			],
			['sourceApp' => 'dossiq']
		);

	}//end start()

}//end class
