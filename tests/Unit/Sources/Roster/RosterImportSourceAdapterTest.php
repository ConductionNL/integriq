<?php

/**
 * Unit tests for the dormant rostering-import Source adapter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Roster;

use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use OCA\Integriq\Sources\Roster\RosterImportSourceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests: the source adapter maps a lesson batch onto
 * learniq's rostering-import job payload field names.
 */
class RosterImportSourceAdapterTest extends TestCase {
	/**
	 * @return void
	 */
	public function testIsActiveDefaultsToFalse(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new RosterImportSourceAdapter($config, $logger, new RosterImportClientMock());

		$this->assertFalse($adapter->isActive());
	}//end testIsActiveDefaultsToFalse()

	/**
	 * @return void
	 */
	public function testImportLessonsMapsToRosteringImportFieldNames(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$adapter = new RosterImportSourceAdapter($config, $logger, new RosterImportClientMock());

		$payload = $adapter->importLessons('roster-zermelo');

		$this->assertCount(2, $payload);
		foreach ($payload as $record) {
			$this->assertArrayHasKey('systemId', $record);
			$this->assertArrayHasKey('subject', $record);
			$this->assertArrayHasKey('startTime', $record);
			$this->assertArrayHasKey('endTime', $record);
			$this->assertArrayHasKey('roomLabel', $record);
			$this->assertArrayHasKey('teacherReference', $record);
			$this->assertArrayHasKey('groupReference', $record);
			// Raw client field names MUST NOT survive the mapping.
			$this->assertArrayNotHasKey('startsAt', $record);
			$this->assertArrayNotHasKey('endsAt', $record);
			$this->assertArrayNotHasKey('room', $record);
		}

		$this->assertSame('roster-zermelo', $payload[0]['systemId']);
		$this->assertSame('Wiskunde', $payload[0]['subject']);
	}//end testImportLessonsMapsToRosteringImportFieldNames()

	/**
	 * @return void
	 */
	public function testImportLessonsLogsSummary(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('0');
		$logger = $this->createMock(LoggerInterface::class);

		$capturedContext = null;
		$logger->expects($this->once())
			->method('debug')
			->with(
				$this->equalTo('roster-import.importLessons'),
				$this->callback(function (array $context) use (&$capturedContext): bool {
					$capturedContext = $context;
					return true;
				})
			);

		$adapter = new RosterImportSourceAdapter($config, $logger, new RosterImportClientMock());
		$adapter->importLessons('roster-xedule');

		$this->assertIsArray($capturedContext);
		$this->assertSame(2, $capturedContext['recordCount']);
		$this->assertSame('mock', $capturedContext['flavour']);
	}//end testImportLessonsLogsSummary()
}//end class
