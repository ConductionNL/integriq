<?php

/**
 * Nextcloud Talk as a document search source, as the requesting person.
 *
 * The tests that need Talk's own classes (Manager, Room, Participant,
 * Attendee, MessageSearch) double them only where Talk is installed in the
 * test container, and say so when they skip. The Talk-absent path runs
 * everywhere. The comments are read through the real OCP ICommentsManager
 * interface.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Chat;

use DateTime;
use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCA\Integriq\EventListener\DocumentSearchRequestedListener;
use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuardInterface;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365AdapterInterface;
use OCA\Integriq\Service\Chat\IntegriqSearchQuery;
use OCA\Integriq\Service\Chat\TalkMessageSource;
use OCP\App\IAppManager;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\Chat\TalkMessageSource
 * @covers \OCA\Integriq\Service\Chat\IntegriqSearchQuery
 * @covers \OCA\Integriq\Service\Chat\TalkSearchHits
 * @uses \OCA\Integriq\Service\Chat\ThreadTranscript
 * @uses \OCA\Integriq\EventListener\DocumentSearchRequestedListener
 * @uses \OCA\Integriq\Event\DocumentSearchRequestedEvent
 * @uses \OCA\Integriq\Event\DocumentConnectionRequestEvent
 */
class TalkMessageSourceTest extends TestCase {

	/**
	 * The conversation's numeric id, as Talk's comments carry it.
	 */
	private const ROOM_ID = 7;

	private IAppManager&MockObject $apps;

	private ContainerInterface&MockObject $container;

	private IUserManager&MockObject $users;

	private ICommentsManager&MockObject $comments;

	/**
	 * Talk's classes by name, as the container resolves them.
	 *
	 * @var array<string,object>
	 */
	private array $talk = [];

