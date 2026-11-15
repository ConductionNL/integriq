<?php

/**
 * Unit tests for OutboundSendGate's outbound log rows.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Identity\MessageComposer;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\OutboundSendGate;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The log rows never stop a send: a failure to write one is logged, not thrown.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
 */
#[CoversClass(OutboundSendGate::class)]
final class OutboundSendGateRecordingTest extends TestCase {

	/**
	 * @var MessageRecorder&MockObject
	 */
	private MessageRecorder $recorder;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the gate's collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->recorder = $this->createMock(MessageRecorder::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The gate under test.
	 *
	 * @return OutboundSendGate
	 */
	private function gate(): OutboundSendGate {
		return new OutboundSendGate(
			optOuts: $this->createMock(OptOutRegistry::class),
			composer: $this->createMock(MessageComposer::class),
			recorder: $this->recorder,
			logger: $this->logger
		);
	}//end gate()

	/**
	 * A row opened for a decided send records the opt-out category and case, replacing a non-array context.
	 *
	 * @return void
	 */
	public function testOpenRecordsTheDecisionInTheContext(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('row-1');
		$this->recorder->expects($this->once())->method('start')->with(
			'zaak/1',
			'mail',
			'Herinnering',
			'Body',
			[['address' => 'jan@example.org']],
			['caseRef' => 'case-9', 'context' => ['optOut' => ['category' => 'reminders', 'caseRef' => 'case-9']]]
		)->willReturn($entity);

		$uuid = $this->gate()->open(
			channel: 'mail',
			subjectRef: 'zaak/1',
			subject: 'Herinnering',
			body: 'Body',
			address: 'jan@example.org',
			options: ['caseRef' => 'case-9', 'context' => 'not an array'],
			decision: ['category' => 'reminders']
		);

		$this->assertSame('row-1', $uuid);
	}//end testOpenRecordsTheDecisionInTheContext()

	/**
	 * A row that cannot be opened is logged and answers null, so the send goes on.
	 *
	 * @return void
	 */
	public function testOpenThatFailsAnswersNullAndLogs(): void {
		$this->recorder->method('start')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[OutboundSendGate] the outbound log row could not be opened; the send is not affected',
			['channel' => 'mail', 'exception' => 'register down']
		);

		$this->assertNull($this->gate()->open(channel: 'mail', subjectRef: 'zaak/1', subject: 's', body: 'b', address: 'jan@example.org'));
	}//end testOpenThatFailsAnswersNullAndLogs()

	/**
	 * Without a row, a hand-over or failure records nothing.
	 *
	 * @return void
	 */
	public function testWithoutARowNothingIsRecorded(): void {
		$this->recorder->expects($this->never())->method('handedOver');
		$this->recorder->expects($this->never())->method('recipientFailed');
		$this->logger->expects($this->never())->method('warning');

		$gate = $this->gate();
		$gate->handedOver(uuid: null, address: 'jan@example.org', reference: 'MAIL-1');
		$gate->failed(uuid: null, address: 'jan@example.org', step: 'transport', reason: '550');
	}//end testWithoutARowNothingIsRecorded()

	/**
	 * A hand-over and a failure are recorded on the row; when that fails, it is logged and not thrown.
	 *
	 * @return void
	 */
	public function testHandOverAndFailureAreRecordedOrLogged(): void {
		$this->recorder->expects($this->once())->method('handedOver')->with('row-1', 'jan@example.org', 'MAIL-1')
			->willThrowException(new RuntimeException('no such recipient'));
		$this->recorder->expects($this->once())->method('recipientFailed')->with('row-1', 'jan@example.org', 'transport', '550')
			->willThrowException(new RuntimeException('register down'));
		$messages = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		$gate = $this->gate();
		$gate->handedOver(uuid: 'row-1', address: 'jan@example.org', reference: 'MAIL-1');
		$gate->failed(uuid: 'row-1', address: 'jan@example.org', step: 'transport', reason: '550');

		$this->assertSame(
			[
				['[OutboundSendGate] could not record the hand-over', ['uuid' => 'row-1', 'exception' => 'no such recipient']],
				['[OutboundSendGate] could not record the failure', ['uuid' => 'row-1', 'exception' => 'register down']],
			],
			$messages
		);
	}//end testHandOverAndFailureAreRecordedOrLogged()

	/**
	 * A refusal without an address is recorded under a placeholder, failed at the opt-out step with code and reason.
	 *
	 * @return void
	 */
	public function testARefusalIsRecordedAsAnOptOutFailure(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('row-2');
		$this->recorder->expects($this->once())->method('start')->with(
			'zaak/2',
			'sms',
			'',
			'',
			[['address' => '(invalid address)']],
			$this->anything()
		)->willReturn($entity);
		$this->recorder->expects($this->once())->method('recipientFailed')->with(
			'row-2',
			'(invalid address)',
			OutboundSendGate::STEP_OPT_OUT,
			'invalid-address: The address cannot be parsed.'
		);

		$uuid = $this->gate()->recordRefusal(
			channel: 'sms',
			subjectRef: 'zaak/2',
			decision: ['code' => 'invalid-address', 'reason' => 'The address cannot be parsed.', 'category' => 'service']
		);

		$this->assertSame('row-2', $uuid);
	}//end testARefusalIsRecordedAsAnOptOutFailure()
}//end class
