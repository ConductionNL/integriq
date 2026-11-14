<?php

/**
 * Integriq Product Usage Figures.
 *
 * Turns inbound `call_log` rows into usage figures: the request count, the
 * error rate and the p50/p95/p99 response times, for all rows together and
 * split per value of one row field (the consumer, the endpoint).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md#requirement-product-usage-is-split-per-consumer-and-per-endpoint-req-apgu-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Usage figures over call_log rows.
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007
 */
class ProductUsageFigures {

	/**
	 * The figures of a set of rows: request count, error rate and percentiles.
	 *
	 * A row with a status code of 400 or more is an error. Rows without a
	 * numeric `responseTime` count as requests but not towards the percentiles.
	 *
	 * @param array<int, array<string, mixed>> $rows The call_log rows.
	 *
	 * @return array{requestCount: int, errorRate: int|float, latency: array{p50: float, p95: float, p99: float}}
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007
	 */
	public function figures(array $rows): array {
		$requestCount = count($rows);
		$errorCount = 0;
		$responseTimes = [];

		foreach ($rows as $row) {
			if ((int)($row['statusCode'] ?? 0) >= 400) {
				$errorCount++;
			}

			if (isset($row['responseTime']) === true && is_numeric($row['responseTime']) === true) {
				$responseTimes[] = (float)$row['responseTime'];
			}
		}

		sort($responseTimes);

		$errorRate = 0;
		if ($requestCount > 0) {
			$errorRate = round(($errorCount / $requestCount), 4);
		}

		return [
			'requestCount' => $requestCount,
			'errorRate' => $errorRate,
			'latency' => [
				'p50' => $this->percentile(sortedValues: $responseTimes, fraction: 0.5),
				'p95' => $this->percentile(sortedValues: $responseTimes, fraction: 0.95),
				'p99' => $this->percentile(sortedValues: $responseTimes, fraction: 0.99),
			],
		];
	}//end figures()

	/**
	 * The figures per value of one row field, highest request count first.
	 *
	 * Only values that occur in the rows get an entry, so a consumer or an
	 * endpoint without traffic does not appear. Rows without the field are
	 * grouped under a `null` id, so the entries always add up to the total.
	 * Equal request counts keep the order in which the ids first occur.
	 *
	 * @param array<int, array<string, mixed>> $rows  The call_log rows.
	 * @param string                           $field The row field to split on, e.g. `consumer`.
	 *
	 * @return list<array{id: string|null, requestCount: int, errorRate: int|float, latency: array{p50: float, p95: float, p99: float}}>
	 *
	 * @spec openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md#requirement-product-usage-is-split-per-consumer-and-per-endpoint-req-apgu-001
	 */
	public function breakdown(array $rows, string $field): array {
		$groups = [];
		foreach ($rows as $row) {
			$value = $row[$field] ?? null;
			$key = '';
			if (is_string($value) === true && $value !== '') {
				$key = 'id:' . $value;
			}

			$groups[$key][] = $row;
		}

		$entries = [];
		foreach ($groups as $key => $groupRows) {
			$id = null;
			if ($key !== '') {
				$id = substr((string)$key, 3);
			}

			$entries[] = ['id' => $id] + $this->figures(rows: $groupRows);
		}

		usort(
			$entries,
			static fn (array $a, array $b): int => ($b['requestCount'] <=> $a['requestCount'])
		);

		return $entries;
	}//end breakdown()

	/**
	 * Nearest-rank percentile of a pre-sorted (ascending) value list.
	 *
	 * @param array<int, float> $sortedValues Ascending-sorted values.
	 * @param float             $fraction     The percentile as a fraction (e.g. 0.95 for p95).
	 *
	 * @return float The percentile value, or 0 when the list is empty (REQ-APG-007 "zero, not an error").
	 */
	private function percentile(array $sortedValues, float $fraction): float {
		$count = count($sortedValues);
		if ($count === 0) {
			return 0.0;
		}

		$index = ((int)ceil($fraction * $count) - 1);
		$index = max(0, min(($count - 1), $index));

		return (float)$sortedValues[$index];
	}//end percentile()
}//end class
