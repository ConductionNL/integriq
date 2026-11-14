<?php

/**
 * Unit tests for the retention read, its update and the queued rebase.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-an-administrator-sets-retention-on-the-admin-page-req-adef-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\BackgroundJob\RetentionRebaseJob;
use OCA\Integriq\Service\RetentionDefaults;
use OCA\Integriq\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Read, change and rebase the retention over an in-memory app config.
 *
 * @covers \OCA\Integriq\Service\SettingsService
 * @uses   \OCA\Integriq\Service\RetentionSettings
 */
class SettingsServiceTest extends TestCase {

	/**
	 * The in-memory `integriq` app config.
	 *
	 * @var array<string,string>
	 */
	private array $stored = [];

	/**
	 * The job list double.
	 *
	 * @var IJobList&MockObject
	 */
	private IJobList $jobList;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = [];
		$this->jobList = $this->createMock(IJobList::class);
	}//end setUp()

	/**
	 * The read reports each value, the defaults beside them, and no rebase yet.
	 *
	 * @return void
	 */
	public function testTheReadReportsValuesAndDefaults(): void {
		$this->stored['retention'] = '{"callLogRetention":7776000000}';

		$settings = $this->service()->getSettings();

		$this->assertSame(7776000000, $settings['retention']['callLogRetention']);
		$this->assertSame(RetentionDefaults::SYNC_LOG, $settings['retention']['syncLogRetention']);
		$this->assertSame(RetentionDefaults::BY_SETTING, $settings['defaults']);
		$this->assertSame([], $settings['rebase']);
	}//end testTheReadReportsValuesAndDefaults()

	/**
	 * An administrator keeps call logs for 90 days: the value is stored in
	 * milliseconds beside what was there, and read back.
	 *
	 * @return void
	 */
	public function testAnUpdateIsStoredAndReadBack(): void {
		$this->stored['retention'] = '{"jobLogRetention":86400000,"legacyKey":1}';

		$settings = $this->service()->updateSettings(['retention' => ['callLogRetention' => 7776000000, 'successLogRetention' => '0']]);

		$this->assertSame(
			['jobLogRetention' => 86400000, 'legacyKey' => 1, 'callLogRetention' => 7776000000, 'successLogRetention' => 0],
			json_decode($this->stored['retention'], true)
		);
		$this->assertSame(7776000000, $settings['retention']['callLogRetention']);
		$this->assertSame(86400000, $settings['retention']['jobLogRetention']);
		$this->assertSame(0, $settings['retention']['successLogRetention']);
	}//end testAnUpdateIsStoredAndReadBack()

	/**
	 * A negative, fractional or non-numeric value, or a key that is not a
	 * retention, refuses the update by name and stores nothing.
	 *
	 * @return void
	 */
	public function testABadValueIsRefusedNamingTheField(): void {
		$cases = [
			'callLogRetention: must be a whole number of milliseconds, 0 or more.' => ['callLogRetention' => -1],
			'jobLogRetention: must be a whole number of milliseconds, 0 or more.' => ['jobLogRetention' => 'thirty days'],
			'syncLogRetention: must be a whole number of milliseconds, 0 or more.' => ['syncLogRetention' => 1.5],
			'auditLogRetention: not a retention setting.' => ['auditLogRetention' => 1],
			'retention: send an object of retention values.' => null,
		];
		foreach ($cases as $message => $retention) {
			try {
				$this->service()->updateSettings(['retention' => $retention]);
				$this->fail('Accepted: ' . $message);
			} catch (InvalidArgumentException $exception) {
				$this->assertSame($message, $exception->getMessage());
			}
		}

		$this->assertArrayNotHasKey('retention', $this->stored);
	}//end testABadValueIsRefusedNamingTheField()

	/**
	 * A rebase queues the job for the chosen kinds and records it as queued.
	 *
	 * @return void
	 */
	public function testARebaseQueuesTheJob(): void {
		$this->jobList->expects($this->once())
			->method('add')
			->with(RetentionRebaseJob::class, ['kinds' => ['callLog'], 'kind' => 0, 'offset' => 0]);

		$result = $this->service()->rebase(['callLog', 'callLog']);

		$this->assertSame(['queued' => true, 'kinds' => ['callLog']], $result);
		$this->assertSame(
			['status' => 'queued', 'kinds' => ['callLog'], 'processed' => 0, 'updated' => 0],
			json_decode($this->stored[RetentionRebaseJob::PROGRESS_KEY], true)
		);
		$this->assertSame('queued', $this->service()->getSettings()['rebase']['status']);
	}//end testARebaseQueuesTheJob()

	/**
	 * No kinds named means every kind with an expiry.
	 *
	 * @return void
	 */
	public function testARebaseWithoutKindsCoversEveryLogWithAnExpiry(): void {
		$this->jobList->expects($this->once())
			->method('add')
			->with(RetentionRebaseJob::class, ['kinds' => ['callLog', 'jobLog', 'syncLog', 'syncContractLog'], 'kind' => 0, 'offset' => 0]);

		$this->service()->rebase();
	}//end testARebaseWithoutKindsCoversEveryLogWithAnExpiry()

	/**
	 * A kind without an expiry to rebase is refused and nothing is queued.
	 *
	 * @return void
	 */
	public function testAKindWithoutAnExpiryIsRefused(): void {
		$this->jobList->expects($this->never())->method('add');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('kinds: "eventMessage" is not a log kind that can be rebased.');

		$this->service()->rebase(['callLog', 'eventMessage']);
	}//end testAKindWithoutAnExpiryIsRefused()

	/**
	 * The service over the in-memory config.
	 *
	 * @return SettingsService
	 */
	private function service(): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame('integriq', $app);
				$this->stored[$key] = $value;
				return true;
			}
		);

		return new SettingsService(config: $config, jobList: $this->jobList, logger: new NullLogger());
	}//end service()
}//end class
