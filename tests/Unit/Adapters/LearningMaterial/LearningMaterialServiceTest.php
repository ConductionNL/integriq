<?php

/**
 * Unit tests for the learning material service, its event and listener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepConnection;
use OCA\Integriq\Adapters\LearningMaterial\EdurepHealthCheck;
use OCA\Integriq\Adapters\LearningMaterial\EdurepSettings;
use OCA\Integriq\Adapters\LearningMaterial\EdurepSourceMock;
use OCA\Integriq\Adapters\LearningMaterial\EdurepStatusStore;
use OCA\Integriq\Adapters\LearningMaterial\LearningMaterialService;
use OCA\Integriq\Adapters\LearningMaterial\LearningMaterialSource;
use OCA\Integriq\Adapters\LearningMaterial\MaterialFileFetcher;
use OCA\Integriq\Adapters\LearningMaterial\MaterialFileFetcherHttp;
use OCA\Integriq\Adapters\LearningMaterial\MaterialFileFetcherMock;
use OCA\Integriq\Adapters\LearningMaterial\MaterialRecord;
use OCA\Integriq\Adapters\LearningMaterial\SourceUnavailableException;
use OCA\Integriq\Adapters\LearningMaterial\SruTransport;
use OCA\Integriq\Event\LearningMaterialRequestedEvent;
use OCA\Integriq\EventListener\LearningMaterialRequestedListener;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Requests are answered or refused on the event, never thrown into the sender.
 */
class LearningMaterialServiceTest extends TestCase {
	/**
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * @return EdurepConnection
	 */
	private function connection(): EdurepConnection {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());
		$health = new EdurepHealthCheck(transport: $this->createMock(SruTransport::class), status: new EdurepStatusStore(cacheFactory: $cacheFactory));

