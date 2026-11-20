<?php

/**
 * The document fetch of a chat hit: the context limit, the routing of a Talk
 * and a Teams handle, and the dispatched event reaching the thread fetch
 * through the listener wiring the app registers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\DocumentFetchRequestedEvent;
use OCA\Integriq\EventListener\DocumentFetchRequestedListener;
use OCA\Integriq\EventListener\EventListenerBindings;
use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuardInterface;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365AdapterInterface;
use OCA\Integriq\Service\Chat\TalkMessageSource;
use OCA\Integriq\Tests\Helpers\ServiceListenerDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\EventListener\DocumentFetchRequestedListener
 * @covers \OCA\Integriq\Event\DocumentFetchRequestedEvent
 * @uses \OCA\Integriq\Event\DocumentConnectionRequestEvent
 * @uses \OCA\Integriq\EventListener\EventListenerBindings
 */
class DocumentFetchRequestedListenerTest extends TestCase {

	private Microsoft365AdapterInterface&MockObject $adapter;

	private TalkMessageSource&MockObject $talk;

	protected function setUp(): void {
		$this->adapter = $this->createMock(Microsoft365AdapterInterface::class);
		$this->talk = $this->createMock(TalkMessageSource::class);
		$this->talk->method('isTalkHandle')->willReturnCallback(static fn (string $handle): bool => str_starts_with($handle, TalkMessageSource::HANDLE_PREFIX));
	}//end setUp()

	/**
	 * The listener with a guard that answers whether the app is linked.
	 *
	 * @param bool $linked The guard's answer.
	 *
	 * @return DocumentFetchRequestedListener The listener.
	 */
	private function listener(bool $linked = true): DocumentFetchRequestedListener {
		$guard = $this->createMock(DocumentConnectionGuardInterface::class);
		$guard->method('isLinked')->willReturn($linked);

		return new DocumentFetchRequestedListener($this->adapter, $guard, $this->createMock(LoggerInterface::class), $this->talk);
	}//end listener()

	/**
	 * A thread answer as a source gives it.
	 *
	 * @param string $platform The platform.
	 *
	 * @return array<string,mixed> The answer.
	 */
	private function threadAnswer(string $platform): array {
		return [
			'fileName' => 'chat-x.txt',
			'mimeType' => 'text/plain',
			'content' => "Platform: $platform\n",
			'thread' => ['platform' => $platform, 'conversation' => ['id' => 'x', 'name' => 'X', 'kind' => 'group'], 'participants' => [], 'messages' => []],
		];
	}//end threadAnswer()

	public function testAContextAboveTheMaximumIsRefused(): void {
		$this->adapter->expects(self::never())->method('fetchThread');
		$this->adapter->expects(self::never())->method('fetch');
		$this->talk->expects(self::never())->method('fetch');

		$event = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'chatMessage:teams:chat:x:m1', 100);
		$this->listener()->handle($event);

		self::assertSame(['error' => 'context-too-large', 'maximum' => 25], $event->getResult());
	}//end testAContextAboveTheMaximumIsRefused()

	public function testTheMaximumItselfIsAccepted(): void {
		$this->adapter->expects(self::once())->method('fetchThread')
			->with('chatMessage:teams:chat:x:m1', 'pjansen', 25)
			->willReturn($this->threadAnswer(platform: 'microsoft-teams'));

		$event = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'chatMessage:teams:chat:x:m1', 25);
		$this->listener()->handle($event);

		self::assertSame('microsoft-teams', $event->getResult()['thread']['platform']);
	}//end testTheMaximumItselfIsAccepted()

	public function testTheDefaultContextIsFiveAndANegativeOneReadsNoNeighbours(): void {
		$this->talk->expects(self::exactly(2))->method('fetch')
			->willReturnCallback(fn (string $handle, string $userId, int $context): array => $this->threadAnswer(platform: 'nextcloud-talk') + ['context' => $context]);

		$default = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'chatMessage:talk:abc123:42');
		$negative = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'chatMessage:talk:abc123:42', -3);
		$this->listener()->handle($default);
		$this->listener()->handle($negative);

		self::assertSame(5, $default->getContext());
		self::assertSame(5, $default->getResult()['context']);
		self::assertSame(0, $negative->getResult()['context']);
	}//end testTheDefaultContextIsFiveAndANegativeOneReadsNoNeighbours()

	public function testAnUnlinkedAppGetsNoThread(): void {
		$this->adapter->expects(self::never())->method('fetchThread');
		$this->talk->expects(self::never())->method('fetch');

		$event = new DocumentFetchRequestedEvent('rogue-app', '', 'pjansen', 'chatMessage:talk:abc123:42', 100);
		$this->listener(linked: false)->handle($event);

		self::assertFalse($event->isHandled());
	}//end testAnUnlinkedAppGetsNoThread()

	public function testARefusalIsHandedOver(): void {
		$this->talk->method('fetch')->willReturn(['error' => 'not-a-participant']);
		$this->adapter->expects(self::never())->method('fetch');

		$event = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'chatMessage:talk:abc123:42');
		$this->listener()->handle($event);

		self::assertSame(['error' => 'not-a-participant'], $event->getResult());
	}//end testARefusalIsHandedOver()

	public function testTheDispatchedEventReachesTheThreadFetch(): void {
		$this->adapter->method('fetchThread')->willReturnCallback(
			fn (string $handle): ?array => ($handle === 'chatMessage:teams:chat:x:m1') ? $this->threadAnswer(platform: 'microsoft-teams') : null
		);
		$this->adapter->method('fetch')->willReturn(['fileName' => 'a.docx', 'mimeType' => 'application/msword', 'content' => 'BYTES']);
		$this->talk->method('fetch')->willReturn($this->threadAnswer(platform: 'nextcloud-talk'));
		$dispatcher = new ServiceListenerDispatcher([DocumentFetchRequestedListener::class => $this->listener()]);
		(new EventListenerBindings())->registerListeners($dispatcher);

		$teams = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'chatMessage:teams:chat:x:m1', 3);
		$talk = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'chatMessage:talk:abc123:42');
		$drive = new DocumentFetchRequestedEvent('dossiq', '', 'pjansen', 'driveItem:d1:i1');
		foreach ([$teams, $talk, $drive] as $event) {
			$dispatcher->dispatchTyped($event);
		}

		self::assertSame('microsoft-teams', $teams->getResult()['thread']['platform']);
		self::assertSame('nextcloud-talk', $talk->getResult()['thread']['platform']);
		self::assertArrayNotHasKey('thread', $drive->getResult());
		self::assertSame('BYTES', $drive->getResult()['content']);
	}//end testTheDispatchedEventReachesTheThreadFetch()
}//end class
