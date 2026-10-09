<?php

/**
 * Unit tests for the seven-year retention of the opt-out log.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Integriq\BackgroundJob\OptOutLogRetentionJob;
use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\Tests\Helpers\InMemoryOptOutLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Only entries past seven years go.
 */
class OptOutLogRetentionJobTest extends TestCase {

	/**
	 * An entry 7 years and 1 day old goes; one 6 years old stays.
	 *
	 * @return void
	 */
	public function testEntriesOlderThanSevenYearsAreDeleted(): void {
		$now = (new DateTimeImmutable('2026-10-05T12:00:00Z'))->getTimestamp();
		$log = new InMemoryOptOutLogMapper($this->createMock(IDBConnection::class));
		$log->append($this->entry(at: (new DateTimeImmutable('2019-10-04T12:00:00Z'))->getTimestamp(), kind: 'suppressed'));
		$log->append($this->entry(at: (new DateTimeImmutable('2020-10-05T12:00:00Z'))->getTimestamp(), kind: 'override'));

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn($now);
		$job = new OptOutLogRetentionJob($clock, $log, new NullLogger());

		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);

		$this->assertCount(1, $log->rows);
		$this->assertSame('override', $log->rows[0]->getKind());
		$this->assertSame((new DateTimeImmutable('2019-10-05T12:00:00Z'))->getTimestamp(), $job->cutOff());

	}//end testEntriesOlderThanSevenYearsAreDeleted()

	/**
	 * The job is registered with Nextcloud.
	 *
	 * @return void
	 */
	public function testTheJobIsRegisteredInInfoXml(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		$this->assertStringContainsString('<job>OCA\\Integriq\\BackgroundJob\\OptOutLogRetentionJob</job>', $info);

	}//end testTheJobIsRegisteredInInfoXml()

	/**
	 * One log row.
	 *
	 * @param int $at When.
	 * @param string $kind The kind.
	 *
	 * @return OptOutLogEntry The row.
	 */
	private function entry(int $at, string $kind): OptOutLogEntry {
		$entry = new OptOutLogEntry();
		$entry->setAt($at);
		$entry->setKind($kind);

		return $entry;

	}//end entry()

}//end class
