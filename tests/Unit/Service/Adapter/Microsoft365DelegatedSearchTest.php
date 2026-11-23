<?php

/**
 * Microsoft 365 mail and chat search with the person's delegated grant.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\Adapter\Saas\TeamsThreadReader;
use OCA\Integriq\Service\Credential\PersonalCredentialLookup;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter
 * @covers \OCA\Integriq\Service\Adapter\Saas\GraphDelegatedSearch
 */
class Microsoft365DelegatedSearchTest extends TestCase {

	/**
	 * The broker double.
	 *
	 * @var CredentialBrokerService&MockObject
	 */
	private CredentialBrokerService&MockObject $broker;

	/**
	 * The personal credential lookup double.
	 *
	 * @var PersonalCredentialLookup&MockObject
	 */
	private PersonalCredentialLookup&MockObject $grants;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->broker = $this->createMock(originalClassName: CredentialBrokerService::class);
		$this->grants = $this->createMock(originalClassName: PersonalCredentialLookup::class);
	}//end setUp()

	/**
	 * The adapter over the doubles; the source's application credential is `cred-app`.
	 *
	 * @return Microsoft365Adapter
	 */
	private function adapter(): Microsoft365Adapter {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => match ($key) {
				Microsoft365Adapter::SEARCH_REGION_KEY, Microsoft365Adapter::DELEGATED_PROVIDER_KEY => $default,
				default => 'cred-app',
			}
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$adapter = new Microsoft365Adapter(
			credentialBroker: $this->broker,
			appConfig: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			l10n: $l10n
		);
		$adapter->useDelegatedGrants(personalCredentials: $this->grants);

		return $adapter;
	}//end adapter()

	/**
	 * A recorded Graph answer.
	 *
	 * @param array<int, array<string, mixed>> $hits  The hits.
	 * @param int                              $total The total Graph reports.
	 *
	 * @return array{status: int, headers: array, body: string}
	 */
	private static function answer(array $hits, int $total): array {
		return [
			'status' => 200,
			'headers' => [],
			'body' => (string)json_encode(['value' => [['hitsContainers' => [['total' => $total, 'hits' => $hits]]]]]),
		];
	}//end answer()

	/**
	 * With a grant, files are searched with the application grant and mail and chat with the person's, in one request each.
	 *
	 * @return void
	 */
	public function testAPersonWithAGrantGetsFileMailAndChatHits(): void {
		$this->grants->expects(self::once())->method('credentialIdFor')
			->with(provider: 'microsoft-entra', userId: 'pjansen')->willReturn('cred-pjansen');
		$drive = self::answer(
			hits: [['hitId' => 'i1', 'resource' => ['id' => 'i1', 'name' => 'Besluit.pdf', 'parentReference' => ['driveId' => 'd1']]]],
			total: 1
		);
		$mail  = self::answer(hits: [
			['hitId' => 'm1', 'summary' => 'Over het <c0>Stationsplein</c0>', 'resource' => [
				'@odata.type' => '#microsoft.graph.message', 'id' => 'm1', 'subject' => 'Bezwaar Stationsplein',
				'receivedDateTime' => '2025-03-01T10:00:00Z', 'from' => ['emailAddress' => ['name' => 'A. de Vries']],
			]],
			['hitId' => 'c1', 'resource' => [
				'@odata.type' => '#microsoft.graph.chatMessage', 'id' => 'c1', 'chatId' => '19:abc@thread.v2',
				'createdDateTime' => '2025-03-02T10:00:00Z', 'body' => ['content' => '<p>Stationsplein planning</p>'],
				'from' => ['user' => ['displayName' => 'P. Jansen']],
			]],
			['hitId' => 'c2', 'resource' => [
				'@odata.type' => '#microsoft.graph.chatMessage', 'id' => 'c2', 'replyToId' => 'r1',
				'channelIdentity' => ['teamId' => 't1', 'channelId' => '19:ch@thread.tacv2'],
			]],
		], total: 3);

		$calls = [];
		$this->broker->expects(self::exactly(count: 2))->method('request')->willReturnCallback(
			static function (...$args) use (&$calls, $drive, $mail): array {
				$calls[] = $args;
				if (json_decode($args[5], true)['requests'][0]['entityTypes'] === ['driveItem']) {
					return $drive;
				}

				return $mail;
			}
		);

		$answer = $this->adapter()->search(terms: 'Stationsplein', from: '2025-01-01', to: null, entityTypes: [], limit: 50, userId: 'pjansen');

		$byType = [];
		foreach ($calls as $call) {
			$byType[implode(',', json_decode($call[5], true)['requests'][0]['entityTypes'])] = $call;
		}

		self::assertSame(expected: 'cred-app', actual: $byType['driveItem'][0], message: 'files are searched with the application grant');
		self::assertSame(expected: 'cred-pjansen', actual: $byType['message,chatMessage'][0], message: 'mail and chat with the person\'s grant');
		$delegatedBody = json_decode($byType['message,chatMessage'][5], true)['requests'][0];
		self::assertSame(expected: 'Stationsplein received>=2025-01-01', actual: $delegatedBody['query']['queryString']);
		self::assertArrayNotHasKey(key: 'region', array: $delegatedBody);

		self::assertSame(expected: [], actual: $answer['notices']);
		self::assertSame(expected: 0, actual: $answer['moreCount']);
		self::assertSame(
			expected: ['driveItem', 'message', 'chatMessage', 'chatMessage'],
			actual: array_column($answer['hits'], 'entityType')
		);
		[, $message, $chat, $channel] = $answer['hits'];
		self::assertSame(expected: 'message:m1', actual: $message['remoteId']);
		self::assertSame(expected: 'Bezwaar Stationsplein', actual: $message['title']);
		self::assertSame(expected: 'Over het Stationsplein', actual: $message['snippet']);
		self::assertSame(expected: 'A. de Vries', actual: $message['modifiedByLabel']);
		self::assertSame(expected: 'message/rfc822', actual: $message['mimeType']);
		self::assertSame(expected: TeamsThreadReader::HANDLE_PREFIX . 'chat:19%3Aabc%40thread.v2:c1', actual: $chat['remoteId']);
		self::assertSame(expected: 'Stationsplein planning', actual: $chat['title']);
		self::assertSame(expected: 'P. Jansen', actual: $chat['modifiedByLabel']);
		self::assertNull(actual: $chat['path']);
		self::assertSame(expected: TeamsThreadReader::HANDLE_PREFIX . 'channel:t1:19%3Ach%40thread.tacv2:r1:c2', actual: $channel['remoteId']);
		self::assertSame(expected: 't1/19:ch@thread.tacv2', actual: $channel['path']);
		self::assertTrue(condition: (new TeamsThreadReader())->isTeamsHandle(handle: $chat['remoteId']));
	}//end testAPersonWithAGrantGetsFileMailAndChatHits()

	/**
	 * Without a grant, only files are searched and mail and chat answer the notice: no delegated call is made.
	 *
	 * @return void
	 */
	public function testAPersonWithoutAGrantGetsFileHitsAndTheNotice(): void {
		$this->grants->method('credentialIdFor')->willReturn(null);
		$this->broker->expects(self::once())->method('request')
			->willReturn(self::answer(hits: [['hitId' => 'i1', 'resource' => ['id' => 'i1', 'name' => 'Besluit.pdf']]], total: 1));

		$answer = $this->adapter()->search(terms: 'Stationsplein', from: null, to: null, entityTypes: [], limit: 50, userId: 'pjansen');

		self::assertSame(expected: ['delegated-grant-missing'], actual: $answer['notices']);
		self::assertSame(expected: ['driveItem'], actual: array_column($answer['hits'], 'entityType'));
	}//end testAPersonWithoutAGrantGetsFileHitsAndTheNotice()

	/**
	 * The limit spans both searches; the rest is counted, and a refused mail search answers `not-permitted`.
	 *
	 * @return void
	 */
	public function testTheLimitSpansBothSearchesAndARefusalIsNamed(): void {
		$this->grants->method('credentialIdFor')->willReturn('cred-pjansen');
		$this->broker->method('request')->willReturnCallback(
			static function (...$args): array {
				if ($args[0] === 'cred-pjansen') {
					return ['status' => 403, 'headers' => [], 'body' => '{}'];
				}
				return self::answer(hits: [
					['hitId' => 'i1', 'resource' => ['id' => 'i1', 'name' => 'a.pdf']],
				], total: 4);
			}
		);

		$answer = $this->adapter()->search(terms: 'x', from: null, to: null, entityTypes: ['driveItem', 'message'], limit: 1, userId: 'pjansen');

		self::assertCount(expectedCount: 1, haystack: $answer['hits']);
		self::assertSame(expected: 3, actual: $answer['moreCount']);
		self::assertSame(expected: ['not-permitted'], actual: $answer['notices']);
	}//end testTheLimitSpansBothSearchesAndARefusalIsNamed()

	/**
	 * Only mail asked, with a grant: one delegated request for mail only, and no application search.
	 *
	 * @return void
	 */
	public function testOnlyMailAskedSearchesMailWithTheGrant(): void {
		$this->grants->method('credentialIdFor')->willReturn('cred-pjansen');
		$sent = null;
		$this->broker->expects(self::once())->method('request')->willReturnCallback(
			static function (...$args) use (&$sent): array {
				$sent = $args;
				return ['status' => 200, 'headers' => [], 'body' => (string)json_encode(['value' => [['hitsContainers' => [['hits' => [
					['resource' => ['@odata.type' => '#microsoft.graph.event', 'id' => 'e1']],
					['resource' => ['@odata.type' => '#microsoft.graph.message']],
				]]]]]])];
			}
		);

		$answer = $this->adapter()->search(terms: 'x', from: null, to: null, entityTypes: ['message'], limit: 10, userId: 'pjansen');

		self::assertSame(expected: 'cred-pjansen', actual: $sent[0]);
		self::assertSame(expected: ['message'], actual: json_decode($sent[5], true)['requests'][0]['entityTypes']);
		self::assertSame(expected: [], actual: $answer['hits'], message: 'a resource of another type or without an id is left out');
		self::assertSame(expected: [], actual: $answer['notices']);
	}//end testOnlyMailAskedSearchesMailWithTheGrant()

	/**
	 * A chat thread is read with the person's grant once they have one.
	 *
	 * @return void
	 */
	public function testAChatThreadIsReadWithThePersonsGrant(): void {
		$this->grants->method('credentialIdFor')->willReturn('cred-pjansen');
		$used = [];
		$this->broker->method('request')->willReturnCallback(
			static function (...$args) use (&$used): array {
				$used[] = $args[0];
				return ['status' => 404, 'headers' => [], 'body' => '{}'];
			}
		);

		$this->adapter()->fetchThread(handle: TeamsThreadReader::HANDLE_PREFIX . 'chat:c%3A1:m1', userId: 'pjansen', context: 2);

		self::assertNotSame(expected: [], actual: $used);
		self::assertSame(expected: ['cred-pjansen'], actual: array_values(array_unique($used)));
	}//end testAChatThreadIsReadWithThePersonsGrant()
}//end class
