<?php

/**
 * Unit tests for the Edurep connection: settings and health.
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
use OCA\Integriq\Adapters\LearningMaterial\EdurepStatusStore;
use OCA\Integriq\Adapters\LearningMaterial\SourceUnavailableException;
use OCA\Integriq\Adapters\LearningMaterial\SruTransport;
use OCA\Integriq\Tests\Helpers\ArrayCache;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The connection reports health once per five minutes and keeps its settings.
 */
class EdurepConnectionTest extends TestCase {
	/**
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * @var SruTransport&MockObject
	 */
	private SruTransport $transport;

	private EdurepStatusStore $status;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->transport = $this->createMock(SruTransport::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(new ArrayCache());
		$this->status = new EdurepStatusStore(cacheFactory: $cacheFactory);
	}//end setUp()

	/**
	 * @return EdurepSettings
	 */
	private function settings(): EdurepSettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		return new EdurepSettings(config: $appConfig);
	}//end settings()

	/**
	 * @return void
	 */
	public function testHealthIsReportedAndCachedForFiveMinutes(): void {
		$this->transport->expects($this->once())->method('fetch')->willReturn((string)file_get_contents(__DIR__ . '/../../../fixtures/edurep/empty.xml'));
		$check = new EdurepHealthCheck(transport: $this->transport, status: $this->status);

		$first = $check->check();
		$second = $check->check();

		$this->assertSame('ok', $first['status']);
		$this->assertSame('Werkt', $first['label']);
		$this->assertIsInt($first['responseTimeMs']);
		$this->assertNotFalse(strtotime($first['checkedAt']));
		$this->assertSame($first, $second);
		$this->assertSame(300, EdurepStatusStore::HEALTH_TTL);
	}//end testHealthIsReportedAndCachedForFiveMinutes()

	/**
	 * @return void
	 */
	public function testAnAnswerThatIsNoSruResponseReportsFout(): void {
		$this->transport->method('fetch')->willReturn('<html>maintenance</html>');

		$this->assertSame('Fout', (new EdurepHealthCheck(transport: $this->transport, status: $this->status))->check()['label']);
	}//end testAnAnswerThatIsNoSruResponseReportsFout()

	/**
	 * @return void
	 */
	public function testAFailedProbeReportsFout(): void {
		$this->transport->method('fetch')->willThrowException(new SourceUnavailableException(source: 'edurep', lastSuccess: null));

		$health = (new EdurepHealthCheck(transport: $this->transport, status: $this->status))->check();

		$this->assertSame('error', $health['status']);
		$this->assertSame('Fout', $health['label']);
	}//end testAFailedProbeReportsFout()

	/**
	 * @return void
	 */
	public function testTheDefaultsLeaveKlasCementOutAndSearchDutch(): void {
		$settings = $this->settings()->toArray();

		$this->assertTrue($settings['enabled']);
		$this->assertSame(['KlasCement'], $settings['disabledCollections']);
		$this->assertSame('nl', $settings['language']);
		$this->assertSame('https://www.kennisnet.nl/diensten/edurep/', $settings['termsUrl']);
	}//end testTheDefaultsLeaveKlasCementOutAndSearchDutch()

	/**
	 * @return void
	 */
	public function testSavedSettingsAreReadBackAndBadValuesIgnored(): void {
		$settings = $this->settings();
		$settings->save(settings: ['enabled' => false, 'disabledCollections' => ['artik', 3], 'language' => 'en']);
		$settings->save(settings: ['language' => 'nl; DROP']);

		$this->assertSame(['enabled' => false, 'disabledCollections' => ['artik'], 'language' => 'en', 'termsUrl' => EdurepSettings::TERMS_URL], $settings->toArray());
	}//end testSavedSettingsAreReadBackAndBadValuesIgnored()

	/**
	 * @return void
	 */
	public function testTheStatusCarriesNameHealthAndSettings(): void {
		$this->transport->method('fetch')->willReturn((string)file_get_contents(__DIR__ . '/../../../fixtures/edurep/empty.xml'));
		$connection = new EdurepConnection(health: new EdurepHealthCheck(transport: $this->transport, status: $this->status), settings: $this->settings());

		$status = $connection->status();

		$this->assertSame('edurep', $status['source']);
		$this->assertSame('Open lesmateriaal via Edurep', $status['name']);
		$this->assertSame('Werkt', $status['health']['label']);
		$this->assertSame(['KlasCement'], $status['settings']['disabledCollections']);
	}//end testTheStatusCarriesNameHealthAndSettings()
}//end class
