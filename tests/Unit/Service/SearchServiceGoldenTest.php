<?php

/**
 * Golden characterization of the SearchService query helpers.
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
 * @spec openspec/specs/mapping-and-search/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SearchService;
use PHPUnit\Framework\TestCase;

/**
 * Every input shape of parseQueryString() and unsetSpecialQueryParams(),
 * recorded against a golden file.
 *
 * Regenerate the golden file on the code it must describe with
 * SEARCH_SERVICE_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\SearchService
 */
class SearchServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/search/search-service-helpers.json';

	/**
	 * Query strings handed to parseQueryString().
	 *
	 * @return array<string, string>
	 */
	private function queryStrings(): array {
		return [
			'empty' => '',
			'single' => 'a=1',
			'two' => 'a=1&b=2',
			'no-value' => 'flag',
			'empty-value' => 'flag=',
			'encoded' => 'na%20me=va%26lue',
			'list' => 'tags[]=x&tags[]=y',
			'keyed' => 'filter[status]=open',
			'nested' => 'filter[a][b]=deep',
			'nested-list' => 'filter[a][]=one&filter[a][]=two',
			'repeat-overwrites' => 'a=1&a=2',
			'extra-equals' => 'a=1=2',
			'leading-bracket' => '[x]=1',
			'trailing-amp' => 'a=1&',
		];
	}//end queryStrings()

	/**
	 * Filter arrays handed to unsetSpecialQueryParams().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function filterSets(): array {
		return [
			'empty' => [],
			'plain' => ['a' => 1, 'b' => 'x'],
			'special' => ['_limit' => 10, '_page' => 2, 'name' => 'n'],
			'only-special' => ['_x' => 1],
			'inner-underscore' => ['a_b' => 1, '__c' => 2],
		];
	}//end filterSets()

	/**
	 * The outputs of every case as JSON text (some carry an empty key), keyed by
	 * method and case name.
	 *
	 * @return array<string, mixed>
	 */
	private function record(): array {
		$constructor = (new \ReflectionClass(SearchService::class))->getConstructor();
		$args = [];
		if ($constructor !== null) {
			foreach ($constructor->getParameters() as $param) {
				$args[] = $this->createMock((string)$param->getType());
			}
		}

		$service = new SearchService(...$args);
		$out = [];
		foreach ($this->queryStrings() as $name => $query) {
			$out['parseQueryString'][$name] = json_encode($service->parseQueryString(queryString: $query));
		}

		foreach ($this->filterSets() as $name => $filters) {
			$out['unsetSpecialQueryParams'][$name] = json_encode($service->unsetSpecialQueryParams(filters: $filters));
		}

		return $out;
	}//end record()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$encoded = json_encode($this->record(), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('SEARCH_SERVICE_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach (json_decode($encoded, true) as $method => $cases) {
			foreach ($cases as $name => $value) {
				$this->assertSame($golden[$method][$name], $value, $method . ' / ' . $name);
			}
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
