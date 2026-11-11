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
			service: new LearningMaterialService(edurep: $source, connection: $this->connection()),
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
		$service = new LearningMaterialService(edurep: $this->createMock(LearningMaterialSource::class), connection: $this->connection());

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
			service: new LearningMaterialService(edurep: $this->createMock(LearningMaterialSource::class), connection: $this->connection()),
			logger: $this->createMock(LoggerInterface::class)
		);
		$listener->handle(event: new Event());

		$this->assertSame('learniq', (new LearningMaterialRequestedEvent(sourceApp: 'learniq', request: []))->getSourceApp());
	}//end testTheListenerIgnoresOtherEvents()
}//end class
