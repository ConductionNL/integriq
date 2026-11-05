<?php

/**
 * Integriq Edurep learning material source.
 *
 * Searches and finds open learning material in Edurep (Kennisnet) over SRU:
 * small pages, facet counts on licence, collection, level and format, and
 * one record by its identifier. The transport decides live or recorded.
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
	public const DRILLDOWN = 'lom.rights.copyrightandotherrestrictions:10,meta.repository.id:12,lom.educational.context:8,lom.technical.format:8';

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
	 * Search Edurep.
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
		$parameters['x-term-drilldown'] = self::DRILLDOWN;

		return $this->request(parameters: $parameters, paging: $paging);
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