	protected function setUp(): void {
		$this->apps = $this->createMock(IAppManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(fn (string $id): object => $this->talk[$id]);
		$this->users = $this->createMock(IUserManager::class);
		$this->comments = $this->createMock(ICommentsManager::class);
	}//end setUp()

	/**
	 * The source over the doubles.
	 *
	 * @return TalkMessageSource The source.
	 */
	private function source(): TalkMessageSource {
		return new TalkMessageSource($this->apps, $this->container, $this->users, $this->comments);
	}//end source()

	/**
	 * Skip unless Talk's classes are installed in this test container.
	 *
	 * @return void
	 */
	private function requireTalk(): void {
		if (class_exists(TalkMessageSource::MANAGER_CLASS) === false || class_exists(TalkMessageSource::SEARCH_CLASS) === false) {
			self::markTestSkipped('Talk (spreed) is not installed in this test container; its classes are doubled only where it is.');
		}

		$this->apps->method('isEnabledForAnyone')->with('spreed')->willReturn(true);
	}//end requireTalk()

	/**
	 * A Talk chat message as the comments manager answers it.
	 *
	 * @param int    $id      The message id.
	 * @param string $actorId The author.
	 * @param string $text    The text.
	 * @param string $verb    The verb.
	 * @param int    $roomId  The conversation's id.
	 *
	 * @return IComment The comment.
	 */
	private function comment(int $id, string $actorId, string $text, string $verb = 'comment', int $roomId = self::ROOM_ID): IComment {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn((string)$id);
		$comment->method('getObjectType')->willReturn('chat');
		$comment->method('getObjectId')->willReturn((string)$roomId);
		$comment->method('getVerb')->willReturn($verb);
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getMessage')->willReturn($text);
		$comment->method('getCreationDateTime')->willReturn(new DateTime('2025-03-03T09:' . sprintf('%02d', $id - 30) . ':00+00:00'));
		$comment->method('getExpireDate')->willReturn(null);

		return $comment;
	}//end comment()

	/**
	 * Talk's room manager, room and participants for the conversation "Project Stationsplein".
	 *
	 * @param bool $participant Whether the officer is still a participant.
	 *
	 * @return void
	 */
	private function conversation(bool $participant = true): void {
		$room = $this->createMock(\OCA\Talk\Room::class);
		$room->method('getId')->willReturn(self::ROOM_ID);
		$room->method('getToken')->willReturn('abc123');
		$room->method('getType')->willReturn(2);
		$room->method('getDisplayName')->willReturn('Project Stationsplein');

		$manager = $this->createMock(\OCA\Talk\Manager::class);
		if ($participant === true) {
			$manager->expects(self::once())->method('getRoomForUserByToken')->with('abc123', 'pjansen')->willReturn($room);
		} else {
			$manager->expects(self::once())->method('getRoomForUserByToken')->with('abc123', 'pjansen')
				->willThrowException(new \OCA\Talk\Exceptions\RoomNotFoundException());
		}

		$attendees = [];
		foreach (['pjansen' => 'P. Jansen', 'rdevries' => 'R. de Vries'] as $actorId => $name) {
			$attendee = new \OCA\Talk\Model\Attendee();
			$attendee->setActorType('users');
			$attendee->setActorId($actorId);
			$attendee->setDisplayName($name);
			$attendees[] = new \OCA\Talk\Participant($room, $attendee, null);
		}

		$participants = $this->createMock(\OCA\Talk\Service\ParticipantService::class);
		$participants->method('getParticipantsForRoom')->with($room)->willReturn($attendees);

		$this->talk[TalkMessageSource::MANAGER_CLASS] = $manager;
		$this->talk[TalkMessageSource::PARTICIPANTS_CLASS] = $participants;
	}//end conversation()

	public function testATalkHitIsFoundAndFetchedWithItsThread(): void {
		$this->requireTalk();
		$user = $this->createMock(IUser::class);
		$this->users->method('get')->with('pjansen')->willReturn($user);
		$entry = new SearchResultEntry('', 'R. de Vries in Project Stationsplein', 'offerte Stationsplein ontvangen', 'https://cloud.example/call/abc123#message_42', 'icon-talk', true);
		foreach (['conversation' => 'abc123', 'messageId' => '42', 'actorType' => 'users', 'actorId' => 'rdevries', 'timestamp' => '1741000000'] as $key => $value) {
			$entry->addAttribute($key, $value);
		}

		$search = $this->createMock(\OCA\Talk\Search\MessageSearch::class);
		$search->expects(self::once())->method('search')->with(
			$user,
			self::callback(static fn (ISearchQuery $query): bool => $query->getTerm() === 'Stationsplein' && $query->getLimit() === 10 && $query->getFilter('conversation') === null)
		)->willReturn(SearchResult::complete('Messages', [$entry]));
		$this->talk[TalkMessageSource::SEARCH_CLASS] = $search;

		$found = $this->source()->search(search: new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'Stationsplein', '2025-01-01', '2025-12-31', [], 10));

		self::assertSame([], $found['notices']);
		self::assertCount(1, $found['hits']);
		$hit = $found['hits'][0];
		self::assertSame('nextcloud-talk', $hit['platform']);
		self::assertSame('chatMessage', $hit['entityType']);
		self::assertSame('chatMessage:talk:abc123:42', $hit['remoteId']);
		self::assertSame('abc123', $hit['conversationId']);
		self::assertSame('R. de Vries in Project Stationsplein', $hit['title']);
		self::assertSame('2025-03-03T11:06:40+00:00', $hit['modifiedAt']);

		// The fetch: participation first, then the hit and one read in each direction.
		$this->conversation();
		$order = [];
		$hitComment = $this->comment(id: 42, actorId: 'rdevries', text: 'offerte Stationsplein ontvangen');
		$this->comments->expects(self::once())->method('get')->with('42')->willReturnCallback(function () use (&$order, $hitComment): IComment {
			$order[] = 'get';
			return $hitComment;
		});
		$this->comments->expects(self::exactly(2))->method('getCommentsWithVerbForObjectSinceComment')->willReturnCallback(
			function (string $type, string $objectId, array $verbs, int $since, string $direction, int $limit, bool $includeLast) use (&$order): array {
				$order[] = $direction;
				self::assertSame(['chat', '7', ['comment', 'object_shared'], 42, 2, false], [$type, $objectId, $verbs, $since, $limit, $includeLast]);
				if ($direction === 'desc') {
					return [$this->comment(id: 41, actorId: 'pjansen', text: 'Is de offerte binnen?'), $this->comment(id: 40, actorId: 'pjansen', text: 'Goedemorgen')];
				}

				return [$this->comment(id: 43, actorId: 'pjansen', text: 'Mooi, dank')];
			}
		);

		$answer = $this->source()->fetch(handle: $hit['remoteId'], userId: 'pjansen', context: 2);

		self::assertSame(['get', 'desc', 'asc'], $order);
		$thread = $answer['thread'];
		self::assertSame(['id' => 'abc123', 'name' => 'Project Stationsplein', 'kind' => 'group'], $thread['conversation']);
		self::assertSame([['id' => 'pjansen', 'label' => 'P. Jansen'], ['id' => 'rdevries', 'label' => 'R. de Vries']], $thread['participants']);
		self::assertSame(['40', '41', '42', '43'], array_column($thread['messages'], 'id'));
		self::assertSame([false, false, true, false], array_column($thread['messages'], 'isHit'));
		self::assertSame('R. de Vries', $thread['messages'][2]['actorLabel']);
		self::assertStringContainsString('>> [2025-03-03T09:12:00+00:00] R. de Vries: offerte Stationsplein ontvangen', $answer['content']);
	}//end testATalkHitIsFoundAndFetchedWithItsThread()

