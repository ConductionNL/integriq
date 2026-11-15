<?php

/**
 * Unit tests for how the intake reply asks the send gate and records the outcome.
 *
 * The opt-out tables themselves are covered by IntakeReplyOptOutTest. Here
 * the gate is a double, so each answer it can give is pinned on its own: the
 * address the reply is asked for per channel, a refusal that stops the reply
 * before the channel is touched and is still written to the message, a send
 * the channel reports as failed, and a message that does not exist.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-a-direct-reply-to-a-citizen-s-message-passes-an-opt-out-req-ooa-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\ChannelCapabilities;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Outbound\OutboundSendGateInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The gate decision per channel, a refusal, a failed send and a missing message.
 *
 * @covers \OCA\Integriq\Intake\IntakeReplyService
 */
class IntakeReplyServiceGateTest extends TestCase {

	private const UUID = 'msg-1';

	/**
	 * The object the service saved last.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $saved = null;

	private OutboundSendGateInterface&MockObject $gate;

	protected function setUp(): void {
		parent::setUp();
		$this->gate = $this->createMock(OutboundSendGateInterface::class);
	}//end setUp()

	/**
	 * The address the gate is asked for follows the channel the message came in on.
	 *
	 * @return void
	 */
	public function testTheGateIsAskedForTheChannelsOwnAddress(): void {
		$cases = [
			'teams' => [['id' => ' 29:teams-user ', 'phone' => '0611111111'], ['teams', '29:teams-user']],
			'form' => [['address' => ' jan@example.nl ', 'phone' => '0611111111'], ['email', 'jan@example.nl']],
			'report' => [['address' => '', 'phone' => ' 0622222222 '], ['sms', '0622222222']],
		];

		foreach ($cases as $channelId => [$correspondent, $expected]) {
			$asked = [];
			$gate = $this->createMock(OutboundSendGateInterface::class);
			$gate->method('check')->willReturnCallback(
				static function (string $channel, string $category, string $address, array $options) use (&$asked): array {
					$asked = [$channel, $category, $address, $options];
					return ['send' => false, 'code' => 'refused', 'reason' => 'stop here'];
				}
			);

			$service = new IntakeReplyService(
				$this->objectService(object: $this->stored(channelId: $channelId, correspondent: $correspondent)),
				$this->registry(adapter: $this->adapter(channelId: $channelId)),
				$gate
			);
			$service->reply(self::UUID, 'Dank voor uw melding.');

			$this->assertSame(
				[$expected[0], 'reply', $expected[1], ['sourceApp' => 'integriq', 'correlationId' => self::UUID, 'inReplyTo' => self::UUID]],
				$asked,
				'channel ' . $channelId
			);
		}
	}//end testTheGateIsAskedForTheChannelsOwnAddress()

	/**
	 * A refusal is recorded at the gate and on the message, and the channel is never asked to send.
	 *
	 * @return void
	 */
	public function testARefusalStopsTheReplyAndIsRecorded(): void {
		$decision = ['send' => false, 'code' => 'address_unusable', 'reason' => 'no usable address'];
		$this->gate->method('check')->willReturn($decision);
		$this->gate->expects($this->once())
			->method('recordRefusal')
			->with('email', self::UUID, $decision, ['sourceApp' => 'integriq', 'correlationId' => self::UUID, 'inReplyTo' => self::UUID]);
		$this->gate->expects($this->never())->method('open');
		$adapter = $this->adapter(channelId: 'form');
		$adapter->expects($this->never())->method('reply');

		$stored = $this->stored(channelId: 'form', correspondent: ['address' => 'jan@example.nl']);
		$stored['replies'] = 'not a list';
		$result = (new IntakeReplyService($this->objectService(object: $stored), $this->registry(adapter: $adapter), $this->gate))
			->reply(self::UUID, 'Dank voor uw melding.');

		$this->assertSame(ReplyResult::STATUS_FAILED, $result->getStatus());
		$this->assertSame('address_unusable: no usable address', $result->getDetail());
		$this->assertCount(1, $this->saved['replies']);
		$reply = $this->saved['replies'][0];
		unset($reply['at']);
		$this->assertSame(
			['status' => 'failed', 'channelId' => 'form', 'reference' => '', 'detail' => 'address_unusable: no usable address', 'text' => 'Dank voor uw melding.'],
			$reply
		);
	}//end testARefusalStopsTheReplyAndIsRecorded()

