<?php

/**
 * Unit tests for OutboundRetryService's batch retry and its nothing-to-retry answer.
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

use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\OutboundRetryService;
use OCA\Integriq\Outbound\OutboundSendGate;
use OCA\Integriq\Outbound\RecipientState;
use OCA\Integriq\Outbound\RetryMessage;
use OCA\Integriq\Service\EventServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A batch retry reports each message, and one that cannot be read does not stop the others.
 *
 * @spec openspec/changes/outbound-communication-log/specs/outbound-message-log/spec.md
 */
#[CoversClass(OutboundRetryService::class)]
#[UsesClass(RetryMessage::class)]
final class OutboundRetryServiceBatchTest extends TestCase {

	/**
	 * A batch with an unreadable message and one with nothing failed: both count as failed, with their reasons.
	 *
	 * @return void
	 */
	public function testABatchReportsEveryMessageAndSurvivesAnUnreadableOne(): void {
		$recorder = $this->createMock(MessageRecorder::class);
		$recorder->method('read')->willReturnCallback(
			static function (string $uuid): array {
				if ($uuid === 'gone') {
					throw new DoesNotExistException('No outbound message "gone".');
				}

				return [
					'channel' => 'mail',
					'recipients' => [
						'not a row',
						['address' => 'jan@example.org', 'status' => RecipientState::STATUS_SENT],
					],
				];
			}
		);
		$recorder->expects($this->never())->method('appendAttempt');
		$events = $this->createMock(EventServiceInterface::class);
		$events->expects($this->never())->method($this->anything());
		$gate = $this->createMock(OutboundSendGate::class);
		$gate->expects($this->never())->method('check');

		$service = new OutboundRetryService(recorder: $recorder, eventService: $events, gate: $gate);
		$result  = $service->retryAll(uuids: ['gone', 'all-sent'], actorUid: 'admin');

		$this->assertSame(0, $result['succeeded']);
		$this->assertSame(2, $result['failed']);
		$this->assertSame(
			['message' => 'gone', 'retried' => [], 'succeeded' => false, 'detail' => 'No outbound message "gone".'],
			$result['items'][0]
		);
		$this->assertSame(
			[
				'message' => 'all-sent',
				'retried' => [],
				'skipped' => [],
				'succeeded' => false,
				'detail' => 'Nothing to retry: no recipient of this message is failed.',
			],
			$result['items'][1]
		);
	}//end testABatchReportsEveryMessageAndSurvivesAnUnreadableOne()
}//end class
