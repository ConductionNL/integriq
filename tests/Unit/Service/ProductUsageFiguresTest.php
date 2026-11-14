<?php

/**
 * Unit tests for ProductUsageFigures — usage figures over call_log rows, in
 * total and per consumer or endpoint.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ProductUsageFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Usage figures and their split per row field.
 *
 * @spec openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md#requirement-product-usage-is-split-per-consumer-and-per-endpoint-req-apgu-001
 */
#[CoversClass(ProductUsageFigures::class)]
class ProductUsageFiguresTest extends TestCase {

	/**
	 * Rows for one consumer or endpoint.
	 *
	 * @param string   $field  The field to set.
	 * @param string   $id     Its value.
	 * @param int[]    $codes  One status code per row.
	 * @param int|null $timeMs The response time of every row, or null for none.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $field, string $id, array $codes, ?int $timeMs = 50): array {
		$rows = [];
		foreach ($codes as $code) {
			$row = [$field => $id, 'statusCode' => $code];
			if ($timeMs !== null) {
				$row['responseTime'] = $timeMs;
			}

			$rows[] = $row;
		}

		return $rows;
	}//end rows()

	/**
	 * No rows: zero, not an error.
	 *
	 * @return void
	 */
	public function testNoRowsAreZero(): void {
		$figures = new ProductUsageFigures();

		$this->assertSame(
			['requestCount' => 0, 'errorRate' => 0, 'latency' => ['p50' => 0.0, 'p95' => 0.0, 'p99' => 0.0]],
			$figures->figures(rows: [])
		);
		$this->assertSame([], $figures->breakdown(rows: [], field: 'consumer'));
	}//end testNoRowsAreZero()

	/**
	 * The totals count 4xx and 5xx as errors and take nearest-rank percentiles;
	 * a row without a response time counts as a request only.
	 *
	 * @return void
	 */
	public function testTheTotalsCountErrorsAndTakeNearestRankPercentiles(): void {
		$rows = [];
		foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $i => $ms) {
			$rows[] = ['statusCode' => [200, 404, 503][$i % 3] ?? 200, 'responseTime' => $ms];
		}

		$rows[] = ['statusCode' => 200];
		$rows[] = ['statusCode' => 399, 'responseTime' => 'n/a'];

		$result = (new ProductUsageFigures())->figures(rows: $rows);

		$this->assertSame(12, $result['requestCount']);
		$this->assertSame(0.5, $result['errorRate']);
		$this->assertSame(['p50' => 50.0, 'p95' => 100.0, 'p99' => 100.0], $result['latency']);
	}//end testTheTotalsCountErrorsAndTakeNearestRankPercentiles()

	/**
	 * The scenario of two consumers, one failing one time in five: each has
	 * its own figures, the busiest first, and they add up to the total.
	 *
	 * @return void
	 */
	public function testEachConsumerHasItsOwnFiguresAndTheyAddUpToTheTotal(): void {
		$rows = array_merge(
			$this->rows(field: 'consumer', id: 'quiet', codes: [200, 200, 200]),
			$this->rows(field: 'consumer', id: 'partner', codes: [200, 500, 200, 200, 200, 200, 200, 200, 200, 200])
		);
		$figures = new ProductUsageFigures();

		$entries = $figures->breakdown(rows: $rows, field: 'consumer');

		$this->assertSame(['partner', 'quiet'], array_column($entries, 'id'));
		$this->assertSame([10, 3], array_column($entries, 'requestCount'));
		$this->assertSame([0.1, 0.0], array_column($entries, 'errorRate'));
		$this->assertSame(
			$figures->figures(rows: $rows)['requestCount'],
			array_sum(array_column($entries, 'requestCount'))
		);
	}//end testEachConsumerHasItsOwnFiguresAndTheyAddUpToTheTotal()

	/**
	 * The scenario of a slow endpoint: three endpoints, highest request count
	 * first, the slow one with its own p95.
	 *
	 * @return void
	 */
	public function testTheSlowEndpointShowsItsOwnP95(): void {
		$rows = array_merge(
			$this->rows(field: 'endpoint', id: 'fast-a', codes: [200, 200], timeMs: 80),
			$this->rows(field: 'endpoint', id: 'slow', codes: [200, 200, 200, 200], timeMs: 2000),
			$this->rows(field: 'endpoint', id: 'fast-b', codes: [200, 200, 200], timeMs: 40)
		);

		$entries = (new ProductUsageFigures())->breakdown(rows: $rows, field: 'endpoint');

		$this->assertSame(['slow', 'fast-b', 'fast-a'], array_column($entries, 'id'));
		$this->assertSame([2000.0, 40.0, 80.0], array_map(static fn (array $e): float => $e['latency']['p95'], $entries));
	}//end testTheSlowEndpointShowsItsOwnP95()

	/**
	 * Rows without the field are one entry with a null id, so nothing drops
	 * out of the sum; equal counts keep the order the ids first occur in.
	 *
	 * @return void
	 */
	public function testRowsWithoutTheFieldFormANullEntryAndTiesKeepTheirOrder(): void {
		$rows = [
			['consumer' => 'b', 'statusCode' => 200],
			['statusCode' => 200],
			['consumer' => '', 'statusCode' => 429],
			['consumer' => 'a', 'statusCode' => 200],
			['consumer' => 'b', 'statusCode' => 200],
			['consumer' => 'a', 'statusCode' => 200],
		];

		$entries = (new ProductUsageFigures())->breakdown(rows: $rows, field: 'consumer');

		$this->assertSame(['b', null, 'a'], array_column($entries, 'id'));
		$this->assertSame([2, 2, 2], array_column($entries, 'requestCount'));
		$this->assertSame(0.5, $entries[1]['errorRate']);
	}//end testRowsWithoutTheFieldFormANullEntryAndTiesKeepTheirOrder()
}//end class
