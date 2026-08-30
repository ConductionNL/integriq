<?php

/**
 * Unit tests for NextcloudFileTagEventListener.
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
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\NextcloudFileTagEventListener;
use OCA\Integriq\Service\EventService;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\MapperEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class NextcloudFileTagEventListenerTest extends TestCase {

	/**
	 * TC-2: a file-tag assignment produces a distinctly-typed
	 * `com.nextcloud.files.node.tagged` event (distinct from create/update/
	 * delete), with resolved tag names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	public function testFileTagAssignmentProducesDistinctType(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('invoice');

		$tagManager = $this->createMock(ISystemTagManager::class);
		$tagManager->method('getTagsByIds')->with([7])->willReturn([$tag]);

		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->expects($this->once())
			->method('handleNextcloudEvent')
			->with(
				'com.nextcloud.files.node.tagged',
				$this->callback(function (array $payload) {
					return $payload['source'] === '/nextcloud/files'
						&& $payload['subject'] === '42'
						&& $payload['data']['fileid'] === '42'
						&& $payload['data']['action'] === 'assigned'
						&& $payload['data']['tagIds'] === [7]
						&& $payload['data']['tagNames'] === ['invoice'];
				})
			);

		$listener = new NextcloudFileTagEventListener($eventService, $tagManager, $this->createMock(LoggerInterface::class));
		$listener->handle(new MapperEvent(MapperEvent::EVENT_ASSIGN, 'files', '42', [7]));
	}//end testFileTagAssignmentProducesDistinctType()

	/**
	 * A tag event on a non-file object type is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	public function testNonFileObjectTypeIsIgnored(): void {
		$eventService = $this->createMock(EventService::class);
		$eventService->expects($this->never())->method('hasActiveSubscriptions');
		$eventService->expects($this->never())->method('handleNextcloudEvent');

		$listener = new NextcloudFileTagEventListener(
			$eventService,
			$this->createMock(ISystemTagManager::class),
			$this->createMock(LoggerInterface::class)
		);
		$listener->handle(new MapperEvent(MapperEvent::EVENT_ASSIGN, 'calendar-resource', '1', [1]));
	}//end testNonFileObjectTypeIsIgnored()

	/**
	 * When tag resolution fails (e.g. the tag was already deleted), the
	 * listener falls back to raw tag ids rather than throwing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	public function testTagResolutionFailureFallsBackToIds(): void {
		$tagManager = $this->createMock(ISystemTagManager::class);
		$tagManager->method('getTagsByIds')->willThrowException(new \RuntimeException('tag gone'));

		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->expects($this->once())
			->method('handleNextcloudEvent')
			->with(
				'com.nextcloud.files.node.tagged',
				$this->callback(fn (array $payload) => $payload['data']['tagNames'] === ['9'])
			);

		$listener = new NextcloudFileTagEventListener($eventService, $tagManager, $this->createMock(LoggerInterface::class));
		$listener->handle(new MapperEvent(MapperEvent::EVENT_UNASSIGN, 'files', '42', [9]));
	}//end testTagResolutionFailureFallsBackToIds()
}//end class
