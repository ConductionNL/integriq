<?php

/**
 * Unit tests for the dormant LVS UWLR result-import Source adapter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Lvs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Lvs;

use OCA\Integriq\Adapters\Lvs\UwlrResultImportClientMock;
use OCA\Integriq\Sources\Lvs\UwlrResultImportSourceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests: the source adapter maps a UWLR-shaped batch onto
 * learniq's `lvs-import-contract` payload field names and never
 * leaks a pupil-identifying value into the structured logger.
 */
class UwlrResultImportSourceAdapterTest extends TestCase {
	/**
	 * @return void
	 */
	public function testIsActiveDefaultsToFalse(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new UwlrResultImportSourceAdapter($config, $logger, new UwlrResultImportClientMock());

		$this->assertFalse($adapter->isActive());
	}//end testIsActiveDefaultsToFalse()

	/**
	 * @return void
	 */
	public function testIsActiveTrueWhenFlagSet(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('1');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new UwlrResultImportSourceAdapter($config, $logger, new UwlrResultImportClientMock());

		$this->assertTrue($adapter->isActive());
	}//end testIsActiveTrueWhenFlagSet()

	/**
	 * @return void
	 */
	public function testImportResultsMapsToLvsImportContractFieldNames(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new UwlrResultImportSourceAdapter($config, $logger, new UwlrResultImportClientMock());

		$payload = $adapter->importResults('lvs-boom');

		$this->assertCount(3, $payload);
		foreach ($payload as $record) {
			$this->assertArrayHasKey('supplierId', $record);
			$this->assertArrayHasKey('pupilReference', $record);
			$this->assertArrayHasKey('assessmentCode', $record);
			$this->assertArrayHasKey('referenceLevel', $record);
			$this->assertArrayHasKey('proficiencyScore', $record);
			$this->assertArrayHasKey('administeredOn', $record);
			$this->assertArrayHasKey('groupLabel', $record);
			// Raw UWLR field names MUST NOT survive the mapping.
			$this->assertArrayNotHasKey('leerlingReference', $record);
			$this->assertArrayNotHasKey('toetscode', $record);
			$this->assertArrayNotHasKey('referentieniveau', $record);
		}

		$this->assertSame('lvs-boom', $payload[0]['supplierId']);
		$this->assertSame('leerling-mock-0001', $payload[0]['pupilReference']);
		$this->assertSame('1F', $payload[0]['referenceLevel']);
	}//end testImportResultsMapsToLvsImportContractFieldNames()

	/**
	 * @return void
	 */
	public function testImportResultsLogsNoPupilIdentifyingValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$capturedContext = null;
		$logger->expects($this->once())
			->method('debug')
			->with(
				$this->equalTo('lvs-uwlr-import.importResults'),
				$this->callback(function (array $context) use (&$capturedContext): bool {
					$capturedContext = $context;
					return true;
				})
			);

		$adapter = new UwlrResultImportSourceAdapter($config, $logger, new UwlrResultImportClientMock());
		$adapter->importResults('lvs-boom');

		$this->assertIsArray($capturedContext);
		$encoded = json_encode($capturedContext);
		$this->assertIsString($encoded);
		$this->assertStringNotContainsString('leerling-mock-0001', $encoded);
		$this->assertSame(3, $capturedContext['recordCount']);
		$this->assertSame('mock', $capturedContext['flavour']);
	}//end testImportResultsLogsNoPupilIdentifyingValue()
}//end class