	public function testALeftConversationIsRefused(): void {
		$this->requireTalk();
		$this->conversation(participant: false);
		$this->comments->expects(self::never())->method(self::anything());

		$answer = $this->source()->fetch(handle: 'chatMessage:talk:abc123:42', userId: 'pjansen', context: 5);

		self::assertSame(['error' => 'not-a-participant'], $answer);
	}//end testALeftConversationIsRefused()

	public function testAMessageOfAnotherConversationIsNotRead(): void {
		$this->requireTalk();
		$this->conversation();
		$this->comments->method('get')->willReturn($this->comment(id: 42, actorId: 'rdevries', text: 'elders', roomId: 99));
		$this->comments->expects(self::never())->method('getCommentsWithVerbForObjectSinceComment');

		self::assertNull($this->source()->fetch(handle: 'chatMessage:talk:abc123:42', userId: 'pjansen', context: 5));
	}//end testAMessageOfAnotherConversationIsNotRead()

	public function testASystemMessageHitIsNotAThread(): void {
		$this->requireTalk();
		$this->conversation();
		$this->comments->method('get')->willReturn($this->comment(id: 42, actorId: 'rdevries', text: '{actor} added {user}', verb: 'system'));

		self::assertNull($this->source()->fetch(handle: 'chatMessage:talk:abc123:42', userId: 'pjansen', context: 5));
	}//end testASystemMessageHitIsNotAThread()

	public function testAContextOfZeroReadsNoNeighbours(): void {
		$this->requireTalk();
		$this->conversation();
		$this->comments->method('get')->willReturn($this->comment(id: 42, actorId: 'rdevries', text: 'offerte'));
		// A limit of 0 means "all" to the comments manager: it must not be asked.
		$this->comments->expects(self::never())->method('getCommentsWithVerbForObjectSinceComment');

		$answer = $this->source()->fetch(handle: 'chatMessage:talk:abc123:42', userId: 'pjansen', context: 0);

		self::assertSame(['42'], array_column($answer['thread']['messages'], 'id'));
	}//end testAContextOfZeroReadsNoNeighbours()

