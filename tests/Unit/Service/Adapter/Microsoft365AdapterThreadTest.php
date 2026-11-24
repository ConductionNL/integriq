<?php

/**
 * Microsoft 365 chat threads.
 *
 * A Teams channel or chat hit fetched with its conversation, against recorded
 * Graph answers under tests/fixtures/graph/: the window around the hit, the
 * paging cap, the dropped system and deleted messages, and the refusal when
 * the person has no delegated grant.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter
 * @covers \OCA\Integriq\Service\Adapter\Saas\TeamsThreadReader
 * @uses \OCA\Integriq\Service\Adapter\Saas\GraphDriveSearch
 * @uses \OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider
 * @uses \OCA\Integriq\Service\Chat\ThreadTranscript
 */
class Microsoft365AdapterThreadTest extends TestCase {

	/**
	 * The delegated credential the subclass hands out.
	 */
	private const DELEGATED = 'cred-delegated-pjansen';

	/**
	 * The source's application credential, which a thread read must never use.
	 */
	private const APPLICATION = 'cred-application-m365';

	private CredentialBrokerService&MockObject $broker;

	/**
	 * Every path the broker was asked for, with the credential it was asked with.
	 *
	 * @var array<int,array{0: string, 1: string}>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->broker = $this->createMock(CredentialBrokerService::class);
		$this->calls = [];
	}//end setUp()

	/**
	 * The adapter, optionally with a delegated grant for the person.
	 *
	 * @param bool $delegated Whether the person has a delegated grant.
	 *
	 * @return Microsoft365Adapter The adapter.
	 */
	private function adapter(bool $delegated = true): Microsoft365Adapter {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(self::APPLICATION);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		if ($delegated === false) {
			return new Microsoft365Adapter(credentialBroker: $this->broker, appConfig: $config, logger: $logger, l10n: $l10n);
		}

		return new class($this->broker, $config, $logger, $l10n) extends Microsoft365Adapter {
			protected function delegatedCredentialId(string $userId): ?string {
				return ($userId === 'pjansen') ? 'cred-delegated-pjansen' : null;
			}
		};
	}//end adapter()

