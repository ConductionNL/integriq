<?php

/**
 * Unit tests for NextcloudTablesEventListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\NextcloudTablesEventListener;
use OCA\Integriq\Service\EventService;
use OCA\Tables\Event\RowAddedEvent;
use OCA\Tables\Event\RowDeletedEvent;
use OCA\Tables\Event\RowUpdatedEvent;
use OCA\Tables\Model\Public\Row;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Class names/accessors verified against the public `nextcloud/tables`
 * source (not a live installed instance — see discovery.md).
 *
 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
 */
class NextcloudTablesEventListenerTest extends TestCase {

	/**
	 * TC-6: a RowUpdatedEvent produces a matching
	 * `com.nextcloud.tables.row.updated` CloudEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 */
	public function testRowUpdatedEventProducesMatchingCloudEvent(): void {
		$row = new Row(tableId: 3, rowId: 17, previousValues: [1 => 'old'], values: [1 => 'new']);

		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->expects($this->once())
			->method('handleNextcloudEvent')
			->with(
				'com.nextcloud.tables.row.updated',
				$this->callback(function (array $payload) {
					return $payload['source'] === '/nextcloud/tables'
						&& $payload['subject'] === '17'
						&& $payload['data']['tableId'] === 3
						&& $payload['data']['rowId'] === 17
						&& $payload['data']['values'] === [1 => 'new']
						&& $payload['data']['previousValues'] === [1 => 'old'];
				})
			);

		$listener = new NextcloudTablesEventListener($eventService, $this->createMock(LoggerInterface::class));
		$listener->handle(new RowUpdatedEvent($row));
	}//end testRowUpdatedEventProducesMatchingCloudEvent()

	/**
	 * RowAddedEvent/RowDeletedEvent produce the expected distinct types.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 */
	public function testAddedAndDeletedProduceDistinctTypes(): void {
		$row = new Row(tableId: 3, rowId: 18, values: [1 => 'x']);

		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$seen = [];
		$eventService->method('handleNextcloudEvent')->willReturnCallback(
			function (string $type, array $payload) use (&$seen) {
				$seen[] = $type;
				return [];
			}
		);

		$listener = new NextcloudTablesEventListener($eventService, $this->createMock(LoggerInterface::class));
		$listener->handle(new RowAddedEvent($row));
		$listener->handle(new RowDeletedEvent($row));

		$this->assertSame(['com.nextcloud.tables.row.created', 'com.nextcloud.tables.row.deleted'], $seen);
	}//end testAddedAndDeletedProduceDistinctTypes()

	/**
	 * TC-5: an unrelated event type is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 */
	public function testUnrelatedEventIsIgnored(): void {
		$eventService = $this->createMock(EventService::class);
		$eventService->expects($this->never())->method('handleNextcloudEvent');

		$listener = new NextcloudTablesEventListener($eventService, $this->createMock(LoggerInterface::class));
		$listener->handle(new Event());
	}//end testUnrelatedEventIsIgnored()

	/**
	 * Each row event sends exactly this payload under its type, and a failure
	 * is logged with the event's class and never thrown.
	 *
	 * @return void
	 */
	public function testThePayloadsAndTheLoggedFailure(): void {
		$sent = [];
		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$failure = new \RuntimeException('store down');
		$eventService->method('handleNextcloudEvent')->willReturnCallback(function (string $type, array $payload) use (&$sent, $failure): array {
			$sent[] = [$type, $payload];
			if (count($sent) === 3) {
				throw $failure;
			}

			return [];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Failed to process Nextcloud Tables row event: store down', ['exception' => $failure, 'event' => RowDeletedEvent::class]);
		$listener = new NextcloudTablesEventListener($eventService, $logger);

		$listener->handle(new RowAddedEvent(new Row(tableId: 1, rowId: 2)));
		$listener->handle(new RowUpdatedEvent(new Row(tableId: 1, rowId: 3, previousValues: ['a'], values: ['b'], dataByAlias: ['x' => 1])));
		$listener->handle(new RowDeletedEvent(new Row(tableId: 4, rowId: 5, previousValues: ['c'])));

		$payload = static fn (int $table, int $row, ?array $values, ?array $previous): array => [
			'source' => '/nextcloud/tables',
			'subject' => (string)$row,
			'data' => ['tableId' => $table, 'rowId' => $row, 'values' => $values, 'previousValues' => $previous],
		];
		$this->assertSame(
			[
				['com.nextcloud.tables.row.created', $payload(1, 2, null, null)],
				['com.nextcloud.tables.row.updated', $payload(1, 3, ['b'], ['a'])],
				['com.nextcloud.tables.row.deleted', $payload(4, 5, null, ['c'])],
			],
			$sent
		);

	}//end testThePayloadsAndTheLoggedFailure()
}//end class
