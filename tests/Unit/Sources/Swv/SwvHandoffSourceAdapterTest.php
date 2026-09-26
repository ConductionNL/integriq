<?php

/**
 * Unit tests for the dormant SWV hand-off Source adapter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Swv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Swv;

use OCA\Integriq\Adapters\Swv\SwvHandoffClientMock;
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests: the source adapter hands off a dossier without ever
 * leaking a pupil-identifying value, and the Privacyconvenant-holder
 * record never blocks a hand-off.
 */
class SwvHandoffSourceAdapterTest extends TestCase {
	/**
	 * @return array<string,mixed>
	 */
	private function fixtureDossier(): array {
		$path = __DIR__ . '/../../../fixtures/swv/fixture-swv-dossier.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		return $decoded['dossier'];
	}//end fixtureDossier()

	/**
	 * @return void
	 */
	public function testIsActiveDefaultsToFalse(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new SwvHandoffSourceAdapter($config, $logger, new SwvHandoffClientMock());

		$this->assertFalse($adapter->isActive());
	}//end testIsActiveDefaultsToFalse()

	/**
	 * @return void
	 */
	public function testPrivacyconvenantHolderDefaultsToEmptyString(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new SwvHandoffSourceAdapter($config, $logger, new SwvHandoffClientMock());

		$this->assertSame('', $adapter->privacyconvenantHolder());
	}//end testPrivacyconvenantHolderDefaultsToEmptyString()

	/**
	 * An unset Privacyconvenant holder MUST NOT block a mock hand-off —
	 * per REQ-004, this is a governance record, never a code gate.
	 *
	 * @return void
	 */
	public function testHandOffSucceedsWithNoPrivacyconvenantHolderRecorded(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new SwvHandoffSourceAdapter($config, $logger, new SwvHandoffClientMock());

		$result = $adapter->handOffDossier('swv-kindkans', $this->fixtureDossier());

		$this->assertArrayHasKey('referenceId', $result);
		$this->assertSame('received', $result['acceptedStatus']);
	}//end testHandOffSucceedsWithNoPrivacyconvenantHolderRecorded()

	/**
	 * @return void
	 */
	public function testHandOffDossierLogsNoPupilIdentifyingValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$capturedContext = null;
		$logger->expects($this->once())
			->method('debug')
			->with(
				$this->equalTo('swv-handoff.handOffDossier'),
				$this->callback(function (array $context) use (&$capturedContext): bool {
					$capturedContext = $context;
					return true;
				})
			);

		$adapter = new SwvHandoffSourceAdapter($config, $logger, new SwvHandoffClientMock());
		$adapter->handOffDossier('swv-kindkans', $this->fixtureDossier());

		$this->assertIsArray($capturedContext);
		$encoded = json_encode($capturedContext);
		$this->assertIsString($encoded);
		$this->assertStringNotContainsString('leerling-mock-0001', $encoded);
		$this->assertSame('tlv-so', $capturedContext['dossierType']);
		$this->assertTrue($capturedContext['tlvRequested']);
		$this->assertSame('mock', $capturedContext['flavour']);
	}//end testHandOffDossierLogsNoPupilIdentifyingValue()
}//end class
