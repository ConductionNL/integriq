<?php

/**
 * Offset-mode pagination (CKAN, OData, Solr).
 *
 * The engine has only ever counted pages by INDEX — `page=1,2,3`. An offset
 * source wants the number of records to SKIP, and the failure when it is handed
 * an index instead is silent: `start=1,2,3` against a 100-row page returns
 * windows overlapping by 99 rows, so the crawl re-reads almost the same records,
 * terminates normally, and reports a healthy run that fetched a fraction of the
 * corpus. Nothing errors, so nothing draws attention to it.
 *
 * These pin the conversion, and — more importantly — pin that a page-indexed
 * source is byte-for-byte unchanged.
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
use ReflectionClass;
use ReflectionMethod;

class SynchronizationOffsetPaginationTest extends TestCase {
	private SynchronizationService $service;
	private ReflectionMethod $paginationValueFor;

	protected function setUp(): void {
		// The method under test reads only its arguments, so the collaborators
		// the constructor wires are irrelevant to it — and building them would
		// make a pure-function test depend on twenty mocks.
		$this->service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();

		$this->paginationValueFor = new ReflectionMethod(SynchronizationService::class, 'paginationValueFor');
		$this->paginationValueFor->setAccessible(true);
	}

	/**
	 * @param array $sourceConfig The source configuration.
	 * @param int $page The 1-based page.
	 *
	 * @return int The substituted cursor value.
	 */
	private function valueFor(array $sourceConfig, int $page): int {
		return $this->paginationValueFor->invoke($this->service, $sourceConfig, $page);
	}

	/**
	 * The regression guard that matters most: every source that does not opt in
	 * keeps the pre-existing behaviour exactly. This change touches the cursor
	 * of every synchronisation in the fleet.
	 *
	 * @param array $sourceConfig A configuration that does not ask for offsets.
	 */
	#[DataProvider('pageIndexedConfigurations')]
	public function testAPageIndexedSourceIsUnchanged(array $sourceConfig): void {
		$this->assertSame(1, $this->valueFor($sourceConfig, 1));
		$this->assertSame(2, $this->valueFor($sourceConfig, 2));
		$this->assertSame(47, $this->valueFor($sourceConfig, 47));
	}

	/**
	 * @return array<string, array{0: array}>
	 */
	public static function pageIndexedConfigurations(): array {
		return [
			'no pagination config at all' => [[]],
			'explicit page mode' => [['paginationMode' => 'page']],
			'page mode with a size' => [['paginationMode' => 'page', 'query' => ['per_page' => 100]]],
			'a size but no mode' => [['query' => ['per_page' => 100]]],
		];
	}

	/**
	 * CKAN's actual shape: `rows` is the page size, `start` the offset.
	 * data.overheid.nl holds 19,822 datasets, so this is the arithmetic the
	 * whole crawl depends on.
	 */
	public function testAnOffsetSourceSkipsWholePages(): void {
		$config = ['paginationMode' => 'offset', 'paginationQuery' => 'start', 'query' => ['rows' => 100]];

		$this->assertSame(0, $this->valueFor($config, 1), 'The first page skips nothing.');
		$this->assertSame(100, $this->valueFor($config, 2));
		$this->assertSame(200, $this->valueFor($config, 3));
		$this->assertSame(19800, $this->valueFor($config, 199));
	}

	/**
	 * An explicit `pageSize` wins over one inferred from the query, so a source
	 * whose size lives somewhere the engine cannot see can still be paged.
	 */
	public function testAnExplicitPageSizeIsUsed(): void {
		$config = ['paginationMode' => 'offset', 'pageSize' => 250, 'query' => ['rows' => 100]];

		$this->assertSame(250, $this->valueFor($config, 2));
	}

	/**
	 * A source counting from zero must not skip a page's worth of records
	 * before it reads anything — which is what subtracting a hardcoded 1 would
	 * do to it.
	 */
	public function testASourceThatCountsFromZeroStartsAtZero(): void {
		$config = [
			'paginationMode' => 'offset',
			'paginationFirstPage' => 0,
			'query' => ['rows' => 100],
		];

		$this->assertSame(0, $this->valueFor($config, 0));
		$this->assertSame(100, $this->valueFor($config, 1));
	}

	/**
	 * An offset needs a page size. A source that declares the mode without one
	 * has said how to count but not what to count in, and guessing would be
	 * worse than falling back — a wrong size silently skips or repeats records.
	 */
	public function testItFallsBackToThePageIndexWhenNoSizeIsKnown(): void {
		$config = ['paginationMode' => 'offset', 'paginationQuery' => 'start'];

		$this->assertSame(3, $this->valueFor($config, 3));
	}

	/**
	 * A zero or negative size is the same situation as none: unusable. It would
	 * otherwise compute offset 0 for every page and crawl page one forever.
	 *
	 * @param mixed $size The declared page size.
	 */
	#[DataProvider('unusableSizes')]
	public function testAnUnusablePageSizeFallsBackRatherThanCrawlingPageOneForever(mixed $size): void {
		$config = ['paginationMode' => 'offset', 'query' => ['rows' => $size]];

		$this->assertSame(3, $this->valueFor($config, 3));
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusableSizes(): array {
		return [
			'zero' => [0],
			'negative' => [-100],
			'not a number' => ['all'],
		];
	}

	/**
	 * The mode is read case-insensitively, because a source configuration is
	 * hand-written JSON and `"Offset"` meaning something different from
	 * `"offset"` is a trap with no upside.
	 */
	public function testTheModeIsCaseInsensitive(): void {
		$config = ['paginationMode' => 'OFFSET', 'query' => ['rows' => 100]];

		$this->assertSame(100, $this->valueFor($config, 2));
	}

	/**
	 * The wiring, not the arithmetic. A conversion that is correct but never
	 * reached is indistinguishable from one that is wrong, so this asserts that
	 * the value actually handed to the call config is the OFFSET — going
	 * through `getNextPage()`, the method the crawl calls.
	 */
	public function testTheOffsetIsWhatReachesTheCallConfig(): void {
		$getNextPage = new ReflectionMethod(SynchronizationService::class, 'getNextPage');
		$getNextPage->setAccessible(true);

		$config = $getNextPage->invoke(
			$this->service,
			[],
			['paginationMode' => 'offset', 'paginationQuery' => 'start', 'query' => ['rows' => 100]],
			3
		);

		$this->assertSame('start', $config['pagination']['paginationQuery']);
		$this->assertSame(200, $config['pagination']['page'], 'Page 3 of 100 skips 200 records.');
	}

	/**
	 * The page NUMBER travels alongside the substituted value, and for an offset
	 * source they are NOT the same thing.
	 *
	 * The prefetch cache is keyed by page number: the fan-out stores page 2, 3,
	 * 4. Once `page` began carrying an offset, the consumer looked up 100, 200,
	 * 300 and missed every time — so each prefetched page was then fetched
	 * AGAIN, serially. The fan-out did not merely stop helping, it added one
	 * wasted request per page, which is why enabling it measured SLOWER.
	 *
	 * Measured against data.overheid.nl before the fix: 199 pages in 140.7 s,
	 * 707 ms each — precisely the source's serial latency, with a concurrency
	 * window of 10 supposedly open.
	 */
	public function testThePageNumberTravelsSeparatelyFromTheOffset(): void {
		$getNextPage = new ReflectionMethod(SynchronizationService::class, 'getNextPage');
		$getNextPage->setAccessible(true);

		$config = $getNextPage->invoke(
			$this->service,
			[],
			['paginationMode' => 'offset', 'paginationQuery' => 'start', 'query' => ['rows' => 100]],
			3
		);

		$this->assertSame(200, $config['pagination']['page'], 'the source is sent an offset');
		$this->assertSame(3, $config['pagination']['index'], 'the cache is keyed by the page number');
	}

	/**
	 * For a page-indexed source the two are identical, so nothing about the
	 * pre-existing cache behaviour changes.
	 */
	public function testTheIndexEqualsThePageForAPageIndexedSource(): void {
		$getNextPage = new ReflectionMethod(SynchronizationService::class, 'getNextPage');
		$getNextPage->setAccessible(true);

		$config = $getNextPage->invoke($this->service, [], ['query' => ['per_page' => 100]], 7);

		$this->assertSame(7, $config['pagination']['page']);
		$this->assertSame(7, $config['pagination']['index']);
	}

	/**
	 * ...and the same route leaves a page-indexed source alone, which is the
	 * half that every existing synchronisation in the fleet depends on.
	 */
	public function testAPageIndexedSourceReachesTheCallConfigUnchanged(): void {
		$getNextPage = new ReflectionMethod(SynchronizationService::class, 'getNextPage');
		$getNextPage->setAccessible(true);

		$config = $getNextPage->invoke($this->service, [], ['query' => ['per_page' => 100]], 3);

		$this->assertSame('page', $config['pagination']['paginationQuery']);
		$this->assertSame(3, $config['pagination']['page']);
	}
}