	/**
	 * A send the channel reports as failed is closed at the gate as failed at the send step.
	 *
	 * @return void
	 */
	public function testASendTheChannelReportsFailedIsClosedAsFailed(): void {
		$decision = ['send' => true, 'address' => 'jan@example.nl', 'code' => '', 'reason' => ''];
		$this->gate->method('check')->willReturn($decision);
		$this->gate->method('compose')->with('Dank.', $decision, 'email')->willReturn(['body' => 'Dank.']);
		$this->gate->method('open')->willReturn('log-1');
		$this->gate->expects($this->never())->method('handedOver');
		$this->gate->expects($this->once())
			->method('failed')
			->with('log-1', 'jan@example.nl', OutboundSendGateInterface::STEP_SEND, 'gateway timeout');
		$adapter = $this->adapter(channelId: 'form');
		$adapter->expects($this->once())
			->method('reply')
			->with($this->isInstanceOf(InboundMessage::class), 'Dank.')
			->willReturn(ReplyResult::failed('form', 'gateway timeout'));

		$result = (new IntakeReplyService(
			$this->objectService(object: $this->stored(channelId: 'form', correspondent: ['address' => 'jan@example.nl'])),
			$this->registry(adapter: $adapter),
			$this->gate
		))->reply(self::UUID, 'Dank.');

		$this->assertSame('gateway timeout', $result->getDetail());
		$this->assertSame('failed', $this->saved['replies'][0]['status']);
	}//end testASendTheChannelReportsFailedIsClosedAsFailed()

	/**
	 * A message that does not exist is an intake channel error naming it.
	 *
	 * @return void
	 */
	public function testAMissingMessageIsAnIntakeChannelError(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->gate->expects($this->never())->method('check');

		$this->expectException(IntakeChannelException::class);
		$this->expectExceptionMessage('No inbound message "msg-404".');

		(new IntakeReplyService($objectService, $this->registry(adapter: $this->adapter(channelId: 'form')), $this->gate))
			->reply('msg-404', 'x');
	}//end testAMissingMessageIsAnIntakeChannelError()

	/**
	 * A lookup that finds nothing is the same error.
	 *
	 * @return void
	 */
	public function testALookupThatFindsNothingIsTheSameError(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willReturn(null);
		$this->gate->expects($this->never())->method('check');

		$this->expectException(IntakeChannelException::class);
		$this->expectExceptionMessage('No inbound message "msg-0".');

		(new IntakeReplyService($objectService, $this->registry(adapter: $this->adapter(channelId: 'form')), $this->gate))
			->reply('msg-0', 'x');
	}//end testALookupThatFindsNothingIsTheSameError()

	/**
	 * A stored inbound message.
	 *
	 * @param string              $channelId     The channel.
	 * @param array<string,mixed> $correspondent Who sent it.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $channelId, array $correspondent): array {
		return (new InboundMessage($channelId, 'ext-1', $correspondent, 'Er ligt afval op straat.'))->toObject();
	}//end stored()

	/**
	 * An object service that finds the message and captures the save.
	 *
	 * @param array<string,mixed> $object The stored message.
	 *
	 * @return MockObject
	 */
	private function objectService(array $object): MockObject {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')
			->with(self::UUID, IntakeRoutingService::REGISTER, IntakeRoutingService::SCHEMA_MESSAGE)
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, $object, self::UUID));
		$objectService->method('saveObject')->willReturnCallback(
			function (array $saved) {
				$this->saved = $saved;
				return ObjectServiceMockBuilder::objectEntity($this, $saved, self::UUID);
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * A reply-capable channel adapter.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return IntakeChannelAdapterInterface&MockObject
	 */
	private function adapter(string $channelId): IntakeChannelAdapterInterface {
		$adapter = $this->createMock(IntakeChannelAdapterInterface::class);
		$adapter->method('getChannelId')->willReturn($channelId);
		$adapter->method('describe')->willReturn(new ChannelCapabilities(channelId: $channelId, label: $channelId, canReply: true));

		return $adapter;
	}//end adapter()

	/**
	 * A registry holding one adapter.
	 *
	 * @param IntakeChannelAdapterInterface $adapter The adapter.
	 *
	 * @return IntakeChannelRegistry
	 */
	private function registry(IntakeChannelAdapterInterface $adapter): IntakeChannelRegistry {
		return new IntakeChannelRegistry(logger: new NullLogger(), adapters: [$adapter]);
	}//end registry()
}//end class
