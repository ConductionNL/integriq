<?php

/**
 * Unit tests for the log retention resolver, and the guard that keeps its
 * defaults equal to the retention the log schemas declare.
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
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-one-resolver-supplies-retention-with-the-schemas-defaults-req-adef-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Service\RetentionDefaults;
use OCA\Integriq\Service\RetentionSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Configured values, defaults, and the drift guard.
 *
 * @covers \OCA\Integriq\Service\RetentionSettings
 */
class RetentionSettingsTest extends TestCase {

	/**
	 * Each resolver default and the schema whose `x-openregister-archival`
	 * block must declare it: the default retention, and the retention of
	 * the schema's first rule (the one for a successful log).
	 *
	 * @var array<string,array{file: string, schema: string, default: int, success?: int}>
	 */
	private const SCHEMA_DEFAULTS = [
		'call_log' => ['file' => 'integriq_register.json', 'schema' => 'call_log', 'default' => RetentionDefaults::CALL_LOG, 'success' => RetentionDefaults::SUCCESS_LOG],
		'job_log' => ['file' => 'integriq_register.json', 'schema' => 'job_log', 'default' => RetentionDefaults::JOB_LOG, 'success' => RetentionDefaults::SUCCESS_LOG],
		'synchronization_log' => ['file' => 'integriq_register.json', 'schema' => 'synchronization_log', 'default' => RetentionDefaults::SYNC_LOG, 'success' => RetentionDefaults::SUCCESS_LOG],
		'synchronization_contract_log' => ['file' => 'integriq_register.json', 'schema' => 'synchronization_contract_log', 'default' => RetentionDefaults::SYNC_CONTRACT_LOG, 'success' => RetentionDefaults::SUCCESS_LOG],
		'execution_trace' => ['file' => 'register.d/execution-trace-observability.json', 'schema' => 'execution_trace', 'default' => RetentionDefaults::EXECUTION_TRACE],
	];

	/**
	 * With no retention key every kind gets its schema's default; an unset
	 * synchronization log is 30 days, not the 3 it once was.
	 *
	 * @return void
	 */
	public function testAnUnsetKeyGivesTheSchemasDefaults(): void {
		$resolver = new RetentionSettings(appConfig: $this->config(stored: ''));

		$this->assertSame(2592000000, $resolver->get(kind: 'syncLog'));
		$this->assertSame(2592000000, $resolver->get(kind: 'callLog'));
		$this->assertSame(3600000, $resolver->get(kind: 'success'));
		$this->assertSame(604800000, $resolver->get(kind: 'eventMessage'));
		$this->assertSame(RetentionDefaults::BY_SETTING, $resolver->all());
	}//end testAnUnsetKeyGivesTheSchemasDefaults()

	/**
	 * errorAndSuccess() gives a kind's own retention first and the success
	 * retention second, and refuses an unknown kind like get().
	 *
	 * @return void
	 */
	public function testErrorAndSuccessGivesTheKindThenTheSuccessRetention(): void {
		$resolver = new RetentionSettings(appConfig: $this->config(stored: '{"jobLogRetention":86400000,"successLogRetention":0}'));

		$this->assertSame([86400000, 0], $resolver->errorAndSuccess(kind: 'jobLog'));
		$this->assertSame([2592000000, 0], $resolver->errorAndSuccess(kind: 'callLog'));

		$this->expectException(InvalidArgumentException::class);
		$resolver->errorAndSuccess(kind: 'auditLog');
	}//end testErrorAndSuccessGivesTheKindThenTheSuccessRetention()

	/**
	 * A value set through `occ` applies unchanged, a number or a numeric
	 * string alike, and a kind not set keeps its default.
	 *
	 * @return void
	 */
	public function testAnOccValueAppliesUnchanged(): void {
		$resolver = new RetentionSettings(appConfig: $this->config(stored: '{"callLogRetention":7776000000,"jobLogRetention":"86400000","successLogRetention":0}'));

		$this->assertSame(7776000000, $resolver->get(kind: 'callLog'));
		$this->assertSame(86400000, $resolver->get(kind: 'jobLog'));
		$this->assertSame(0, $resolver->get(kind: 'success'));
		$this->assertSame(RetentionDefaults::SYNC_LOG, $resolver->get(kind: 'syncLog'));
	}//end testAnOccValueAppliesUnchanged()

