<?php

/**
 * Unit tests for the MessageRecorder steps, receipts and retry attempts.
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
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Steps, read receipts, retry attempts and the record's normalisation.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md#requirement-every-outbound-message-is-recorded-per-recipient-and-per-step-req-ocl-001
 */
#[CoversClass(MessageRecorder::class)]
#[CoversClass(ChannelReportingCapabilities::class)]
#[UsesClass(BodyRedactor::class)]
final class MessageRecorderLifecycleTest extends TestCase {

	/**
	 * The OR object service double.
	 *
	 * @var ORObjectService&MockObject
	 */
	private $objectService;

	/**
	 * The record as it currently stands, which the double reads and writes.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $stored = [];

	/**
	 * The uuids each save was given.
	 *
	 * @var list<string|null>
	 */
	private array $savedUuids = [];

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

		$this->objectService = $this->createMock(ORObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$this->stored = $object;
				$this->savedUuids[] = ($rest['uuid'] ?? $rest[2] ?? null);
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'message-uuid');
			}
		);
		$this->objectService->method('find')->willReturnCallback(
			function () {
				if ($this->stored === null) {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, $this->stored, 'message-uuid');
			}
		);

		$this->recorder = new MessageRecorder($this->objectService, new BodyRedactor(), new ChannelReportingCapabilities());
	}//end setUp()

	/**
	 * A started record carries its context, attachment metadata and only the usable recipients.
	 *
	 * @return void
	 */
	public function testStartNormalisesRecipientsAndAttachments(): void {
		$this->recorder->start(
			'zaak/1',
			'digitalPost',
			'Besluit',
			'Uw besluit staat klaar.',
			['jan@example.org', ['address' => '  '], 42, ['address' => 'piet@example.org', 'name' => 'Piet']],
			[
				'context' => 'not an array',
				'correlationId' => 'corr-1',
				'attachments' => [['name' => 'besluit.pdf', 'mime' => 'application/pdf', 'size' => '1200', 'fileRef' => 'f-1'], 'skip', []],
			]
		);

		$this->assertSame(['jan@example.org', 'piet@example.org'], array_column($this->stored['recipients'], 'address'));
		$this->assertSame(RecipientState::NOT_REPORTED, $this->stored['recipients'][0]['readState'], 'digital post reports reads');
		$this->assertSame([], $this->stored['context']);
		$this->assertSame('corr-1', $this->stored['correlationId']);
		$this->assertSame(
			[
				['name' => 'besluit.pdf', 'mime' => 'application/pdf', 'size' => 1200, 'fileRef' => 'f-1'],
				['name' => 'bijlage', 'mime' => 'application/octet-stream', 'size' => 0, 'fileRef' => ''],
			],
			$this->stored['attachments']
		);
		$this->assertSame(MessageRecorder::STATUS_PENDING, $this->stored['status']);
		$this->assertSame([null], $this->savedUuids, 'a new record is saved without a uuid');
	}//end testStartNormalisesRecipientsAndAttachments()

	/**
	 * A succeeded step is appended and the record is written under its uuid.
	 *
	 * @return void
	 */
	public function testStepSucceededAppendsTheStep(): void {
		$this->start(channel: 'mail');

		$this->recorder->stepSucceeded('message-uuid', 'transport', 'queued at the relay');

		$last = end($this->stored['steps']);
		$this->assertSame(['transport', MessageRecorder::OUTCOME_SUCCEEDED, 'queued at the relay'], [$last['step'], $last['outcome'], $last['detail']]);
		$this->assertSame('message-uuid', end($this->savedUuids));
	}//end testStepSucceededAppendsTheStep()

	/**
	 * A failed step for named recipients leaves the others, and one already sent, alone.
	 *
	 * @return void
	 */
	public function testStepFailedForNamedRecipientsSkipsTheOthersAndTheSent(): void {
		$this->start(channel: 'mail');
		$this->recorder->handedOver('message-uuid', 'jan@example.org', 'MAIL-1');

		$this->recorder->stepFailed('message-uuid', 'transport', 'relay down', ['jan@example.org', 'piet@example.org']);

		$byAddress = array_column($this->stored['recipients'], null, 'address');
		$this->assertSame(RecipientState::STATUS_SENT, $byAddress['jan@example.org']['status']);
		$this->assertSame(RecipientState::STATUS_FAILED, $byAddress['piet@example.org']['status']);
		$this->assertSame('relay down', $byAddress['piet@example.org']['reason']);
		$this->assertSame(RecipientState::STATUS_PENDING, (string)($byAddress['gemachtigde@advocaat.example']['status'] ?? RecipientState::STATUS_PENDING));
		$this->assertSame(MessageRecorder::STATUS_PENDING, $this->stored['status'], 'one recipient is still open');
	}//end testStepFailedForNamedRecipientsSkipsTheOthersAndTheSent()

	/**
	 * A read receipt on a channel that reports reads is recorded on that recipient.
	 *
	 * @return void
	 */
	public function testAReadReceiptIsRecorded(): void {
		$this->start(channel: 'digitalPost');

		$this->recorder->readReported('message-uuid', 'piet@example.org', 'opened in the inbox');

		$byAddress = array_column($this->stored['recipients'], null, 'address');
		$this->assertSame(RecipientState::REPORTED, $byAddress['piet@example.org']['readState']);
		$this->assertSame('opened in the inbox', $byAddress['piet@example.org']['readDetail']);
		$this->assertNotEmpty($byAddress['piet@example.org']['readAt']);
		$this->assertSame(RecipientState::NOT_REPORTED, $byAddress['jan@example.org']['readState']);
	}//end testAReadReceiptIsRecorded()

	/**
	 * A read receipt on a channel that cannot report reads is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAReadReceiptOnAChannelWithoutReadsIsRefused(): void {
		$this->start(channel: 'berichtenbox');
		$writes = count($this->savedUuids);

		try {
			$this->recorder->readReported('message-uuid', 'jan@example.org');
			$this->fail('a read receipt was accepted on berichtenbox');
		} catch (RuntimeException $exception) {
			$this->assertSame('Channel "berichtenbox" cannot report reads, so this receipt is not its.', $exception->getMessage());
		}

		$this->assertCount($writes, $this->savedUuids);
	}//end testAReadReceiptOnAChannelWithoutReadsIsRefused()

	/**
	 * A successful retry attempt marks its recipients sent and counts the attempt.
	 *
	 * @return void
	 */
	public function testASuccessfulAttemptMarksItsRecipientsSent(): void {
		$this->start(channel: 'mail');
		$this->recorder->recipientFailed('message-uuid', 'jan@example.org', 'transport', '451 try later');
		$this->stored['attempts'] = 'corrupt';

		$this->recorder->appendAttempt('message-uuid', 'admin', ['jan@example.org'], true, 'resent');

		$this->assertSame(1, $this->stored['retryCount'], 'a corrupt attempt list starts over');
		$this->assertSame(['by' => 'admin', 'recipients' => ['jan@example.org'], 'outcome' => MessageRecorder::OUTCOME_SUCCEEDED, 'detail' => 'resent'], array_diff_key($this->stored['attempts'][0], ['at' => true]));
		$byAddress = array_column($this->stored['recipients'], null, 'address');
		$this->assertSame(RecipientState::STATUS_SENT, $byAddress['jan@example.org']['status']);
		$this->assertSame(['', ''], [$byAddress['jan@example.org']['failedStep'], $byAddress['jan@example.org']['reason']]);
		$this->assertNotSame(RecipientState::STATUS_SENT, $byAddress['piet@example.org']['status'] ?? null, 'a recipient not in the attempt is untouched');
		$this->assertEmpty($byAddress['piet@example.org']['handedOverAt'] ?? null);
	}//end testASuccessfulAttemptMarksItsRecipientsSent()

	/**
	 * A failed retry attempt marks its recipients failed with the detail, and the status adds up.
	 *
	 * @return void
	 */
	public function testAFailedAttemptMarksItsRecipientsFailed(): void {
		$this->start(channel: 'mail');
		$this->recorder->handedOver('message-uuid', 'jan@example.org');
		$this->recorder->handedOver('message-uuid', 'piet@example.org');

		$this->recorder->appendAttempt('message-uuid', 'admin', ['gemachtigde@advocaat.example'], false, '550 unknown user');
		$this->recorder->appendAttempt('message-uuid', 'admin', ['gemachtigde@advocaat.example'], false, '550 again');

		$this->assertSame(2, $this->stored['retryCount']);
		$this->assertSame(MessageRecorder::OUTCOME_FAILED, $this->stored['attempts'][1]['outcome']);
		$byAddress = array_column($this->stored['recipients'], null, 'address');
		$this->assertSame([RecipientState::STATUS_FAILED, '550 again'], [$byAddress['gemachtigde@advocaat.example']['status'], $byAddress['gemachtigde@advocaat.example']['reason']]);
		$this->assertSame(MessageRecorder::STATUS_PARTIAL, $this->stored['status']);
	}//end testAFailedAttemptMarksItsRecipientsFailed()

	/**
	 * Reading an unknown record throws; a stored record without recipient or step lists reads as empty lists.
	 *
	 * @return void
	 */
	public function testReadNormalisesOrRefuses(): void {
		$this->stored = ['channel' => 'mail', 'recipients' => 'x', 'steps' => null];
		$record = $this->recorder->read('message-uuid');
		$this->assertSame([[], []], [$record['recipients'], $record['steps']]);

		$this->stored = null;
		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage('No outbound message "gone-uuid".');
		$this->recorder->read('gone-uuid');
	}//end testReadNormalisesOrRefuses()

	/**
	 * The record status follows its recipients.
	 *
	 * @return void
	 */
	public function testDeriveStatus(): void {
		$sent   = ['status' => RecipientState::STATUS_SENT];
		$failed = ['status' => RecipientState::STATUS_FAILED];

		$this->assertSame(MessageRecorder::STATUS_PENDING, $this->recorder->deriveStatus([]));
		$this->assertSame(MessageRecorder::STATUS_PENDING, $this->recorder->deriveStatus([$sent, []]));
		$this->assertSame(MessageRecorder::STATUS_SENT, $this->recorder->deriveStatus([$sent, $sent]));
		$this->assertSame(MessageRecorder::STATUS_FAILED, $this->recorder->deriveStatus([$failed, $failed]));
		$this->assertSame(MessageRecorder::STATUS_PARTIAL, $this->recorder->deriveStatus([$sent, $failed]));
	}//end testDeriveStatus()

	/**
	 * The capabilities list every declared channel, and an unknown channel reports nothing.
	 *
	 * @return void
	 */
	public function testTheCapabilitiesDescribeEveryChannel(): void {
		$capabilities = new ChannelReportingCapabilities();
		$described    = array_column($capabilities->describeAll(), null, 'channel');

		$this->assertSame(['channel' => 'digitalPost', 'delivery' => true, 'read' => true], $described['digitalPost']);
		$this->assertSame(['channel' => 'berichtenbox', 'delivery' => true, 'read' => false], $described['berichtenbox']);
		$this->assertSame(['channel' => 'log', 'delivery' => false, 'read' => false], $described['log']);
		$this->assertSame(RecipientState::UNSUPPORTED, $capabilities->initialDeliveryState('carrier-pigeon'));
		$this->assertSame(RecipientState::UNSUPPORTED, $capabilities->initialReadState('carrier-pigeon'));
	}//end testTheCapabilitiesDescribeEveryChannel()

	/**
	 * Start a record with three recipients on a channel.
	 *
	 * @param string $channel The channel.
	 *
	 * @return void
	 */
	private function start(string $channel): void {
		$this->recorder->start(
			'zaak/2026-0042',
			$channel,
			'Ontvangstbevestiging',
			'Wij hebben uw brief ontvangen.',
			[
				['address' => 'jan@example.org', 'name' => 'Jan Burger'],
				['address' => 'piet@example.org', 'name' => 'Piet Behandelaar'],
				['address' => 'gemachtigde@advocaat.example', 'name' => 'Gemachtigde'],
			],
			['sourceApp' => 'dossiq']
		);
	}//end start()
}//end class
