<?php

/**
 * Unit tests for ConnectionAlertService: thresholds open and clear connection alerts.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionAlertService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Counts per threshold, opens one alert above it, keeps it while above, clears it below.
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */
final class ConnectionAlertServiceTest extends TestCase {

	private const NOW = '2026-09-29T09:00:00+02:00';

	private const SOURCE = 'a1b2c3d4-0000-4000-8000-00000000c0de';

	/**
	 * Objects per schema the fake object service holds.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $store = [];

	/**
	 * Every saveObject() call: [schema, payload, uuid].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $saves = [];

	/**
	 * The schemas findAll() was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * Build the service over the fake store.
	 *
	 * @return ConnectionAlertService
	 */
	private function makeService(): ConnectionAlertService {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$this->reads[] = $filters['schema'];
				$rows = [];
				foreach (($this->store[$filters['schema']] ?? []) as $entity) {
					$body = $entity->getObject();
					$match = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($body[$key] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$rows[] = $entity;
					}
				}

				return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 1000));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, $register = null, $schema = null, $uuid = null): ObjectEntity {
				$this->saves[] = [(string)$schema, $object, $uuid];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'alert-new');
				return $entity;
			}
		);

		return new ConnectionAlertService($objects, $this->createMock(LoggerInterface::class));
	}//end makeService()

	/**
	 * A source with a failed-calls threshold of 10 in 60 minutes, and N failed calls in the last hour.
	 *
	 * @param int $failed Failed calls within the window.
	 *
	 * @return void
	 */
	private function sourceWithFailedCalls(int $failed): void {
		$this->store['source'] = [
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['name' => 'KVK', 'alertThresholds' => ['failedCalls' => ['count' => 10, 'windowMinutes' => 60]]],
				self::SOURCE
			),
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'No thresholds'], 'source-quiet'),
		];

		$calls = [];
		for ($i = 0; $i < $failed; $i++) {
			$calls[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['source' => self::SOURCE, 'statusCode' => 500, 'created' => '2026-09-29T08:' . sprintf('%02d', 50 - $i) . ':00+02:00'],
				'call-' . $i
			);
		}

		// Two successful calls and one old failure do not count.
		$calls[] = ObjectServiceMockBuilder::objectEntity($this, ['source' => self::SOURCE, 'statusCode' => 200, 'created' => '2026-09-29T08:55:00+02:00'], 'call-ok-1');
		$calls[] = ObjectServiceMockBuilder::objectEntity($this, ['source' => self::SOURCE, 'statusCode' => 204, 'created' => '2026-09-29T08:54:00+02:00'], 'call-ok-2');
		$calls[] = ObjectServiceMockBuilder::objectEntity($this, ['source' => self::SOURCE, 'statusCode' => 502, 'created' => '2026-09-29T06:00:00+02:00'], 'call-old');

		$this->store['call_log'] = $calls;
	}//end sourceWithFailedCalls()

	/**
	 * Eleven failed calls in the hour open one alert with count 11, and the register takes it.
	 *
	 * @return void
	 */
	public function testElevenFailedCallsOpenOneAlert(): void {
		$this->sourceWithFailedCalls(11);

		$this->makeService()->evaluate(now: new DateTimeImmutable(self::NOW));

		$this->assertCount(1, $this->saves);
		[$schema, $alert, $uuid] = $this->saves[0];
		$this->assertSame('connection_alert', $schema);
		$this->assertNull($uuid);
		$this->assertSame('open', $alert['state']);
		$this->assertSame(11, $alert['count']);
		$this->assertSame(10, $alert['threshold']);
		$this->assertSame(self::SOURCE, $alert['subject']);
		$this->assertSame('KVK', $alert['subjectName']);
		$this->assertSame('failedCalls', $alert['rule']);
		$this->assertSame([], RegisterSchemaValidator::errors('connection_alert', $alert));
	}//end testElevenFailedCallsOpenOneAlert()

	/**
	 * Ten failed calls do not pass a threshold of ten.
	 *
	 * @return void
	 */
	public function testTenFailedCallsOpenNothing(): void {
		$this->sourceWithFailedCalls(10);

		$this->makeService()->evaluate(now: new DateTimeImmutable(self::NOW));

		$this->assertSame([], $this->saves);
	}//end testTenFailedCallsOpenNothing()

	/**
	 * While an alert is open and the count stays above, no second alert opens.
	 *
	 * @return void
	 */
	public function testAnOpenAlertDoesNotRepeat(): void {
		$this->sourceWithFailedCalls(12);
		$this->store['connection_alert'] = [
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['subjectType' => 'source', 'subject' => self::SOURCE, 'rule' => 'failedCalls', 'state' => 'open', 'count' => 11, 'threshold' => 10, 'windowMinutes' => 60, 'openedAt' => '2026-09-29T08:55:00+02:00'],
				'alert-1'
			),
		];

		$this->makeService()->evaluate(now: new DateTimeImmutable(self::NOW));

		$this->assertSame([], $this->saves);
	}//end testAnOpenAlertDoesNotRepeat()

	/**
	 * An open alert clears once the count falls back, and the register takes the cleared alert.
	 *
	 * @return void
	 */
	public function testAnOpenAlertClearsWhenTheCountFallsBack(): void {
		$this->sourceWithFailedCalls(3);
		$this->store['connection_alert'] = [
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['subjectType' => 'source', 'subject' => self::SOURCE, 'rule' => 'failedCalls', 'state' => 'open', 'count' => 11, 'threshold' => 10, 'windowMinutes' => 60, 'openedAt' => '2026-09-29T08:00:00+02:00'],
				'alert-1'
			),
		];

		$this->makeService()->evaluate(now: new DateTimeImmutable(self::NOW));

		$this->assertCount(1, $this->saves);
		[$schema, $alert, $uuid] = $this->saves[0];
		$this->assertSame('alert-1', $uuid);
		$this->assertSame('cleared', $alert['state']);
		$this->assertArrayHasKey('clearedAt', $alert);
		$this->assertSame([], RegisterSchemaValidator::errors('connection_alert', $alert));
	}//end testAnOpenAlertClearsWhenTheCountFallsBack()

	/**
	 * Failed runs and invalid objects on a synchronization count from its run records.
	 *
	 * @return void
	 */
	public function testASynchronizationCountsFailedRunsAndInvalidObjects(): void {
		$this->store['synchronization'] = [
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['name' => 'KVK pull', 'alertThresholds' => ['failedRuns' => ['count' => 1, 'windowMinutes' => 120], 'invalidObjects' => ['count' => 50, 'windowMinutes' => 120]]],
				'sync-1'
			),
		];
		$this->store['synchronization_run'] = [
			ObjectServiceMockBuilder::objectEntity($this, ['synchronizationId' => 'sync-1', 'status' => 'failed', 'startedAt' => '2026-09-29T08:30:00+02:00', 'invalid' => 30], 'run-1'),
			ObjectServiceMockBuilder::objectEntity($this, ['synchronizationId' => 'sync-1', 'status' => 'failed', 'startedAt' => '2026-09-29T08:00:00+02:00', 'invalid' => 30], 'run-2'),
			ObjectServiceMockBuilder::objectEntity($this, ['synchronizationId' => 'sync-1', 'status' => 'failed', 'startedAt' => '2026-09-28T08:00:00+02:00', 'invalid' => 900], 'run-old'),
		];

		$this->makeService()->evaluate(now: new DateTimeImmutable(self::NOW));

		$rules = array_map(static fn (array $save): string => $save[1]['rule'], $this->saves);
		sort($rules);
		$this->assertSame(['failedRuns', 'invalidObjects'], $rules);
		foreach ($this->saves as [$schema, $alert]) {
			$this->assertSame('synchronization', $alert['subjectType']);
			$this->assertSame([], RegisterSchemaValidator::errors('connection_alert', $alert));
		}
	}//end testASynchronizationCountsFailedRunsAndInvalidObjects()

	/**
	 * With no thresholds anywhere, nothing but the subjects is read.
	 *
	 * @return void
	 */
	public function testNoThresholdsCountsNothing(): void {
		$this->store['source'] = [ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Quiet'], 'source-quiet')];

		$this->makeService()->evaluate(now: new DateTimeImmutable(self::NOW));

		$this->assertSame(['source', 'synchronization'], $this->reads);
		$this->assertSame([], $this->saves);
	}//end testNoThresholdsCountsNothing()
}//end class
