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
	 * The open file of the record with files.
	 */
	private const OPEN_FILE = 'https://maken.wikiwijs.nl/files/Betoog%20schrijven.pdf';

	/**
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * @return EdurepConnection
	 */
	private function connection(): EdurepConnection {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(value: new ArrayCache());
		$health = new EdurepHealthCheck(
			transport: $this->createMock(originalClassName: SruTransport::class),
			status: new EdurepStatusStore(cacheFactory: $cacheFactory)
		);

		return new EdurepConnection(health: $health, settings: new EdurepSettings(config: $appConfig));
	}//end connection()

	/**
	 * @param LearningMaterialSource|null $source The source, the recorded Edurep when null.
	 *
	 * @return LearningMaterialRequestedEvent The handled event.
	 */
	private function ask(array $request, ?LearningMaterialSource $source = null): LearningMaterialRequestedEvent {
		$source = ($source ?? new EdurepSourceMock(logger: $this->createMock(originalClassName: LoggerInterface::class)));
		$listener = new LearningMaterialRequestedListener(
			service: new LearningMaterialService(edurep: $source, connection: $this->connection(), files: new MaterialFileFetcherMock()),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
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

		$this->assertTrue(condition: $event->isHandled());
		$this->assertNull(actual: $event->getRefusal());
		$this->assertSame(expected: 242, actual: $event->getAnswer()['total']);
		$this->assertSame(expected: 'krantindeklas', actual: $event->getAnswer()['records'][0]['collection']);
		$this->assertIsString(actual: $event->getAnswer()['records'][0]['license']);
		$this->assertArrayHasKey(key: 'meta.repository.id', array: $event->getAnswer()['facets']);
	}//end testASearchIsAnsweredWithRecordsAndFacets()

	/**
	 * @return void
	 */
	public function testSwitchedOffCollectionsAreLeftOutUnlessTheRequestNamesCollections(): void {
		$service = new LearningMaterialService(
			edurep: $this->createMock(originalClassName: LearningMaterialSource::class),
			connection: $this->connection(),
			files: new MaterialFileFetcherMock()
		);

		$this->assertSame(expected: ['excludeCollections' => ['KlasCement'], 'language' => 'nl'], actual: $service->withSettings(filters: []));
		$this->assertSame(
			expected: ['collection' => ['KlasCement'], 'language' => 'en'],
			actual: $service->withSettings(filters: ['collection' => ['KlasCement'], 'language' => 'en'])
		);
	}//end testSwitchedOffCollectionsAreLeftOutUnlessTheRequestNamesCollections()

	/**
	 * @return void
	 */
	public function testFindAnswersOneRecordOrNull(): void {
		$found = $this->ask(request: ['operation' => 'find', 'id' => '/NTR/POMS/WO_NTR_20306689']);
		$missing = $this->ask(request: ['operation' => 'find', 'id' => 'urn:none:0']);

		$this->assertSame(expected: 'rights-reserved', actual: $found->getAnswer()['license']);
		$this->assertTrue(condition: $missing->isHandled());
		$this->assertNull(actual: $missing->getAnswer());
		$this->assertNull(actual: $missing->getRefusal());
	}//end testFindAnswersOneRecordOrNull()

	/**
	 * @return void
	 */
	public function testAnUnansweredSourceIsRefusedAsSourceUnavailable(): void {
		$source = $this->createMock(originalClassName: LearningMaterialSource::class);
		$source->method('search')->willThrowException(
			exception: new SourceUnavailableException(source: 'edurep', lastSuccess: '2026-11-05T10:00:00+00:00')
		);

		$refusal = $this->ask(request: ['operation' => 'search', 'query' => 'x'], source: $source)->getRefusal();

		$this->assertSame(
			expected: [
				'code' => 'source-unavailable',
				'reason' => 'Edurep antwoordt niet',
				'source' => 'edurep',
				'lastSuccess' => '2026-11-05T10:00:00+00:00',
			],
			actual: $refusal
		);
	}//end testAnUnansweredSourceIsRefusedAsSourceUnavailable()

	/**
	 * @return void
	 */
	public function testUnknownAndUnavailableSourcesAndOperationsAreRefused(): void {
		$this->assertSame(
			expected: 'source-not-available',
			actual: $this->ask(request: ['operation' => 'search', 'source' => 'edusources'])->getRefusal()['code']
		);
		$this->assertSame(
			expected: 'unknown-source',
			actual: $this->ask(request: ['operation' => 'search', 'source' => 'other'])->getRefusal()['code']
		);
		$this->assertSame(expected: 'unknown-operation', actual: $this->ask(request: ['operation' => 'delete'])->getRefusal()['code']);
	}//end testUnknownAndUnavailableSourcesAndOperationsAreRefused()

	/**
	 * @return void
	 */
	public function testASwitchedOffConnectionRefusesSearchAndFind(): void {
		$this->config[EdurepSettings::KEY_ENABLED] = '0';

		$this->assertSame(
			expected: 'source-disabled',
			actual: $this->ask(request: ['operation' => 'search', 'query' => 'betoog'])->getRefusal()['code']
		);
		$this->assertSame(
			expected: 'source-disabled',
			actual: $this->ask(request: ['operation' => 'find', 'id' => 'urn:wikiwijsmaken:194413'])->getRefusal()['code']
		);
	}//end testASwitchedOffConnectionRefusesSearchAndFind()

	/**
	 * @return void
	 */
	public function testStatusAndSaveSettingsAnswerTheConnectionStatus(): void {
		$status = $this->ask(request: ['operation' => 'status'])->getAnswer();
		$saved = $this->ask(request: ['operation' => 'saveSettings', 'settings' => ['language' => 'en']])->getAnswer();

		$this->assertSame(expected: 'Open lesmateriaal via Edurep', actual: $status['name']);
		$this->assertSame(expected: 'Fout', actual: $status['health']['label']);
		$this->assertSame(expected: 'edurep', actual: $saved['source']);
	}//end testStatusAndSaveSettingsAnswerTheConnectionStatus()

	/**
	 * @return void
	 */
	public function testAnUnexpectedFailureIsRefusedNotThrown(): void {
		$source = $this->createMock(originalClassName: LearningMaterialSource::class);
		$source->method('find')->willThrowException(exception: new RuntimeException('boom'));

		$this->assertSame(
			expected: 'failed',
			actual: $this->ask(request: ['operation' => 'find', 'id' => 'x'], source: $source)->getRefusal()['code']
		);
	}//end testAnUnexpectedFailureIsRefusedNotThrown()

	/**
	 * @return void
	 */
	public function testTheListenerIgnoresOtherEvents(): void {
		$listener = new LearningMaterialRequestedListener(
			service: new LearningMaterialService(
				edurep: $this->createMock(originalClassName: LearningMaterialSource::class),
				connection: $this->connection(),
				files: new MaterialFileFetcherMock()
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$listener->handle(event: new Event());

		$this->assertSame(expected: 'learniq', actual: (new LearningMaterialRequestedEvent(sourceApp: 'learniq', request: []))->getSourceApp());
	}//end testTheListenerIgnoresOtherEvents()

	/**
	 * A record with an open file, a restricted file and an http file.
	 *
	 * @return LearningMaterialSource The source that finds it.
	 */
	private function sourceWithFiles(): LearningMaterialSource {
		$record = new MaterialRecord(
			fields: [
				'sourceId' => 'urn:wikiwijsmaken:1',
				'license' => 'cc-by-40',
				'accessRights' => 'OpenAccess',
				'files' => [
					['url' => self::OPEN_FILE, 'format' => 'application/pdf', 'accessRights' => null],
					['url' => 'https://maken.wikiwijs.nl/files/docent.pdf', 'format' => 'application/pdf', 'accessRights' => 'RestrictedAccess'],
					['url' => 'http://maken.wikiwijs.nl/files/plain.pdf', 'format' => 'application/pdf', 'accessRights' => 'OpenAccess'],
				],
			]
		);
		$source = $this->createMock(originalClassName: LearningMaterialSource::class);
		$source->method('find')->willReturnMap(valueMap: [['urn:wikiwijsmaken:1', $record], ['urn:none:0', null]]);

		return $source;
	}//end sourceWithFiles()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testDownloadAnswersTheBytesOfAnOpenFileOfTheRecord(): void {
		$request = ['operation' => 'download', 'id' => 'urn:wikiwijsmaken:1', 'url' => self::OPEN_FILE];
		$answer  = $this->ask(request: $request, source: $this->sourceWithFiles())->getAnswer();

		$this->assertSame(expected: 'urn:wikiwijsmaken:1', actual: $answer['sourceId']);
		$this->assertSame(expected: 'Betoog-schrijven.pdf', actual: $answer['name']);
		$this->assertSame(expected: 'application/pdf', actual: $answer['mimeType']);
		$this->assertSame(expected: MaterialFileFetcherMock::CONTENT, actual: base64_decode($answer['contentBase64'], true));
		$this->assertSame(expected: strlen(MaterialFileFetcherMock::CONTENT), actual: $answer['size']);
	}//end testDownloadAnswersTheBytesOfAnOpenFileOfTheRecord()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testDownloadRefusesAFileTheRecordDoesNotOpenAndAnUnknownRecord(): void {
		$source = $this->sourceWithFiles();
		$ask    = fn (string $id, string $url): array => $this->ask(
			request: ['operation' => 'download', 'id' => $id, 'url' => $url],
			source: $source
		)->getRefusal();

		$this->assertSame(expected: 'file-not-copyable', actual: $ask('urn:wikiwijsmaken:1', 'https://maken.wikiwijs.nl/files/docent.pdf')['code']);
		$this->assertSame(expected: 'file-not-copyable', actual: $ask('urn:wikiwijsmaken:1', 'http://maken.wikiwijs.nl/files/plain.pdf')['code']);
		$this->assertSame(expected: 'file-not-copyable', actual: $ask('urn:wikiwijsmaken:1', 'https://elsewhere.example/any.pdf')['code']);
		$this->assertSame(expected: 'not-found', actual: $ask('urn:none:0', self::OPEN_FILE)['code']);
	}//end testDownloadRefusesAFileTheRecordDoesNotOpenAndAnUnknownRecord()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testDownloadPassesOnATooLargeFileAndIsRefusedWhileSwitchedOff(): void {
		$fetcher = $this->createMock(originalClassName: MaterialFileFetcher::class);
		$fetcher->method('fetch')->willThrowException(exception: MaterialFileFetcherHttp::tooLarge());
		$listener = new LearningMaterialRequestedListener(
			service: new LearningMaterialService(edurep: $this->sourceWithFiles(), connection: $this->connection(), files: $fetcher),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$request = ['operation' => 'download', 'id' => 'urn:wikiwijsmaken:1', 'url' => self::OPEN_FILE];
		$event   = new LearningMaterialRequestedEvent(sourceApp: 'learniq', request: $request);
		$listener->handle(event: $event);
		$this->assertSame(expected: 'file-too-large', actual: $event->getRefusal()['code']);

		$this->config[EdurepSettings::KEY_ENABLED] = '0';
		$switchedOff = $this->ask(request: ['operation' => 'download', 'id' => 'urn:wikiwijsmaken:1', 'url' => 'x'], source: $this->sourceWithFiles());
		$this->assertSame(expected: 'source-disabled', actual: $switchedOff->getRefusal()['code']);
	}//end testDownloadPassesOnATooLargeFileAndIsRefusedWhileSwitchedOff()

	/**
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function testAFileNameIsTheSafeLastPathSegment(): void {
		$this->assertSame(expected: 'les-1.pptx', actual: LearningMaterialService::fileName(url: 'https://x.nl/a/les%201.pptx?download=1'));
		$this->assertSame(expected: 'material', actual: LearningMaterialService::fileName(url: 'https://x.nl/'));
	}//end testAFileNameIsTheSafeLastPathSegment()
}//end class
