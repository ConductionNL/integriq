<?php

/**
 * Microsoft 365 document search and fetch.
 *
 * The adapter's Graph `/search/query` call against a recorded answer, the
 * notices for mail and chat without a delegated grant and for a refusal, and
 * the fetch of a drive item by its handle.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\PersonalCredentialLookup;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter
 * @covers \OCA\Integriq\Service\Adapter\Saas\GraphDriveSearch
 * @covers \OCA\Integriq\Service\Adapter\Saas\GraphDelegatedSearch
 * @uses   \OCA\Integriq\Service\Adapter\Saas\DriveSearchQuery
 */
class Microsoft365DocumentSearchTest extends TestCase {

	private CredentialBrokerService&MockObject $broker;

	private string $credential = 'cred-uuid-m365';

	/**
	 * The person's own connected accounts the lookup double answers, or null for no lookup.
	 *
	 * @var list<string>|null
	 */
	private ?array $ownAccounts = null;

	/**
	 * The provider the lookup was asked for, and for whom.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $lookups = [];

	/**
	 * The adapter over the broker double.
	 *
	 * @return Microsoft365Adapter The adapter.
	 */
	private function adapter(): Microsoft365Adapter {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => match ($key) {
				Microsoft365Adapter::SEARCH_REGION_KEY, Microsoft365Adapter::DELEGATED_PROVIDER_KEY => $default,
				default => $this->credential,
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$lookup = null;
		if ($this->ownAccounts !== null) {
			$lookup = $this->createMock(PersonalCredentialLookup::class);
			$lookup->method('credentialIds')->willReturnCallback(
				function (string $provider, string $userId): array {
					$this->lookups[] = [$provider, $userId];
					return $this->ownAccounts;
				}
			);
		}

		return new Microsoft365Adapter(
			credentialBroker: $this->broker,
			appConfig: $config,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
			personalCredentials: $lookup
		);
	}//end adapter()

	protected function setUp(): void {
		$this->broker = $this->createMock(CredentialBrokerService::class);
	}//end setUp()

	public function testASearchAnswersDriveHitsWithHandlesAndCountsTheRest(): void {
		$recorded = ['value' => [['hitsContainers' => [[
			'total' => 3,
			'moreResultsAvailable' => true,
			'hits' => [
				['hitId' => 'i1', 'summary' => 'Besluit over de <c0>Stationsplein</c0> herinrichting', 'resource' => [
					'id' => 'i1', 'name' => 'Raadsvoorstel Stationsplein.docx', 'size' => 2048, 'webUrl' => 'https://zuiddrecht.sharepoint.com/sites/ruimte/doc.docx',
					'lastModifiedDateTime' => '2025-05-01T09:00:00Z', 'lastModifiedBy' => ['user' => ['displayName' => 'P. Jansen']],
					'file' => ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
					'parentReference' => ['driveId' => 'd1', 'path' => '/drives/d1/root:/Ruimte'],
				]],
			],
		]]]]];
		$sent = null;
		$this->broker->expects(self::once())->method('request')->willReturnCallback(
			function (...$args) use (&$sent, $recorded): array {
				$sent = $args;
				return ['status' => 200, 'headers' => [], 'body' => json_encode($recorded)];
			}
		);

		$answer = $this->adapter()->search(terms: 'Stationsplein', from: '2025-01-01', to: '2026-06-30', entityTypes: [], limit: 1, userId: 'pjansen');

		self::assertSame('POST', $sent[2]);
		self::assertSame('/v1.0/search/query', $sent[3]);
		$body = json_decode($sent[5], true);
		self::assertSame(['driveItem'], $body['requests'][0]['entityTypes']);
		self::assertSame('Stationsplein LastModifiedTime>=2025-01-01 LastModifiedTime<=2026-06-30', $body['requests'][0]['query']['queryString']);
		self::assertSame('EUR', $body['requests'][0]['region']);
		self::assertSame(1, $body['requests'][0]['size']);

		self::assertCount(1, $answer['hits']);
		self::assertSame(2, $answer['moreCount']);
		self::assertSame(['delegated-grant-missing'], $answer['notices']);
		$hit = $answer['hits'][0];
		self::assertSame('driveItem:d1:i1', $hit['remoteId']);
		self::assertSame('Raadsvoorstel Stationsplein.docx', $hit['title']);
		self::assertSame('/drives/d1/root:/Ruimte', $hit['path']);
		self::assertSame('Besluit over de Stationsplein herinrichting', $hit['snippet']);
		self::assertSame('P. Jansen', $hit['modifiedByLabel']);
		self::assertSame(2048, $hit['sizeBytes']);
		self::assertSame('driveItem', $hit['entityType']);
		self::assertSame('microsoft-365', $hit['sourceSlug']);
	}//end testASearchAnswersDriveHitsWithHandlesAndCountsTheRest()

	public function testMailAndChatOnlyAnswerTheMissingGrantNotice(): void {
		$this->broker->expects(self::never())->method('request');

		$answer = $this->adapter()->search(terms: 'x', from: null, to: null, entityTypes: ['message', 'chatMessage'], limit: 50, userId: 'pjansen');

		self::assertSame(['hits' => [], 'moreCount' => 0, 'notices' => ['delegated-grant-missing']], $answer);
	}//end testMailAndChatOnlyAnswerTheMissingGrantNotice()

	public function testARefusalByMicrosoft365AnswersNotPermitted(): void {
		$this->broker->method('request')->willReturn(['status' => 403, 'headers' => [], 'body' => '{}']);

		$answer = $this->adapter()->search(terms: 'x', from: null, to: null, entityTypes: ['driveItem'], limit: 50, userId: 'pjansen');

		self::assertSame(['not-permitted'], $answer['notices']);
		self::assertSame([], $answer['hits']);
	}//end testARefusalByMicrosoft365AnswersNotPermitted()

	public function testAFetchAnswersTheDriveItemAndRefusesOtherHandles(): void {
		$this->broker->method('request')->willReturnCallback(
			static function (...$args): array {
				if (str_ends_with($args[3], '/content') === true) {
					return ['status' => 200, 'headers' => [], 'body' => 'BYTES'];
				}
				return ['status' => 200, 'headers' => [], 'body' => json_encode(['name' => 'raadsvoorstel.docx', 'file' => ['mimeType' => 'application/msword']])];
			}
		);
		$adapter = $this->adapter();

		self::assertSame(
			['fileName' => 'raadsvoorstel.docx', 'mimeType' => 'application/msword', 'content' => 'BYTES'],
			$adapter->fetch(handle: 'driveItem:d1:i1', userId: 'pjansen')
		);
		self::assertNull($adapter->fetch(handle: 'message:m1', userId: 'pjansen'));
		self::assertNull($adapter->fetch(handle: 'driveItem::i1', userId: 'pjansen'));
		self::assertNull($adapter->fetch(handle: 'driveItem:d1:..', userId: 'pjansen'), 'a dot segment would climb out of /items/');
		self::assertNull($adapter->fetch(handle: 'driveItem:..:i1', userId: 'pjansen'));
	}//end testAFetchAnswersTheDriveItemAndRefusesOtherHandles()

	/**
	 * A recorded Graph `/search/query` answer.
	 *
	 * @param array<int, array<string, mixed>> $hits  The hits.
	 * @param int                              $total The total Graph reports.
	 *
	 * @return array{status: int, headers: array<string, mixed>, body: string} The broker answer.
	 */
	private static function recorded(array $hits, int $total): array {
		return ['status' => 200, 'headers' => [], 'body' => (string)json_encode(['value' => [['hitsContainers' => [['total' => $total, 'hits' => $hits]]]]])];
	}//end recorded()

	public function testWithTheOwnGrantFilesMailAndChatComeBackAsThreeHits(): void {
		$this->ownAccounts = ['cred-own-pjansen'];
		$calls = [];
		$this->broker->method('request')->willReturnCallback(
			function (...$args) use (&$calls): array {
				$calls[] = $args;
				$type = json_decode($args[5], true)['requests'][0]['entityTypes'][0];
				return match ($type) {
					'driveItem' => self::recorded(hits: [['hitId' => 'i1', 'summary' => 'over <c0>Stationsplein</c0>', 'resource' => [
						'id' => 'i1', 'name' => 'Raadsvoorstel.docx', 'parentReference' => ['driveId' => 'd1', 'path' => '/drives/d1/root:/Ruimte'],
					]]], total: 1),
					'message' => self::recorded(hits: [['hitId' => 'AAMk=1', 'summary' => 'Planning <c0>Stationsplein</c0>', 'resource' => [
						'id' => 'AAMk=1', 'subject' => 'Planning Stationsplein', 'receivedDateTime' => '2025-03-02T10:00:00Z',
						'from' => ['emailAddress' => ['name' => 'A. de Vries', 'address' => 'a.devries@example.nl']], 'webLink' => 'https://outlook.office365.com/owa/?ItemID=AAMk',
					]]], total: 4),
					'chatMessage' => self::recorded(hits: [['hitId' => 'c1', 'summary' => 'Is het <c0>Stationsplein</c0> klaar?', 'resource' => [
						'id' => '1712', 'chatId' => '19:abc@thread.v2', 'createdDateTime' => '2025-04-01T08:00:00Z',
						'from' => ['emailAddress' => ['name' => 'B. Smit']],
					]]], total: 1),
				};
			}
		);

		$answer = $this->adapter()->search(terms: 'Stationsplein', from: '2025-01-01', to: '2026-06-30', entityTypes: [], limit: 50, userId: 'pjansen');

		self::assertSame([['microsoft-entra', 'pjansen']], $this->lookups, 'the person\'s own account at the default provider');
		self::assertCount(3, $calls);
		self::assertSame('cred-uuid-m365', $calls[0][0], 'files run on the source\'s application grant');
		self::assertSame('cred-own-pjansen', $calls[1][0], 'mail runs on the person\'s own grant');
		self::assertSame('cred-own-pjansen', $calls[2][0], 'chat runs on the person\'s own grant');
		$mailRequest = json_decode($calls[1][5], true)['requests'][0];
		self::assertSame('Stationsplein received>=2025-01-01 received<=2026-06-30', $mailRequest['query']['queryString']);
		self::assertArrayNotHasKey('region', $mailRequest, 'a delegated search sends no region');
		self::assertSame('Stationsplein sent>=2025-01-01 sent<=2026-06-30', json_decode($calls[2][5], true)['requests'][0]['query']['queryString']);

		self::assertSame([], $answer['notices']);
		self::assertSame(['driveItem', 'message', 'chatMessage'], array_column($answer['hits'], 'entityType'));
		self::assertSame(['driveItem:d1:i1', 'message:AAMk%3D1', 'chatMessage:19%3Aabc%40thread.v2:1712'], array_column($answer['hits'], 'remoteId'));
		self::assertSame(3, $answer['moreCount'], 'the mail container reports three more than it sent');
		$mail = $answer['hits'][1];
		self::assertSame('Planning Stationsplein', $mail['title']);
		self::assertSame('A. de Vries', $mail['modifiedByLabel']);
		self::assertSame('2025-03-02T10:00:00Z', $mail['modifiedAt']);
		self::assertSame('Planning Stationsplein', $mail['snippet']);
		self::assertSame('Mail', $mail['path']);
		self::assertSame('Is het Stationsplein klaar?', $answer['hits'][2]['snippet']);
		self::assertSame('Teams', $answer['hits'][2]['path']);
	}//end testWithTheOwnGrantFilesMailAndChatComeBackAsThreeHits()

	public function testWithoutTheOwnGrantFilesComeBackWithTheNotice(): void {
		$this->ownAccounts = [];
		$this->broker->expects(self::once())->method('request')->willReturn(
			self::recorded(hits: [['hitId' => 'i1', 'resource' => ['id' => 'i1', 'name' => 'a.pdf', 'parentReference' => ['driveId' => 'd1']]]], total: 1)
		);

		$answer = $this->adapter()->search(terms: 'Stationsplein', from: null, to: null, entityTypes: [], limit: 50, userId: 'pjansen');

		self::assertSame(['driveItem:d1:i1'], array_column($answer['hits'], 'remoteId'));
		self::assertSame(['delegated-grant-missing'], $answer['notices']);
	}//end testWithoutTheOwnGrantFilesComeBackWithTheNotice()

	public function testTwoOwnAccountsAreNeverGuessedBetween(): void {
		$this->ownAccounts = ['cred-a', 'cred-b'];
		$this->broker->expects(self::never())->method('request');

		$answer = $this->adapter()->search(terms: 'x', from: null, to: null, entityTypes: ['message'], limit: 50, userId: 'pjansen');

		self::assertSame(['hits' => [], 'moreCount' => 0, 'notices' => ['delegated-grant-ambiguous']], $answer);
	}//end testTwoOwnAccountsAreNeverGuessedBetween()

	public function testTheLimitHoldsAcrossTypesAndARefusedTypeSaysNotPermitted(): void {
		$this->ownAccounts = ['cred-own'];
		$this->broker->method('request')->willReturnCallback(
			static function (...$args): array {
				$type = json_decode($args[5], true)['requests'][0]['entityTypes'][0];
				if ($type === 'chatMessage') {
					return ['status' => 403, 'headers' => [], 'body' => '{}'];
				}
				return self::recorded(hits: [['resource' => ['id' => 'm1', 'subject' => 'Een']], ['resource' => ['id' => 'm2', 'subject' => 'Twee']]], total: 2);
			}
		);

		$answer = $this->adapter()->search(terms: 'x', from: null, to: null, entityTypes: ['message', 'chatMessage'], limit: 1, userId: 'pjansen');

		self::assertSame(['message:m1'], array_column($answer['hits'], 'remoteId'));
		self::assertSame(1, $answer['moreCount']);
		self::assertSame(['not-permitted'], $answer['notices']);
	}//end testTheLimitHoldsAcrossTypesAndARefusedTypeSaysNotPermitted()

	public function testAMailAndAChatMessageAreFetchedWithTheOwnGrant(): void {
		$this->ownAccounts = ['cred-own'];
		$calls = [];
		$this->broker->method('request')->willReturnCallback(
			function (...$args) use (&$calls): array {
				$calls[] = [$args[0], $args[3]];
				if (str_ends_with($args[3], '/$value') === true) {
					return ['status' => 200, 'headers' => [], 'body' => "Subject: Planning\r\n\r\nTekst"];
				}
				if (str_contains($args[3], '/me/messages/') === true) {
					return ['status' => 200, 'headers' => [], 'body' => json_encode(['subject' => 'Planning: fase 2/3'])];
				}
				return ['status' => 200, 'headers' => [], 'body' => json_encode(['id' => '1712', 'body' => ['content' => 'Klaar?']])];
			}
		);
		$adapter = $this->adapter();

		self::assertSame(
			['fileName' => 'Planning fase 2 3.eml', 'mimeType' => 'message/rfc822', 'content' => "Subject: Planning\r\n\r\nTekst"],
			$adapter->fetch(handle: 'message:AAMk%3D1', userId: 'pjansen')
		);
		$chat = $adapter->fetch(handle: 'chatMessage:19%3Aabc%40thread.v2:1712', userId: 'pjansen');
		self::assertSame('chat-message-1712.json', $chat['fileName']);
		self::assertSame('application/json', $chat['mimeType']);
		self::assertSame('Klaar?', json_decode($chat['content'], true)['body']['content']);

		self::assertSame(
			[
				['cred-own', '/v1.0/me/messages/AAMk%3D1?$select=subject'],
				['cred-own', '/v1.0/me/messages/AAMk%3D1/$value'],
				['cred-own', '/v1.0/chats/19%3Aabc%40thread.v2/messages/1712'],
				['cred-own', '/v1.0/chats/19%3Aabc%40thread.v2/messages/1712'],
			],
			$calls
		);
		self::assertNull($adapter->fetch(handle: 'message:..', userId: 'pjansen'), 'a dot segment would climb out of /messages/');
		self::assertNull($adapter->fetch(handle: 'chatMessage:19%3Aabc:', userId: 'pjansen'));
	}//end testAMailAndAChatMessageAreFetchedWithTheOwnGrant()

	public function testWithoutTheOwnGrantAMailIsNotFetched(): void {
		$this->ownAccounts = [];
		$this->broker->expects(self::never())->method('request');

		self::assertNull($this->adapter()->fetch(handle: 'message:m1', userId: 'pjansen'));
	}//end testWithoutTheOwnGrantAMailIsNotFetched()

	public function testWithoutACredentialTheSourceIsNotConnected(): void {
		self::assertTrue($this->adapter()->isConnected());
		$this->credential = '';
		self::assertFalse($this->adapter()->isConnected());
	}//end testWithoutACredentialTheSourceIsNotConnected()
}//end class