	public function testAHitOutsideThePeriodIsLeftOut(): void {
		$this->requireTalk();
		$this->users->method('get')->willReturn($this->createMock(IUser::class));
		$entry = new SearchResultEntry('', 't', 's', 'u');
		foreach (['conversation' => 'abc123', 'messageId' => '42', 'timestamp' => '1741000000'] as $key => $value) {
			$entry->addAttribute($key, $value);
		}

		$search = $this->createMock(\OCA\Talk\Search\MessageSearch::class);
		$search->method('search')->willReturn(SearchResult::complete('Messages', [$entry]));
		$this->talk[TalkMessageSource::SEARCH_CLASS] = $search;

		self::assertSame([], $this->source()->search(search: new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x', '2025-03-04', null, [], 10))['hits']);
		self::assertSame([], $this->source()->search(search: new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x', null, '2025-03-02', [], 10))['hits']);
		self::assertCount(1, $this->source()->search(search: new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x', '2025-03-03', '2025-03-03', [], 10))['hits']);
	}//end testAHitOutsideThePeriodIsLeftOut()

	public function testTalkAbsentGivesANotice(): void {
		$this->apps->method('isEnabledForAnyone')->willReturn(false);
		$this->container->expects(self::never())->method('get');
		$this->comments->expects(self::never())->method(self::anything());

		self::assertSame(
			['hits' => [], 'moreCount' => 0, 'notices' => ['talk-not-installed']],
			$this->source()->search(search: new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'Stationsplein', null, null, [], 10))
		);
		self::assertSame(['error' => 'talk-not-installed'], $this->source()->fetch(handle: 'chatMessage:talk:abc123:42', userId: 'pjansen', context: 5));
		self::assertNull($this->source()->fetch(handle: 'chatMessage:talk:abc-123:42', userId: 'pjansen', context: 5));

		// Searching both platforms: the Teams hits come back beside the notice.
		$adapter = $this->createMock(Microsoft365AdapterInterface::class);
		$adapter->method('isConnected')->willReturn(true);
		$adapter->method('search')->willReturn(['hits' => [['remoteId' => 'driveItem:d1:i1']], 'moreCount' => 0, 'notices' => []]);
		$guard = $this->createMock(DocumentConnectionGuardInterface::class);
		$guard->method('isLinked')->willReturn(true);
		$event = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'Stationsplein');
		(new DocumentSearchRequestedListener($adapter, $guard, $this->createMock(LoggerInterface::class), $this->source()))->handle($event);

		self::assertSame(['hits' => [['remoteId' => 'driveItem:d1:i1']], 'moreCount' => 0, 'notices' => ['talk-not-installed']], $event->getResult());
	}//end testTalkAbsentGivesANotice()

	public function testThePlatformsArgumentChoosesTheSources(): void {
		$this->apps->method('isEnabledForAnyone')->willReturn(false);
		$adapter = $this->createMock(Microsoft365AdapterInterface::class);
		$adapter->method('isConnected')->willReturn(true);
		$adapter->expects(self::once())->method('search')->willReturn(['hits' => [['remoteId' => 'a'], ['remoteId' => 'b']], 'moreCount' => 1, 'notices' => []]);
		$guard = $this->createMock(DocumentConnectionGuardInterface::class);
		$guard->method('isLinked')->willReturn(true);
		$listener = new DocumentSearchRequestedListener($adapter, $guard, $this->createMock(LoggerInterface::class), $this->source());

		$talkOnly = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x', null, null, [], 50, ['nextcloud-talk']);
		$teamsOnly = new DocumentSearchRequestedEvent('dossiq', '', 'pjansen', 'x', null, null, [], 1, ['microsoft-teams']);
		$listener->handle($talkOnly);
		$listener->handle($teamsOnly);

		self::assertSame(['hits' => [], 'moreCount' => 0, 'notices' => ['talk-not-installed']], $talkOnly->getResult());
		// The limit holds across the merge; what is cut is counted.
		self::assertSame(['hits' => [['remoteId' => 'a']], 'moreCount' => 2, 'notices' => []], $teamsOnly->getResult());
		self::assertSame(['microsoft-teams'], $teamsOnly->getPlatforms());
		self::assertFalse($teamsOnly->wantsPlatform('nextcloud-talk'));
	}//end testThePlatformsArgumentChoosesTheSources()

	public function testTheQueryCarriesOnlyTermLimitAndCursor(): void {
		$query = new IntegriqSearchQuery(term: 'Stationsplein', limit: 20, cursor: 40);

		self::assertSame('Stationsplein', $query->getTerm());
		self::assertSame(20, $query->getLimit());
		self::assertSame(40, $query->getCursor());
		self::assertSame(ISearchQuery::SORT_DATE_DESC, $query->getSortOrder());
		self::assertNull($query->getFilter('since'));
		self::assertCount(0, $query->getFilters());
		self::assertFalse($query->getFilters()->has('term'));
		self::assertNull($query->getFilters()->get('term'));
		self::assertSame([], iterator_to_array($query->getFilters()));
		self::assertSame('', $query->getRoute());
		self::assertSame([], $query->getRouteParameters());
	}//end testTheQueryCarriesOnlyTermLimitAndCursor()
}//end class
