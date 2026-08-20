<?php

/**
 * Learning the page count from a total reported in the response BODY.
 *
 * The concurrency machinery needs exactly one fact to fan out: how many pages
 * there are. It could only ever learn that from an RFC 5988 `rel="last"`
 * header, which most APIs do not send — they put the total in the payload.
 *
 * Without it the crawl is serial. Measured 2026-08-14 against data.overheid.nl:
 * 20 pages, 13,519 ms, 676 ms each back to back — exactly the source's own
 * latency, nothing overlapped, while the count sat in page one's body.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class SynchronizationBodyTotalPageCountTest extends TestCase {
	private SynchronizationService $service;
	private ReflectionMethod $record;
	private ReflectionProperty $lastPage;

	protected function setUp(): void {
		$this->service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();

		$logger = new ReflectionProperty(SynchronizationService::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($this->service, $this->createMock(LoggerInterface::class));

		$this->record = new ReflectionMethod(SynchronizationService::class, 'recordLastPageFromBody');
		$this->record->setAccessible(true);

		$this->lastPage = new ReflectionProperty(SynchronizationService::class, 'lastPageFromLink');
		$this->lastPage->setAccessible(true);
		$this->lastPage->setValue($this->service, null);
	}

	/**
	 * @param array $body The decoded response body.
	 * @param array $sourceConfig The source configuration.
	 *
	 * @return int|null The learned page count.
	 */
	private function learn(array $body, array $sourceConfig): ?int {
		$this->record->invoke($this->service, $body, ['sourceConfig' => $sourceConfig]);

		return $this->lastPage->getValue($this->service);
	}

	/**
	 * CKAN's actual shape, and the number this whole exercise turns on:
	 * data.overheid.nl reports 19,822 datasets, which at 100 a page is 199.
	 */
	public function testItLearnsThePageCountFromACkanBody(): void {
		$pages = $this->learn(
			['result' => ['count' => 19822, 'results' => []]],
			['totalPosition' => 'result.count', 'query' => ['rows' => 100]]
		);

		$this->assertSame(199, $pages);
	}

	/**
	 * A partial final page still needs fetching, so the count rounds UP. Rounding
	 * down would silently drop the tail — the last 22 datasets, in CKAN's case,
	 * on a run that reported success.
	 */
	public function testAPartialFinalPageIsCounted(): void {
		$this->assertSame(
			3,
			$this->learn(['total' => 201], ['totalPosition' => 'total', 'query' => ['limit' => 100]])
		);
	}

	/**
	 * The same fact under other vendors' names — nothing here is CKAN-specific,
	 * because the dot-path is configuration rather than a hardcoded shape.
	 *
	 * @param string $position The dot-path to the total.
	 * @param array $body The body carrying it.
	 */
	#[DataProvider('vendorShapes')]
	public function testItReadsAnyDotPath(string $position, array $body): void {
		$this->assertSame(5, $this->learn($body, ['totalPosition' => $position, 'query' => ['rows' => 100]]));
	}

	/**
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function vendorShapes(): array {
		return [
			'CKAN' => ['result.count', ['result' => ['count' => 500]]],
			'OData' => ['@odata.count', ['@odata.count' => 500]],
			'Spring Data' => ['page.totalElements', ['page' => ['totalElements' => 500]]],
			'Solr' => ['response.numFound', ['response' => ['numFound' => 500]]],
			'a numeric string' => ['total', ['total' => '500']],
		];
	}

	/**
	 * OPT-IN ONLY. Guessing a total out of an arbitrary payload would eventually
	 * find a number that means something else, and a wrong page count reads
	 * ahead into pages that do not exist.
	 */
	public function testItDoesNothingWithoutTotalPosition(): void {
		$this->assertNull($this->learn(['result' => ['count' => 19822]], ['query' => ['rows' => 100]]));
	}

	/**
	 * A total without a page size cannot become a page count. Guessing a size
	 * would be worse than staying serial — a wrong one skips or repeats pages.
	 */
	public function testItNeedsAPageSize(): void {
		$this->assertNull($this->learn(['total' => 19822], ['totalPosition' => 'total']));
	}

	/**
	 * The first answer wins, and a header already read is never overwritten.
	 * A filtered search that recomputes its total per page would otherwise move
	 * the finish line mid-crawl.
	 */
	public function testAKnownCountIsNeverOverwritten(): void {
		$this->lastPage->setValue($this->service, 4);

		$this->assertSame(
			4,
			$this->learn(['total' => 19822], ['totalPosition' => 'total', 'query' => ['rows' => 100]])
		);
	}

	/**
	 * Anything that is not a usable number leaves the crawl exactly as it was:
	 * serial, which is the pre-existing behaviour and always correct.
	 *
	 * @param mixed $total The value found at the dot-path.
	 */
	#[DataProvider('unusableTotals')]
	public function testAnUnusableTotalFallsBackToSerial(mixed $total): void {
		$this->assertNull(
			$this->learn(['total' => $total], ['totalPosition' => 'total', 'query' => ['rows' => 100]])
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusableTotals(): array {
		return [
			'absent' => [null],
			'not a number' => ['many'],
			'zero' => [0],
			'negative' => [-1],
			'an array' => [[1, 2, 3]],
		];
	}

	/**
	 * A path that does not exist in this body must not be read as a total.
	 */
	public function testAMissingPathIsNotATotal(): void {
		$this->assertNull(
			$this->learn(['result' => []], ['totalPosition' => 'result.count', 'query' => ['rows' => 100]])
		);
	}
}
