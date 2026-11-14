<?php

/**
 * Unit tests for a page of learning material search results.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\MaterialRecord;
use OCA\Integriq\Adapters\LearningMaterial\SearchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The summary, its fallbacks and merging facets.
 */
#[CoversClass(SearchResult::class)]
#[UsesClass(MaterialRecord::class)]
class SearchResultTest extends TestCase {
	/**
	 * @return void
	 */
	public function testAMissingSummaryFallsBackToTheRecords(): void {
		$result = new SearchResult(summary: [], records: [new MaterialRecord(['title' => 'a']), new MaterialRecord(['title' => 'b'])]);

		$this->assertSame(2, $result->total);
		$this->assertSame(1, $result->page);
		$this->assertSame(2, $result->pageSize);
		$this->assertSame([], $result->facets);
	}//end testAMissingSummaryFallsBackToTheRecords()

	/**
	 * @return void
	 */
	public function testToArrayCarriesTheSummaryAndTheRecordsAsArrays(): void {
		$result = new SearchResult(summary: ['total' => '242', 'page' => 3, 'pageSize' => 10, 'facets' => ['level' => [['value' => 'VO', 'count' => 38]]]], records: [new MaterialRecord(['title' => 'a'])]);
		$array = $result->toArray();

		$this->assertSame(242, $array['total']);
		$this->assertSame(3, $array['page']);
		$this->assertSame(10, $array['pageSize']);
		$this->assertSame(['level' => [['value' => 'VO', 'count' => 38]]], $array['facets']);
		$this->assertCount(1, $array['records']);
		$this->assertSame('a', $array['records'][0]['title']);
	}//end testToArrayCarriesTheSummaryAndTheRecordsAsArrays()

	/**
	 * @return void
	 */
	public function testWithFacetsAddsNewFacetsAndKeepsTheRest(): void {
		$records = [new MaterialRecord(['title' => 'a'])];
		$result = new SearchResult(summary: ['total' => 50, 'page' => 2, 'pageSize' => 1, 'facets' => ['level' => ['old'], 'format' => ['pdf']]], records: $records);
		$merged = $result->withFacets(facets: ['level' => ['new'], 'collection' => ['x']]);

		$this->assertNotSame($result, $merged);
		$this->assertSame(['level' => ['new'], 'collection' => ['x'], 'format' => ['pdf']], $merged->facets);
		$this->assertSame(50, $merged->total);
		$this->assertSame(2, $merged->page);
		$this->assertSame(1, $merged->pageSize);
		$this->assertSame($records, $merged->records);
		$this->assertSame(['level' => ['old'], 'format' => ['pdf']], $result->facets);
	}//end testWithFacetsAddsNewFacetsAndKeepsTheRest()
}//end class