	/**
	 * Serve recorded answers by path, and record every call.
	 *
	 * @param array<string,mixed> $answers Decoded bodies by path.
	 *
	 * @return void
	 */
	private function serve(array $answers): void {
		$this->broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path) use ($answers): array {
				$this->calls[] = [$path, $credentialId];
				if (array_key_exists($path, $answers) === false) {
					return ['status' => 404, 'headers' => [], 'body' => '{}'];
				}

				return ['status' => 200, 'headers' => [], 'body' => (string)json_encode($answers[$path])];
			}
		);
	}//end serve()

	/**
	 * A recorded fixture.
	 *
	 * @param string $name The file name.
	 *
	 * @return array<string,mixed> The decoded fixture.
	 */
	private function fixture(string $name): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/graph/' . $name), true);
	}//end fixture()

	/**
	 * The recorded answers of a chat fixture, by path.
	 *
	 * @param array<string,mixed> $fixture The fixture.
	 *
	 * @return array<string,mixed> The answers.
	 */
	private function chatAnswers(array $fixture): array {
		$base = '/v1.0/chats/' . rawurlencode($fixture['chat']['id']);
		$answers = [
			$base . '?$select=id,topic,chatType' => $fixture['chat'],
			$base . '/members' => $fixture['members'],
			$base . '/messages?$top=50&$orderby=createdDateTime%20desc' => $fixture['pages'][0],
		];
		foreach ($fixture['pages'] as $index => $page) {
			if ($index > 0) {
				$answers['/v1.0/chats/' . $fixture['chat']['id'] . '/messages?$top=50&$skiptoken=page' . ($index + 1)] = $page;
			}
		}

		return $answers;
	}//end chatAnswers()

	public function testAChannelHitComesWithItsThread(): void {
		$fixture = $this->fixture('teams-channel-thread.json');
		$base = '/v1.0/teams/team-ruimte/channels/' . rawurlencode($fixture['channel']['id']);
		$root = $fixture['root']['id'];
		$this->serve([
			$base . '?$select=id,displayName' => $fixture['channel'],
			$base . '/messages/' . $root => $fixture['root'],
			$base . '/messages/' . $root . '/replies?$top=50' => $fixture['replies'],
			$base . '/members' => $fixture['members'],
		]);
		$handle = 'chatMessage:teams:channel:team-ruimte:' . rawurlencode($fixture['channel']['id']) . ':' . $root . ':' . $fixture['hitId'];

		$answer = $this->adapter()->fetchThread(handle: $handle, userId: 'pjansen', context: 5);

		$thread = $answer['thread'];
		self::assertSame('microsoft-teams', $thread['platform']);
		self::assertSame(['id' => '19:stationsplein@thread.tacv2', 'name' => 'Stationsplein', 'kind' => 'channel'], $thread['conversation']);
		self::assertSame(['P. Jansen', 'R. de Vries', 'S. Bakker'], array_column($thread['participants'], 'label'));
		// 8 before the hit and 3 after: the 5 nearest before, the hit, the 3 after, in time order.
		self::assertCount(9, $thread['messages']);
		self::assertSame(
			['1741000000004', '1741000000005', '1741000000006', '1741000000007', '1741000000008', '1741000000009', '1741000000010', '1741000000011', '1741000000012'],
			array_column($thread['messages'], 'id')
		);
		self::assertSame([false, false, false, false, false, true, false, false, false], array_column($thread['messages'], 'isHit'));
		self::assertSame('Stationsplein: planning aanbesteding, de offertes sluiten op 1 april', $thread['messages'][5]['text']);
		self::assertSame('P. Jansen', $thread['messages'][0]['actorLabel']);
		self::assertSame('2025-03-03T09:15:00Z', $thread['messages'][0]['at']);
		self::assertSame('text/plain', $answer['mimeType']);
		self::assertStringContainsString(">> [2025-03-03T09:40:00Z] S. Bakker: Stationsplein: planning aanbesteding", $answer['content']);
		self::assertSame([self::DELEGATED], array_values(array_unique(array_column($this->calls, 1))));
	}//end testAChannelHitComesWithItsThread()

	public function testAChannelRootPostIsHtmlDecoded(): void {
		$fixture = $this->fixture('teams-channel-thread.json');
		$base = '/v1.0/teams/team-ruimte/channels/' . rawurlencode($fixture['channel']['id']);
		$root = $fixture['root']['id'];
		$this->serve([
			$base . '?$select=id,displayName' => $fixture['channel'],
			$base . '/messages/' . $root => $fixture['root'],
			$base . '/messages/' . $root . '/replies?$top=50' => $fixture['replies'],
			$base . '/members' => $fixture['members'],
		]);
		$handle = 'chatMessage:teams:channel:team-ruimte:' . rawurlencode($fixture['channel']['id']) . ':' . $root . ':' . $root;

		$messages = $this->adapter()->fetchThread(handle: $handle, userId: 'pjansen', context: 2)['thread']['messages'];

		self::assertSame([$root, '1741000000002', '1741000000003'], array_column($messages, 'id'));
		self::assertSame('Stationsplein: planning aanbesteding & fasering', $messages[0]['text']);
		self::assertTrue($messages[0]['isHit']);
	}//end testAChannelRootPostIsHtmlDecoded()

	public function testAChatHitIsPagedAtMostFourTimes(): void {
		$fixture = $this->fixture('teams-chat-five-pages.json');
		$this->serve($this->chatAnswers(fixture: $fixture));
		$handle = 'chatMessage:teams:chat:' . rawurlencode($fixture['chat']['id']) . ':' . $fixture['hitId'];

		$thread = $this->adapter()->fetchThread(handle: $handle, userId: 'pjansen', context: 5)['thread'];

		$pages = array_filter(array_column($this->calls, 0), static fn (string $path): bool => str_contains($path, '/messages'));
		self::assertCount(4, $pages);
		self::assertNotContains('/v1.0/chats/' . $fixture['chat']['id'] . '/messages?$top=50&$skiptoken=page5', array_column($this->calls, 0));
		// The fifth page, with the older side, was never read: only what four pages hold comes back.
		self::assertSame(['m011', 'm012', 'm013', 'm014', 'm015', 'm016', 'm017', 'm018'], array_column($thread['messages'], 'id'));
		self::assertSame(['id' => '19:a1b2c3@thread.v2', 'name' => 'Overleg Stationsplein', 'kind' => 'group'], $thread['conversation']);
	}//end testAChatHitIsPagedAtMostFourTimes()

	public function testAChatStopsPagingOnceBothSidesAreRead(): void {
		$fixture = $this->fixture('teams-chat-five-pages.json');
		$this->serve($this->chatAnswers(fixture: $fixture));
		$handle = 'chatMessage:teams:chat:' . rawurlencode($fixture['chat']['id']) . ':m045';

		$thread = $this->adapter()->fetchThread(handle: $handle, userId: 'pjansen', context: 3)['thread'];

		$pages = array_filter(array_column($this->calls, 0), static fn (string $path): bool => str_contains($path, '/messages'));
		self::assertCount(1, $pages);
		self::assertSame(['m042', 'm043', 'm044', 'm045', 'm046', 'm047', 'm048'], array_column($thread['messages'], 'id'));
	}//end testAChatStopsPagingOnceBothSidesAreRead()

	public function testSystemAndDeletedMessagesAreDropped(): void {
		$fixture = $this->fixture('teams-chat-system-and-deleted.json');
		$this->serve($this->chatAnswers(fixture: $fixture));
		$handle = 'chatMessage:teams:chat:' . rawurlencode($fixture['chat']['id']) . ':c4';

		$thread = $this->adapter()->fetchThread(handle: $handle, userId: 'pjansen', context: 5)['thread'];

		self::assertSame(['c1', 'c2', 'c4', 'c6', 'c7'], array_column($thread['messages'], 'id'));
		self::assertSame('one-to-one', $thread['conversation']['kind']);
	}//end testSystemAndDeletedMessagesAreDropped()

	public function testADeletedHitIsNotAnswered(): void {
		$fixture = $this->fixture('teams-chat-system-and-deleted.json');
		$this->serve($this->chatAnswers(fixture: $fixture));
		$handle = 'chatMessage:teams:chat:' . rawurlencode($fixture['chat']['id']) . ':c5';

		self::assertNull($this->adapter()->fetchThread(handle: $handle, userId: 'pjansen', context: 5));
	}//end testADeletedHitIsNotAnswered()

	public function testWithoutADelegatedGrantNothingIsCalled(): void {
		$this->broker->expects(self::never())->method('request');

		$answer = $this->adapter(delegated: false)->fetchThread(handle: 'chatMessage:teams:chat:19%3Aa1b2c3%40thread.v2:m013', userId: 'pjansen', context: 5);

		self::assertSame(['error' => 'delegated-grant-missing'], $answer);
	}//end testWithoutADelegatedGrantNothingIsCalled()

	public function testARefusalAndAMalformedHandleAreAnswered(): void {
		$this->broker->method('request')->willReturn(['status' => 403, 'headers' => [], 'body' => '{}']);
		$adapter = $this->adapter();

		self::assertSame(['error' => 'not-permitted'], $adapter->fetchThread(handle: 'chatMessage:teams:chat:x:m1', userId: 'pjansen', context: 5));
		self::assertNull($adapter->fetchThread(handle: 'chatMessage:teams:chat:x', userId: 'pjansen', context: 5));
		self::assertNull($adapter->fetchThread(handle: 'chatMessage:teams:group:x:m1', userId: 'pjansen', context: 5));
		self::assertNull($adapter->fetchThread(handle: 'driveItem:d1:i1', userId: 'pjansen', context: 5));
	}//end testARefusalAndAMalformedHandleAreAnswered()

	public function testANextLinkToAnotherHostIsNotFollowed(): void {
		$fixture = $this->fixture('teams-chat-five-pages.json');
		$answers = $this->chatAnswers(fixture: $fixture);
		$first = '/v1.0/chats/' . rawurlencode($fixture['chat']['id']) . '/messages?$top=50&$orderby=createdDateTime%20desc';
		$answers[$first]['@odata.nextLink'] = 'https://graph.example.org/v1.0/chats/x/messages?page=2';
		$this->serve($answers);

		$this->adapter()->fetchThread(handle: 'chatMessage:teams:chat:' . rawurlencode($fixture['chat']['id']) . ':m013', userId: 'pjansen', context: 5);

		$pages = array_filter(array_column($this->calls, 0), static fn (string $path): bool => str_contains($path, '/messages'));
		self::assertCount(1, $pages);
	}//end testANextLinkToAnotherHostIsNotFollowed()
}//end class
