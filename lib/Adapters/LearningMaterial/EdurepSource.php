<?php

/**
 * Integriq Edurep learning material source.
 *
 * Searches and finds open learning material in Edurep (Kennisnet) over SRU:
 * small pages, facet counts on licence, collection, level and format, and
 * one record by its identifier. A facet whose group has a filter set is counted
 * by its own drilldown call without that filter, so its options stay visible.
 * The transport decides live or recorded.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use Psr\Log\LoggerInterface;

/**
 * Edurep over an SRU transport.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */
class EdurepSource implements LearningMaterialSource {
	public const PAGE_SIZE = 10;
	public const MAX_PAGE_SIZE = 50;

	/**
	 * Each facet's index and how many values to ask for.
	 *
	 * @var array<string,int>
	 */
	public const FACET_LIMITS = [
		'lom.rights.copyrightandotherrestrictions' => 20,
		'meta.repository.id' => 20,
		'lom.educational.context' => 12,
		'lom.technical.format' => 12,
	];

	/**
	 * The filter keys that narrow each facet's index.
	 *
	 * @var array<string,list<string>>
	 */
	public const FACET_FILTERS = [
		'lom.rights.copyrightandotherrestrictions' => ['licenses', 'excludeLicenses'],
		'meta.repository.id' => ['collection', 'excludeCollections'],
		'lom.educational.context' => ['levels'],
		'lom.technical.format' => ['formats', 'excludeFormats'],
	];

	/**
	 * The paging of a drilldown call: counts only, no records.
	 *
	 * @var array{page: int, pageSize: int}
	 */
	private const COUNTS_ONLY = ['page' => 1, 'pageSize' => 0];

	/**
	 * The cql.
	 *
	 * @var EdurepCqlBuilder
	 */
	private readonly EdurepCqlBuilder $cql;

	/**
	 * The reader.
	 *
	 * @var SruResponseReader
	 */
	private readonly SruResponseReader $reader;

	/**
	 * Constructor.
	 *
	 * @param SruTransport $transport Sends the SRU requests.
	 * @param LoggerInterface $logger Logs skipped records.
	 */
	public function __construct(
		private readonly SruTransport $transport,
		LoggerInterface $logger,
	) {
		$this->cql = new EdurepCqlBuilder();
		$this->reader = new SruResponseReader(logger: $logger);
	}//end __construct()

	/**
	 * Search Edurep: one call, plus one counts-only call per filter group with a filter set.
	 *
	 * @param string $query The user's words.
	 * @param array<string,mixed> $filters The normalised filters, `pageSize` included.
	 * @param int $page The page, from 1.
	 *
	 * @return SearchResult The page with its total and facet counts.
	 *
	 * @throws SourceUnavailableException When Edurep does not answer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function search(string $query, array $filters, int $page): SearchResult {
		$paging = ['page' => max(1, $page), 'pageSize' => self::pageSize(requested: $filters['pageSize'] ?? null)];
		$parameters = self::parameters(query: $this->cql->search(query: $query, filters: $filters), paging: $paging);
		if (($filters['facets'] ?? true) === false) {
			return $this->request(parameters: $parameters, paging: $paging);
		}

		$narrowed = self::narrowedFacets(filters: $filters);
		$parameters += array_filter(['x-term-drilldown' => self::drilldown(limits: array_diff_key(self::FACET_LIMITS, $narrowed))]);
		$result = $this->request(parameters: $parameters, paging: $paging);

		return $result->withFacets(facets: $this->countsWithoutOwnFilter(search: ['query' => $query, 'filters' => $filters], narrowed: $narrowed));
	}//end search()

	/**
	 * Find one Edurep record by its identifier.
	 *
	 * @param string $id The record identifier.
	 *
	 * @return MaterialRecord|null The record, or null when Edurep has none.
	 *
	 * @throws SourceUnavailableException When Edurep does not answer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-find-returns-one-record-by-its-identifier
	 */
	public function find(string $id): ?MaterialRecord {
		if (trim($id) === '') {
			return null;
		}

		$paging = ['page' => 1, 'pageSize' => 1];
		$result = $this->request(parameters: self::parameters(query: $this->cql->find(id: $id), paging: $paging), paging: $paging);

		return ($result->records[0] ?? null);
	}//end find()

