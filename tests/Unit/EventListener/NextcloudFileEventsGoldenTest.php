<?php

/**
 * Golden tests for the Nextcloud file and file-tag listeners: the exact
 * CloudEvent per event, the gates, and the failure lines.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\NextcloudFileEventListener;
use OCA\Integriq\EventListener\NextcloudFileTagEventListener;
use OCA\Integriq\Service\EventServiceInterface;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeTouchedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;
use OCP\IUser;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\MapperEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what both listeners forward, skip and log.
 */
class NextcloudFileEventsGoldenTest extends TestCase {

	/**
	 * A node, owned or not.
	 *
	 * @param string|null $owner The owner uid, or null.
	 *
	 * @return Node
	 */
	private function node(?string $owner): Node {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(42);
		$node->method('getPath')->willReturn('/a/b.txt');
		$node->method('getMimetype')->willReturn('text/plain');
		$user = null;
		if ($owner !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($owner);
		}

		$node->method('getOwner')->willReturn($user);

		return $node;

	}//end node()

	/**
	 * Every node event forwards exactly this CloudEvent; a touch is ignored.
	 *
	 * @return void
	 */
	public function testFileEventsForwardTheirPayload(): void {
		$sent = [];
		$events = $this->createMock(EventServiceInterface::class);
		$events->method('hasActiveSubscriptions')->willReturn(true);
		$events->method('handleNextcloudEvent')->willReturnCallback(
			function (string $type, array $payload) use (&$sent): array {
				$sent[] = [$type, $payload];
				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());
		$listener = new NextcloudFileEventListener($events, $logger);

		$listener->handle(new NodeCreatedEvent($this->node('alice')));
		$listener->handle(new NodeWrittenEvent($this->node(null)));
		$listener->handle(new NodeDeletedEvent($this->node('bob')));
		$listener->handle(new NodeTouchedEvent($this->node('carol')));

		$payload = static fn (?string $owner): array => [
			'source' => '/nextcloud/files',
			'subject' => '42',
			'data' => ['path' => '/a/b.txt', 'fileid' => 42, 'mimetype' => 'text/plain', 'owner' => $owner],
			'userId' => $owner,
		];
		$this->assertSame(
			[
				['com.nextcloud.files.node.created', $payload('alice')],
				['com.nextcloud.files.node.updated', $payload(null)],
				['com.nextcloud.files.node.deleted', $payload('bob')],
			],
			$sent
		);

	}//end testFileEventsForwardTheirPayload()

	/**
	 * A failing file forward is logged with the event class.
	 *
	 * @return void
	 */
	public function testFileFailureIsLogged(): void {
		$failure = new RuntimeException('down');
		$events = $this->createMock(EventServiceInterface::class);
		$events->method('hasActiveSubscriptions')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Failed to process Nextcloud file event: down',
			['exception' => $failure, 'event' => NodeDeletedEvent::class]
		);

		(new NextcloudFileEventListener($events, $logger))->handle(new NodeDeletedEvent($this->node('x')));

	}//end testFileFailureIsLogged()

	/**
	 * Tag assignments and unassignments forward their payload; others are ignored before the gate.
	 *
	 * @return void
	 */
	public function testTagEventsForwardTheirPayload(): void {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn('invoice');
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getTagsByIds')->willReturn([$tag]);
		$sent = [];
		$gates = 0;
		$events = $this->createMock(EventServiceInterface::class);
		$events->method('hasActiveSubscriptions')->willReturnCallback(
			function () use (&$gates): bool {
				$gates++;
				return true;
			}
		);
		$events->method('handleNextcloudEvent')->willReturnCallback(
			function (string $type, array $payload) use (&$sent): array {
				$sent[] = [$type, $payload];
				return [];
			}
		);
		$listener = new NextcloudFileTagEventListener($events, $tags, $this->createMock(LoggerInterface::class));

		$listener->handle(new MapperEvent(MapperEvent::EVENT_UNASSIGN, 'files', '7', [3]));
		$listener->handle(new MapperEvent(MapperEvent::EVENT_ASSIGN, 'files', '8', [3]));
		$listener->handle(new MapperEvent(MapperEvent::EVENT_ASSIGN, 'calendar', '9', [3]));
		$listener->handle(new MapperEvent('other', 'files', '9', [3]));

		$this->assertSame(2, $gates);
		$this->assertSame(
			[
				['com.nextcloud.files.node.tagged', ['source' => '/nextcloud/files', 'subject' => '7', 'data' => ['fileid' => '7', 'action' => 'unassigned', 'tagIds' => [3], 'tagNames' => ['invoice']]]],
				['com.nextcloud.files.node.tagged', ['source' => '/nextcloud/files', 'subject' => '8', 'data' => ['fileid' => '8', 'action' => 'assigned', 'tagIds' => [3], 'tagNames' => ['invoice']]]],
			],
			$sent
		);

	}//end testTagEventsForwardTheirPayload()

	/**
	 * Without subscriptions a tag event forwards nothing; a failure is logged.
	 *
	 * @return void
	 */
	public function testTagGateAndFailure(): void {
		$failure = new RuntimeException('tags gone');
		$tags = $this->createMock(ISystemTagManager::class);
		$events = $this->createMock(EventServiceInterface::class);
		$events->method('hasActiveSubscriptions')->willReturnOnConsecutiveCalls(false, true);
		$events->method('handleNextcloudEvent')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Failed to process Nextcloud file tag event: tags gone',
			['exception' => $failure, 'event' => MapperEvent::class]
		);
		$listener = new NextcloudFileTagEventListener($events, $tags, $logger);

		$listener->handle(new MapperEvent(MapperEvent::EVENT_ASSIGN, 'files', '1', []));
		$listener->handle(new MapperEvent(MapperEvent::EVENT_ASSIGN, 'files', '1', []));

	}//end testTagGateAndFailure()
}//end class
