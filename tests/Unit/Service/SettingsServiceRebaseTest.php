<?php

/**
 * Golden characterization of SettingsService::rebase().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/logs-and-statistics/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SettingsService;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Every rebase path recorded against a golden file: each statement prepared
 * and executed with its parameters, the result (timing left out), the log
 * lines, or the exception.
 *
 * Regenerate the golden file on the code it must describe with
 * REBASE_GOLDEN=1.
 */
class SettingsServiceRebaseTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/settings/rebase.json';

	/**
	 * Every database and log call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The cases: the stored retention JSON, the platform, and what fails.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$all = ['successLogRetention' => 1000, 'callLogRetention' => 2000, 'eventMessageRetention' => 3000, 'jobLogRetention' => 4000, 'syncContractLogRetention' => 5000, 'syncLogRetention' => 6000];

		return [
			'defaults-mysql' => ['retention' => ''],
			'all-set-mysql-with-expires-column' => ['retention' => json_encode($all), 'hasColumn' => true],
			'all-set-postgres-with-expires-column' => ['retention' => json_encode($all), 'hasColumn' => true, 'platform' => IDBConnection::PLATFORM_POSTGRES],
			'all-set-postgres-without-column' => ['retention' => json_encode($all), 'platform' => IDBConnection::PLATFORM_POSTGRES],
			'all-zero' => ['retention' => json_encode(array_map(fn () => 0, $all))],
			'some-negative-or-missing' => ['retention' => json_encode(['callLogRetention' => -1, 'jobLogRetention' => 10])],
			'not-json' => ['retention' => 'not json'],
			'call-log-update-fails' => ['retention' => json_encode($all), 'hasColumn' => true, 'failOn' => 'openconnector_call_logs'],
			'event-update-fails' => ['retention' => json_encode($all), 'hasColumn' => true, 'failOn' => 'UPDATE `*PREFIX*openconnector_event_messages`'],
			'column-check-fails' => ['retention' => json_encode($all), 'failOn' => 'SHOW COLUMNS'],
			'sync-logs-fail' => ['retention' => json_encode($all), 'failOn' => 'synchronization_logs'],
			'settings-unreadable' => ['retention' => null],
		];
	}//end cases()

	/**
	 * Run one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->log = [];
		$rows      = 0;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturnCallback(
			function () use ($case) {
				$this->log[] = ['db.getDatabaseProvider'];
				return ($case['platform'] ?? IDBConnection::PLATFORM_MYSQL);
			}
		);
		$db->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($case, &$rows) {
				$sql         = (string)preg_replace('/\s+/', ' ', trim($sql));
				$this->log[] = ['db.prepare', $sql];
				if (isset($case['failOn']) === true && str_contains($sql, $case['failOn']) === true) {
					throw new \RuntimeException('prepare failed on ' . $case['failOn']);
				}

				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturnCallback(
					function ($params = null) {
						$this->log[] = ['stmt.execute', $params];
						return $this->createMock(IResult::class);
					}
				);
				$statement->method('fetch')->willReturn((($case['hasColumn'] ?? false) === true) ? ['Field' => 'expires'] : false);
				$statement->method('rowCount')->willReturn(++$rows);
				return $statement;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		if ($case['retention'] === null) {
			$config->method('getValueString')->willThrowException(new \Exception('config store down'));
		} else {
			$config->method('getValueString')->willReturn($case['retention']);
		}

		$logger = new class($this->log) extends AbstractLogger {
			/**
			 * @param array $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * Record a log line without timing.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['duration'], $context['trace']);
				$this->log[] = ['logger.' . $level, (string)$message, $context];
			}
		};

		$result = [];
		try {
			$rebased = (new SettingsService($db, $config, $logger))->rebase();
			$this->assertInstanceOf(\DateTime::class, $rebased['startTime']);
			$this->assertInstanceOf(\DateTime::class, $rebased['endTime']);
			$this->assertMatchesRegularExpression('/^\d\d:\d\d:\d\d$/', $rebased['duration']);
			unset($rebased['startTime'], $rebased['endTime'], $rebased['duration']);
			$result['result'] = $rebased;
		} catch (\Throwable $e) {
			$result['exception'] = [get_class($e), $e->getMessage()];
		}

		$result['log'] = $this->log;

		return json_decode((string)json_encode($result), true);
	}//end runCase()

	/**
	 * Every case matches the golden record.
	 *
	 * @return void
	 */
	public function testEveryRebasePathMatchesTheGoldenRecord(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(case: $case);
		}

		if (getenv('REBASE_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($actual));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $actual[$name], $name);
		}
	}//end testEveryRebasePathMatchesTheGoldenRecord()
}//end class
