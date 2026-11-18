<?php

/**
 * The conversation around a Talk hit, read in a room the person is in.
 *
 * Talk's Room and ParticipantService are stood in for by small objects with
 * the methods this reader calls, so these tests run without Talk installed.
 * The comments are read through the real OCP ICommentsManager interface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Chat;

use DateTime;
use OCA\Integriq\Service\Chat\TalkMessageSource;
use OCA\Integriq\Service\Chat\TalkThreadReader;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\Chat\TalkThreadReader
 */
class TalkThreadReaderTest extends TestCase {

	/**
	 * The conversation's numeric id, as Talk's comments carry it.
	 */
	private const ROOM_ID = 7;

	private ContainerInterface&MockObject $container;

	private ICommentsManager&MockObject $comments;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->comments = $this->createMock(ICommentsManager::class);
	}//end setUp()

	/**
	 * A stand-in for Talk's Room.
	 *
	 * @param int $type Talk's room type.
	 *
	 * @return object The room.
	 */
	private function room(int $type = 2): object {
		return new class ($type) {
			public function __construct(private int $type) {
			}

			public function getId(): int {
				return 7;
			}

			public function getType(): int {
				return $this->type;
			}

			public function getDisplayName(string $userId): string {
				return 'Project Stationsplein for ' . $userId;
			}
		};
	}//end room()

	/**
	 * A stand-in for Talk's ParticipantService, with one attendee per actor.
	 *
	 * @param array<string,string> $names Display names by user id.
	 *
	 * @return object The service.
	 */
	private function participants(array $names): object {
		$list = [];
		foreach ($names as $actorId => $name) {
			$attendee = new class ($actorId, $name) {
				public function __construct(private string $actorId, private string $name) {
				}

				public function getActorType(): string {
					return 'users';
				}

				public function getActorId(): string {
					return $this->actorId;
				}

				public function getDisplayName(): string {
					return $this->name;
				}
			};
			$list[] = new class ($attendee) {
				public function __construct(private object $attendee) {
				}

				public function getAttendee(): object {
					return $this->attendee;
				}
			};
		}

		return new class ($list) {
			public function __construct(private array $list) {
			}

			public function getParticipantsForRoom(object $room): array {
				return $this->list;
			}
		};
	}//end participants()

	/**
	 * A Talk chat comment.
	 *
	 * @param int    $id      The comment id.
	 * @param string $actorId The author.
	 * @param string $verb    The verb.
	 * @param int    $roomId  The conversation's id.
	 *
	 * @return IComment The comment.
	 */
	private function comment(int $id, string $actorId, string $verb = 'comment', int $roomId = self::ROOM_ID): IComment {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn((string)$id);
		$comment->method('getObjectType')->willReturn('chat');
		$comment->method('getObjectId')->willReturn((string)$roomId);
		$comment->method('getVerb')->willReturn($verb);
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getMessage')->willReturn('message ' . $id);
		$comment->method('getCreationDateTime')->willReturn(new DateTime('2025-03-03T09:' . sprintf('%02d', $id - 30) . ':00+00:00'));
		$comment->method('getExpireDate')->willReturn(null);

		return $comment;
	}//end comment()

	private function reader(): TalkThreadReader {
		return new TalkThreadReader(container: $this->container, comments: $this->comments);
	}//end reader()

	public function testTheHitComesWithItsNeighboursInTimeOrder(): void {
		$this->container->method('get')->with(TalkMessageSource::PARTICIPANTS_CLASS)
			->willReturn($this->participants(names: ['pjansen' => 'P. Jansen', 'rdevries' => 'R. de Vries']));
		$this->comments->expects(self::once())->method('get')->with('42')->willReturn($this->comment(id: 42, actorId: 'pjansen'));
		$this->comments->expects(self::exactly(2))->method('getCommentsWithVerbForObjectSinceComment')
			->willReturnCallback(function (string $type, string $objectId, array $verbs, int $since, string $direction, int $limit, bool $includeLast): array {
				self::assertSame(['chat', '7', TalkMessageSource::MESSAGE_VERBS, 42, 2, false], [$type, $objectId, $verbs, $since, $limit, $includeLast]);
				if ($direction === 'desc') {
					return [$this->comment(id: 41, actorId: 'rdevries'), $this->comment(id: 40, actorId: 'rdevries', verb: 'system')];
				}

				return [$this->comment(id: 43, actorId: 'gast')];
			});

		$thread = $this->reader()->read(room: $this->room(), token: 'abc123', messageId: '42', userId: 'pjansen', context: 2);

		self::assertSame('nextcloud-talk', $thread['platform']);
		self::assertSame(['id' => 'abc123', 'name' => 'Project Stationsplein for pjansen', 'kind' => 'group'], $thread['conversation']);
		self::assertSame([['id' => 'pjansen', 'label' => 'P. Jansen'], ['id' => 'rdevries', 'label' => 'R. de Vries']], $thread['participants']);
		self::assertSame(['41', '42', '43'], array_column($thread['messages'], 'id'));
		self::assertSame([false, true, false], array_column($thread['messages'], 'isHit'));
		self::assertSame(['R. de Vries', 'P. Jansen', 'gast'], array_column($thread['messages'], 'actorLabel'));
		self::assertSame('2025-03-03T09:12:00+00:00', $thread['messages'][1]['at']);
	}//end testTheHitComesWithItsNeighboursInTimeOrder()

	public function testAMessageOfAnotherRoomIsNotRead(): void {
		$this->comments->method('get')->with('42')->willReturn($this->comment(id: 42, actorId: 'pjansen', roomId: 8));
		$this->comments->expects(self::never())->method('getCommentsWithVerbForObjectSinceComment');
		$this->container->expects(self::never())->method('get');

		self::assertNull($this->reader()->read(room: $this->room(), token: 'abc123', messageId: '42', userId: 'pjansen', context: 2));
	}//end testAMessageOfAnotherRoomIsNotRead()

	public function testASystemMessageOrAnUnknownCommentIsNoThread(): void {
		$this->comments->method('get')->willReturnCallback(function (string $id): IComment {
			if ($id === '42') {
				return $this->comment(id: 42, actorId: 'pjansen', verb: 'system');
			}

			throw new RuntimeException('not found');
		});

		self::assertNull($this->reader()->read(room: $this->room(), token: 'abc123', messageId: '42', userId: 'pjansen', context: 2));
		self::assertNull($this->reader()->read(room: $this->room(), token: 'abc123', messageId: '99', userId: 'pjansen', context: 2));
	}//end testASystemMessageOrAnUnknownCommentIsNoThread()

	public function testAContextOfZeroReadsOnlyTheHitAndAOneToOneKind(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no participant service'));
		$this->comments->method('get')->willReturn($this->comment(id: 42, actorId: 'pjansen'));
		$this->comments->expects(self::never())->method('getCommentsWithVerbForObjectSinceComment');

		$thread = $this->reader()->read(room: $this->room(type: 1), token: 'abc123', messageId: '42', userId: 'pjansen', context: 0);

		self::assertSame('one-to-one', $thread['conversation']['kind']);
		self::assertSame([], $thread['participants']);
		self::assertSame([['id' => '42', 'at' => '2025-03-03T09:12:00+00:00', 'actorLabel' => 'pjansen', 'text' => 'message 42', 'isHit' => true]], $thread['messages']);
	}//end testAContextOfZeroReadsOnlyTheHitAndAOneToOneKind()
}//end class
