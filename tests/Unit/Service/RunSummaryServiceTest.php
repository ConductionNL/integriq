<?php

/**
 * Unit tests for RunSummaryService: a source's pulls per day.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Service\RunSummaryService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Sums the source's run records per day over a window of at most 31 days.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */
final class RunSummaryServiceTest extends TestCase {

	/**
	 * The findAll() configs the service asked for.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * Fourteen runs over seven days, two a day, the second one on two days failed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fourteenRuns(): array {
		$runs = [];
		for ($day = 0; $day < 7; $day++) {
			$date = (new DateTimeImmutable('2026-09-28T00:00:00+02:00'))->modify('-' . $day . ' days')->format('Y-m-d');
			$runs[] = ['synchronizationId' => 'sync-1', 'sourceId' => 'source-kvk', 'status' => 'success', 'startedAt' => $date . 'T14:00:00+02:00', 'found' => 10, 'created' => 2, 'updated' => 3, 'invalid' => 0];
			$status = 'success';
			if ($day === 1 || $day === 4) {
				$status = 'failed';
			}

			$runs[] = ['synchronizationId' => 'sync-1', 'sourceId' => 'source-kvk', 'status' => $status, 'startedAt' => $date . 'T02:00:00+02:00', 'found' => 5, 'created' => 1, 'updated' => 0, 'invalid' => 1];
		}

		return $runs;
	}//end fourteenRuns()

	/**
	 * Build the service over run records, newest first, handed out in pages.
	 *
	 * @param array<int, array<string, mixed>> $runs The run records.
	 *
	 * @return RunSummaryService
	 */
	private function makeService(array $runs): RunSummaryService {
		$entities = [];
		foreach ($runs as $index => $run) {
			$entities[] = ObjectServiceMockBuilder::objectEntity($this, $run, 'run-' . $index);
		}

		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($entities): array {
				$this->queries[] = $config;
				return array_slice($entities, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 0));
			}
		);

		return new RunSummaryService($objects);
	}//end makeService()

	/**
	 * Fourteen runs over seven days come back as seven rows adding up to fourteen, two failed.
	 *
	 * @return void
	 */
	public function testSevenDaysOfPullsAddUpToFourteenRuns(): void {
		$summary = $this->makeService($this->fourteenRuns())->summarise(
			sourceId: 'source-kvk',
			from: new DateTimeImmutable('2026-09-22'),
			to: new DateTimeImmutable('2026-09-28')
		);

		$this->assertCount(7, $summary['days']);
		$this->assertSame(14, array_sum(array_column($summary['days'], 'runs')));
		$this->assertSame(2, array_sum(array_column($summary['days'], 'failed')));
		$this->assertSame(12, array_sum(array_column($summary['days'], 'succeeded')));
		$this->assertSame(105, array_sum(array_column($summary['days'], 'found')));
		$this->assertSame(7, array_sum(array_column($summary['days'], 'invalid')));
		$this->assertSame('2026-09-28', $summary['days'][0]['date']);
		$this->assertSame('source-kvk', $this->queries[0]['filters']['sourceId']);
	}//end testSevenDaysOfPullsAddUpToFourteenRuns()

	/**
	 * A day without a pull still has a row, with zeros.
	 *
	 * @return void
	 */
	public function testADayWithoutAPullShowsZeros(): void {
		$summary = $this->makeService([])->summarise(
			sourceId: 'source-kvk',
			from: new DateTimeImmutable('2026-09-26'),
			to: new DateTimeImmutable('2026-09-28')
		);

		$this->assertCount(3, $summary['days']);
		$this->assertSame(0, $summary['days'][2]['runs']);
		$this->assertSame([], $summary['runs']);
	}//end testADayWithoutAPullShowsZeros()

	/**
	 * Runs outside the window are not counted, and the read stops at the window's start.
	 *
	 * @return void
	 */
	public function testRunsBeforeTheWindowAreLeftOut(): void {
		$summary = $this->makeService($this->fourteenRuns())->summarise(
			sourceId: 'source-kvk',
			from: new DateTimeImmutable('2026-09-27'),
			to: new DateTimeImmutable('2026-09-28')
		);

		$this->assertSame(4, array_sum(array_column($summary['days'], 'runs')));
		$this->assertCount(4, $summary['runs']);
		$this->assertSame('run-0', $summary['runs'][0]['id']);
	}//end testRunsBeforeTheWindowAreLeftOut()

	/**
	 * A window longer than 31 days is refused, naming the limit.
	 *
	 * @return void
	 */
	public function testAWindowLongerThanThirtyOneDaysIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('31');

		$this->makeService([])->summarise(
			sourceId: 'source-kvk',
			from: new DateTimeImmutable('2026-01-01'),
			to: new DateTimeImmutable('2026-06-30')
		);
	}//end testAWindowLongerThanThirtyOneDaysIsRefused()
}//end class