		return new EdurepConnection(health: $health, settings: new EdurepSettings(config: $appConfig));
	}//end connection()

	/**
	 * @param LearningMaterialSource|null $source The source, the recorded Edurep when null.
	 *
	 * @return LearningMaterialRequestedEvent The handled event.
	 */
	private function ask(array $request, ?LearningMaterialSource $source = null): LearningMaterialRequestedEvent {
		$source = ($source ?? new EdurepSourceMock(logger: $this->createMock(LoggerInterface::class)));
		$listener = new LearningMaterialRequestedListener(
			service: new LearningMaterialService(edurep: $source, connection: $this->connection(), files: new MaterialFileFetcherMock()),
			logger: $this->createMock(LoggerInterface::class)
		);
		$event = new LearningMaterialRequestedEvent(sourceApp: 'learniq', request: $request);
		$listener->handle(event: $event);

		return $event;
	}//end ask()

	/**
	 * @return void
	 */
	public function testASearchIsAnsweredWithRecordsAndFacets(): void {
		$event = $this->ask(request: ['operation' => 'search', 'source' => 'edurep', 'query' => 'betoog', 'filters' => ['excludeCollections' => []], 'page' => 1]);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getRefusal());
		$this->assertSame(242, $event->getAnswer()['total']);
		$this->assertSame('krantindeklas', $event->getAnswer()['records'][0]['collection']);
		$this->assertIsString($event->getAnswer()['records'][0]['license']);
		$this->assertArrayHasKey('meta.repository.id', $event->getAnswer()['facets']);
	}//end testASearchIsAnsweredWithRecordsAndFacets()

	/**
	 * @return void
	 */
	public function testSwitchedOffCollectionsAreLeftOutUnlessTheRequestNamesCollections(): void {
		$service = new LearningMaterialService(edurep: $this->createMock(LearningMaterialSource::class), connection: $this->connection(), files: new MaterialFileFetcherMock());

		$this->assertSame(['excludeCollections' => ['KlasCement'], 'language' => 'nl'], $service->withSettings(filters: []));
		$this->assertSame(['collection' => ['KlasCement'], 'language' => 'en'], $service->withSettings(filters: ['collection' => ['KlasCement'], 'language' => 'en']));
	}//end testSwitchedOffCollectionsAreLeftOutUnlessTheRequestNamesCollections()

	/**
	 * @return void
	 */
	public function testFindAnswersOneRecordOrNull(): void {
		$found = $this->ask(request: ['operation' => 'find', 'id' => '/NTR/POMS/WO_NTR_20306689']);
		$missing = $this->ask(request: ['operation' => 'find', 'id' => 'urn:none:0']);

		$this->assertSame('rights-reserved', $found->getAnswer()['license']);
		$this->assertTrue($missing->isHandled());
		$this->assertNull($missing->getAnswer());
		$this->assertNull($missing->getRefusal());
	}//end testFindAnswersOneRecordOrNull()

	/**
	 * @return void
	 */
	public function testAnUnansweredSourceIsRefusedAsSourceUnavailable(): void {
		$source = $this->createMock(LearningMaterialSource::class);
		$source->method('search')->willThrowException(new SourceUnavailableException(source: 'edurep', lastSuccess: '2026-11-05T10:00:00+00:00'));

		$refusal = $this->ask(request: ['operation' => 'search', 'query' => 'x'], source: $source)->getRefusal();

		$this->assertSame(['code' => 'source-unavailable', 'reason' => 'Edurep antwoordt niet', 'source' => 'edurep', 'lastSuccess' => '2026-11-05T10:00:00+00:00'], $refusal);
	}//end testAnUnansweredSourceIsRefusedAsSourceUnavailable()

	/**
	 * @return void
	 */
	public function testUnknownAndUnavailableSourcesAndOperationsAreRefused(): void {
		$this->assertSame('source-not-available', $this->ask(request: ['operation' => 'search', 'source' => 'edusources'])->getRefusal()['code']);
		$this->assertSame('unknown-source', $this->ask(request: ['operation' => 'search', 'source' => 'other'])->getRefusal()['code']);
		$this->assertSame('unknown-operation', $this->ask(request: ['operation' => 'delete'])->getRefusal()['code']);
	}//end testUnknownAndUnavailableSourcesAndOperationsAreRefused()

	/**
	 * @return void
	 */
	public function testASwitchedOffConnectionRefusesSearchAndFind(): void {
		$this->config[EdurepSettings::KEY_ENABLED] = '0';

		$this->assertSame('source-disabled', $this->ask(request: ['operation' => 'search', 'query' => 'betoog'])->getRefusal()['code']);
		$this->assertSame('source-disabled', $this->ask(request: ['operation' => 'find', 'id' => 'urn:wikiwijsmaken:194413'])->getRefusal()['code']);
	}//end testASwitchedOffConnectionRefusesSearchAndFind()

	/**
	 * @return void
	 */
	public function testStatusAndSaveSettingsAnswerTheConnectionStatus(): void {
		$status = $this->ask(request: ['operation' => 'status'])->getAnswer();
		$saved = $this->ask(request: ['operation' => 'saveSettings', 'settings' => ['language' => 'en']])->getAnswer();

		$this->assertSame('Open lesmateriaal via Edurep', $status['name']);
		$this->assertSame('Fout', $status['health']['label']);
		$this->assertSame('edurep', $saved['source']);
	}//end testStatusAndSaveSettingsAnswerTheConnectionStatus()

	/**
	 * @return void
	 */
	public function testAnUnexpectedFailureIsRefusedNotThrown(): void {
		$source = $this->createMock(LearningMaterialSource::class);
		$source->method('find')->willThrowException(new RuntimeException('boom'));

		$this->assertSame('failed', $this->ask(request: ['operation' => 'find', 'id' => 'x'], source: $source)->getRefusal()['code']);
	}//end testAnUnexpectedFailureIsRefusedNotThrown()

	/**
	 * @return void
	 */
	public function testTheListenerIgnoresOtherEvents(): void {
		$listener = new LearningMaterialRequestedListener(
			service: new LearningMaterialService(edurep: $this->createMock(LearningMaterialSource::class), connection: $this->connection(), files: new MaterialFileFetcherMock()),
			logger: $this->createMock(LoggerInterface::class)
		);
		$listener->handle(event: new Event());

		$this->assertSame('learniq', (new LearningMaterialRequestedEvent(sourceApp: 'learniq', request: []))->getSourceApp());
	}//end testTheListenerIgnoresOtherEvents()

	/**
	 * A record with an open file, a restricted file and an http file.
	 *
	 * @return LearningMaterialSource The source that finds it.
	 */
	private function sourceWithFiles(): LearningMaterialSource {
		$record = new MaterialRecord(
			[
				'sourceId' => 'urn:wikiwijsmaken:1',
				'license' => 'cc-by-40',
				'accessRights' => 'OpenAccess',
				'files' => [
					['url' => 'https://maken.wikiwijs.nl/files/Betoog%20schrijven.pdf', 'format' => 'application/pdf', 'accessRights' => null],
					['url' => 'https://maken.wikiwijs.nl/files/docent.pdf', 'format' => 'application/pdf', 'accessRights' => 'RestrictedAccess'],
					['url' => 'http://maken.wikiwijs.nl/files/plain.pdf', 'format' => 'application/pdf', 'accessRights' => 'OpenAccess'],
				],
			]
		);
		$source = $this->createMock(LearningMaterialSource::class);
		$source->method('find')->willReturnCallback(fn (string $id): ?MaterialRecord => ($id === 'urn:wikiwijsmaken:1' ? $record : null));

		return $source;
	}//end sourceWithFiles()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testDownloadAnswersTheBytesOfAnOpenFileOfTheRecord(): void {
		$answer = $this->ask(request: ['operation' => 'download', 'id' => 'urn:wikiwijsmaken:1', 'url' => 'https://maken.wikiwijs.nl/files/Betoog%20schrijven.pdf'], source: $this->sourceWithFiles())->getAnswer();

		$this->assertSame('urn:wikiwijsmaken:1', $answer['sourceId']);
		$this->assertSame('Betoog-schrijven.pdf', $answer['name']);
		$this->assertSame('application/pdf', $answer['mimeType']);
		$this->assertSame(MaterialFileFetcherMock::CONTENT, base64_decode($answer['contentBase64'], true));
		$this->assertSame(strlen(MaterialFileFetcherMock::CONTENT), $answer['size']);
	}//end testDownloadAnswersTheBytesOfAnOpenFileOfTheRecord()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testDownloadRefusesAFileTheRecordDoesNotOpenAndAnUnknownRecord(): void {
		$ask = fn (string $id, string $url): array => $this->ask(request: ['operation' => 'download', 'id' => $id, 'url' => $url], source: $this->sourceWithFiles())->getRefusal();

		$this->assertSame('file-not-copyable', $ask('urn:wikiwijsmaken:1', 'https://maken.wikiwijs.nl/files/docent.pdf')['code']);
		$this->assertSame('file-not-copyable', $ask('urn:wikiwijsmaken:1', 'http://maken.wikiwijs.nl/files/plain.pdf')['code']);
		$this->assertSame('file-not-copyable', $ask('urn:wikiwijsmaken:1', 'https://elsewhere.example/any.pdf')['code']);
		$this->assertSame('not-found', $ask('urn:none:0', 'https://maken.wikiwijs.nl/files/Betoog%20schrijven.pdf')['code']);
	}//end testDownloadRefusesAFileTheRecordDoesNotOpenAndAnUnknownRecord()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testDownloadPassesOnATooLargeFileAndIsRefusedWhileSwitchedOff(): void {
		$fetcher = $this->createMock(MaterialFileFetcher::class);
		$fetcher->method('fetch')->willThrowException(MaterialFileFetcherHttp::tooLarge());
		$listener = new LearningMaterialRequestedListener(
			service: new LearningMaterialService(edurep: $this->sourceWithFiles(), connection: $this->connection(), files: $fetcher),
			logger: $this->createMock(LoggerInterface::class)
		);
		$event = new LearningMaterialRequestedEvent(sourceApp: 'learniq', request: ['operation' => 'download', 'id' => 'urn:wikiwijsmaken:1', 'url' => 'https://maken.wikiwijs.nl/files/Betoog%20schrijven.pdf']);
		$listener->handle(event: $event);
		$this->assertSame('file-too-large', $event->getRefusal()['code']);

		$this->config[EdurepSettings::KEY_ENABLED] = '0';
		$this->assertSame('source-disabled', $this->ask(request: ['operation' => 'download', 'id' => 'urn:wikiwijsmaken:1', 'url' => 'x'], source: $this->sourceWithFiles())->getRefusal()['code']);
	}//end testDownloadPassesOnATooLargeFileAndIsRefusedWhileSwitchedOff()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testAFileNameIsTheSafeLastPathSegment(): void {
		$this->assertSame('les-1.pptx', LearningMaterialService::fileName(url: 'https://x.nl/a/les%201.pptx?download=1'));
		$this->assertSame('material', LearningMaterialService::fileName(url: 'https://x.nl/'));
	}//end testAFileNameIsTheSafeLastPathSegment()
}//end class