	/**
	 * The page size, between 1 and 50.
	 *
	 * @param mixed $requested The requested size.
	 *
	 * @return int The size.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public static function pageSize(mixed $requested): int {
		if (is_numeric($requested) === false) {
			return self::PAGE_SIZE;
		}

		return min(self::MAX_PAGE_SIZE, max(1, (int)$requested));
	}//end pageSize()

	/**
	 * Count each narrowed facet with its own filter left out and every other filter kept.
	 *
	 * @param array{query: string, filters: array<string,mixed>} $search The search.
	 * @param array<string,list<string>> $narrowed The narrowed facets with their filter keys.
	 *
	 * @return array<string,list<array{value: string, count: int}>> Facet name to its values with counts.
	 *
	 * @throws SourceUnavailableException When Edurep does not answer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-filter-groups-counts-leave-out-its-own-filter
	 */
	private function countsWithoutOwnFilter(array $search, array $narrowed): array {
		$facets = [];
		foreach ($narrowed as $index => $keys) {
			$filters = array_diff_key($search['filters'], array_flip($keys));
			$parameters = self::parameters(query: $this->cql->search(query: $search['query'], filters: $filters), paging: self::COUNTS_ONLY);
			$parameters['x-term-drilldown'] = self::drilldown(limits: [$index => self::FACET_LIMITS[$index]]);
			$facets[$index] = ($this->request(parameters: $parameters, paging: self::COUNTS_ONLY)->facets[$index] ?? []);
		}

		return $facets;
	}//end countsWithoutOwnFilter()

	/**
	 * The facets a filter narrows, with the filter keys of each.
	 *
	 * @param array<string,mixed> $filters The filters.
	 *
	 * @return array<string,list<string>> The narrowed facets.
	 */
	private static function narrowedFacets(array $filters): array {
		$isSet = static fn (array $keys): bool => array_filter(array_intersect_key($filters, array_flip($keys))) !== [];

		return array_filter(self::FACET_FILTERS, $isSet);
	}//end narrowedFacets()

	/**
	 * The `x-term-drilldown` value for some facets.
	 *
	 * @param array<string,int> $limits Facet index to the number of values.
	 *
	 * @return string The value, empty for no facets.
	 */
	private static function drilldown(array $limits): string {
		$terms = array_map(static fn (string $index, int $limit): string => $index . ':' . $limit, array_keys($limits), $limits);

		return implode(',', $terms);
	}//end drilldown()

	/**
	 * The SRU parameters of one request.
	 *
	 * @param string $query The CQL query.
	 * @param array{page: int, pageSize: int} $paging The page and page size.
	 *
	 * @return array<string,string> The parameters.
	 */
	private static function parameters(string $query, array $paging): array {
		return [
			'operation' => 'searchRetrieve',
			'version' => '1.2',
			'recordSchema' => 'lom',
			'query' => $query,
			'startRecord' => (string)((($paging['page'] - 1) * $paging['pageSize']) + 1),
			'maximumRecords' => (string)$paging['pageSize'],
		];
	}//end parameters()

	/**
	 * Send a request and read its answer.
	 *
	 * @param array<string,string> $parameters The SRU parameters.
	 * @param array{page: int, pageSize: int} $paging The page and page size.
	 *
	 * @return SearchResult The result.
	 *
	 * @throws SourceUnavailableException When Edurep does not answer or the answer is no SRU response.
	 */
	private function request(array $parameters, array $paging): SearchResult {
		$result = $this->reader->read(xml: $this->transport->fetch(parameters: $parameters), paging: $paging);
		if ($result === null) {
			throw new SourceUnavailableException(source: LomRecordReader::SOURCE, lastSuccess: $this->transport->lastSuccess());
		}

		return $result;
	}//end request()
}//end class