	/**
	 * A value that is not a non-negative number, or a key that is not JSON,
	 * reads as unset rather than as a log that expires before it is written.
	 *
	 * @return void
	 */
	public function testAnUnusableValueReadsAsUnset(): void {
		$resolver = new RetentionSettings(appConfig: $this->config(stored: '{"callLogRetention":-5,"jobLogRetention":"soon","syncLogRetention":null}'));
		$this->assertSame(RetentionDefaults::CALL_LOG, $resolver->get(kind: 'callLog'));
		$this->assertSame(RetentionDefaults::JOB_LOG, $resolver->get(kind: 'jobLog'));
		$this->assertSame(RetentionDefaults::SYNC_LOG, $resolver->get(kind: 'syncLog'));

		$broken = new RetentionSettings(appConfig: $this->config(stored: '{not json'));
		$this->assertSame([], $broken->stored());
		$this->assertSame(RetentionDefaults::BY_SETTING, $broken->all());
	}//end testAnUnusableValueReadsAsUnset()

	/**
	 * An unknown kind is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown log kind "auditLog".');

		(new RetentionSettings(appConfig: $this->config(stored: '')))->get(kind: 'auditLog');
	}//end testAnUnknownKindIsRefused()

	/**
	 * Every kind the resolver knows has a default.
	 *
	 * @return void
	 */
	public function testEveryKindHasADefault(): void {
		foreach (RetentionSettings::KINDS as $kind => $setting) {
			$this->assertArrayHasKey($setting, RetentionDefaults::BY_SETTING, $kind);
		}

		$this->assertEqualsCanonicalizing(array_values(RetentionSettings::KINDS), array_keys(RetentionDefaults::BY_SETTING));
	}//end testEveryKindHasADefault()

	/**
	 * The drift guard: each resolver default equals the retention its
	 * schema declares, so the two cannot drift apart again.
	 *
	 * @return void
	 */
	public function testTheDefaultsMatchTheSchemas(): void {
		foreach (self::SCHEMA_DEFAULTS as $name => $expected) {
			$archival = $this->archival(file: $expected['file'], schema: $expected['schema']);
			$this->assertSame(
				$expected['default'],
				self::milliseconds(duration: (string)$archival['retention']['default']),
				$name . ' declares a default retention the resolver does not use'
			);
			if (isset($expected['success']) === true) {
				$this->assertSame(
					$expected['success'],
					self::milliseconds(duration: (string)$archival['retention']['rules'][0]['retention']),
					$name . ' declares a success retention the resolver does not use'
				);
			}
		}
	}//end testTheDefaultsMatchTheSchemas()

	/**
	 * A schema's archival block.
	 *
	 * @param string $file   The register file under lib/Settings.
	 * @param string $schema The schema key.
	 *
	 * @return array<string,mixed>
	 */
	private function archival(string $file, string $schema): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file), true);
		$this->assertIsArray($register, $file);
		$block = ($register['components']['schemas'][$schema]['x-openregister-archival'] ?? null);
		$this->assertIsArray($block, $schema . ' declares no x-openregister-archival block');

		return $block;
	}//end archival()

	/**
	 * An ISO 8601 duration of days, hours, minutes and seconds in milliseconds.
	 *
	 * @param string $duration The duration, such as P30D or PT1H.
	 *
	 * @return int
	 */
	private static function milliseconds(string $duration): int {
		$start = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

		return ($start->add(new DateInterval($duration))->getTimestamp() - $start->getTimestamp()) * 1000;
	}//end milliseconds()

	/**
	 * An app config holding a retention JSON.
	 *
	 * @param string $stored The stored value, empty for unset.
	 *
	 * @return IAppConfig
	 */
	private function config(string $stored): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('integriq', 'retention', '')->willReturn($stored);

		return $config;
	}//end config()
}//end class
