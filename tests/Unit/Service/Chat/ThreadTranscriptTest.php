<?php

/**
 * The plain text transcript of a fetched chat thread.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Chat;

use OCA\Integriq\Service\Chat\ThreadTranscript;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\Chat\ThreadTranscript
 */
class ThreadTranscriptTest extends TestCase {

	/**
	 * A thread of three messages, the middle one the hit.
	 *
	 * @return array<string,mixed> The thread.
	 */
	private function thread(): array {
		return [
			'platform' => 'nextcloud-talk',
			'conversation' => ['id' => 'abc123', 'name' => 'Project Stationsplein', 'kind' => 'group'],
			'participants' => [['id' => 'pjansen', 'label' => 'P. Jansen'], ['id' => 'rdevries', 'label' => 'R. de Vries']],
			'messages' => [
				['id' => '10', 'at' => '2025-03-03T09:00:00+00:00', 'actorLabel' => 'P. Jansen', 'text' => 'Is er al nieuws?', 'isHit' => false],
				['id' => '11', 'at' => '2025-03-03T09:05:00+00:00', 'actorLabel' => 'R. de Vries', 'text' => "offerte Stationsplein\nontvangen", 'isHit' => true],
				['id' => '12', 'at' => '2025-03-03T09:06:00+00:00', 'actorLabel' => 'P. Jansen', 'text' => 'Mooi', 'isHit' => false],
			],
		];
	}//end thread()

	public function testTheHitIsMarked(): void {
		$content = (new ThreadTranscript())->render(thread: $this->thread(), fetchedAt: '2026-11-17T10:00:00+00:00');

		self::assertSame(
			"Platform: nextcloud-talk\n"
			. "Conversation: Project Stationsplein (group)\n"
			. "Participants: P. Jansen, R. de Vries\n"
			. "Fetched at: 2026-11-17T10:00:00+00:00\n"
			. "\n"
			. "   [2025-03-03T09:00:00+00:00] P. Jansen: Is er al nieuws?\n"
			. ">> [2025-03-03T09:05:00+00:00] R. de Vries: offerte Stationsplein ontvangen\n"
			. "   [2025-03-03T09:06:00+00:00] P. Jansen: Mooi\n",
			$content
		);
	}//end testTheHitIsMarked()

	public function testTheAnswerIsAPlainTextFileCarryingTheThread(): void {
		$answer = (new ThreadTranscript())->answer(thread: $this->thread(), fetchedAt: '2026-11-17T10:00:00+00:00');

		self::assertSame('chat-abc123.txt', $answer['fileName']);
		self::assertSame('text/plain', $answer['mimeType']);
		self::assertSame($this->thread(), $answer['thread']);
		self::assertStringStartsWith("Platform: nextcloud-talk\n", $answer['content']);
	}//end testTheAnswerIsAPlainTextFileCarryingTheThread()

	public function testAConversationIdIsMadeSafeForAFileName(): void {
		$thread = $this->thread();
		$thread['conversation']['id'] = '19:a1b2c3@thread.v2';

		self::assertSame('chat-19-a1b2c3-thread-v2.txt', (new ThreadTranscript())->answer(thread: $thread, fetchedAt: 'now')['fileName']);
	}//end testAConversationIdIsMadeSafeForAFileName()
}//end class
