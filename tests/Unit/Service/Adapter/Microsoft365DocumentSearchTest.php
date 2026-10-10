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
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter
 * @covers \OCA\Integriq\Service\Adapter\Saas\GraphDriveSearch
 */
class Microsoft365DocumentSearchTest extends TestCase {

	private CredentialBrokerService&MockObject $broker;

	private string $credential = 'cred-uuid-m365';

	/**
	 * The adapter over the broker double.
	 *
	 * @return Microsoft365Adapter The adapter.
	 */
	private function adapter(): Microsoft365Adapter {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === Microsoft365Adapter::SEARCH_REGION_KEY) ? $default : $this->credential
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new Microsoft365Adapter(credentialBroker: $this->broker, appConfig: $config, logger: $this->createMock(LoggerInterface::class), l10n: $l10n);
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
	}//end testAFetchAnswersTheDriveItemAndRefusesOtherHandles()

	public function testWithoutACredentialTheSourceIsNotConnected(): void {
		self::assertTrue($this->adapter()->isConnected());
		$this->credential = '';
		self::assertFalse($this->adapter()->isConnected());
	}//end testWithoutACredentialTheSourceIsNotConnected()
}//end class
