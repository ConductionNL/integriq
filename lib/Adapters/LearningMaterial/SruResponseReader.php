<?php

/**
 * Integriq SRU response reader.
 *
 * Reads an SRU 1.2 searchRetrieve response from Edurep: the total, the
 * escaped LOM records in `srw:recordData` and the Meresco drilldown facets.
 * A record whose data is no LOM record is skipped and logged with its
 * position; the others are returned.
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use DOMXPath;
use Psr\Log\LoggerInterface;

/**
 * Reads SRU responses into search results.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */
final class SruResponseReader {
	public const SRW_NAMESPACE = 'http://www.loc.gov/zing/srw/';
	public const DRILLDOWN_NAMESPACE = 'http://meresco.org/namespace/drilldown';

	/**
	 * The records.
	 *
	 * @var LomRecordReader
	 */
	private readonly LomRecordReader $records;

	/**
	 * The XML helper.
	 *
	 * @var SafeXml
	 */
	private readonly SafeXml $xml;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logs skipped records.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
		$this->records = new LomRecordReader();
		$this->xml = new SafeXml();
	}//end __construct()

	/**
	 * Read one response.
	 *
	 * @param string $xml The response body.
	 * @param array{page: int, pageSize: int} $paging The requested page and page size.
	 *
	 * @return SearchResult|null The result, or null when the body is no SRU response or carries a diagnostic.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function read(string $xml, array $paging): ?SearchResult {
		$xpath = $this->parse(xml: $xml);
		if ($xpath === null || $this->xml->nodes(xpath: $xpath, expression: '//srw:diagnostics/*') !== []) {
			return null;
		}

		$summary = [
			'total' => (int)$xpath->evaluate('number(//srw:numberOfRecords)'),
			'page' => $paging['page'],
			'pageSize' => $paging['pageSize'],
			'facets' => $this->facets(xpath: $xpath),
		];

		return new SearchResult(summary: $summary, records: $this->recordsOf(xpath: $xpath));
	}//end read()

	/**
	 * Parse the body with the SRU and drilldown namespaces bound.
	 *
	 * @param string $xml The response body.
	 *
	 * @return DOMXPath|null The XPath, or null when the body is no SRU response.
	 */
	private function parse(string $xml): ?DOMXPath {
		$root = $this->xml->root(xml: $xml, namespace: self::SRW_NAMESPACE);
		if ($root === null || $root->ownerDocument === null) {
			return null;
		}

		$xpath = new DOMXPath($root->ownerDocument);
		$xpath->registerNamespace('srw', self::SRW_NAMESPACE);
		$xpath->registerNamespace('dd', self::DRILLDOWN_NAMESPACE);

		return $xpath;
	}//end parse()

	/**
	 * The records, skipping and logging each one that is no LOM record.
	 *
	 * @param DOMXPath $xpath The response.
	 *
	 * @return list<MaterialRecord> The records in response order.
	 */
	private function recordsOf(DOMXPath $xpath): array {
		$records = [];
		foreach ($this->xml->nodes(xpath: $xpath, expression: '//srw:records/srw:record') as $position => $node) {
			$identifier = trim($xpath->evaluate('string(srw:recordIdentifier)', $node));
			$record = $this->records->read(recordIdentifier: $identifier, xml: trim($xpath->evaluate('string(srw:recordData)', $node)));
			if ($record === null) {
				$context = ['position' => ($position + 1), 'recordIdentifier' => $identifier];
				$this->logger->warning('Integriq: skipped an Edurep record that is no LOM record', $context);
				continue;
			}

			$records[] = $record;
		}

		return $records;
	}//end recordsOf()

	/**
	 * The drilldown facets: navigator name to its values with counts.
	 *
	 * @param DOMXPath $xpath The response.
	 *
	 * @return array<string,list<array{value: string, count: int}>> The facets.
	 */
	private function facets(DOMXPath $xpath): array {
		$facets = [];
		foreach ($this->xml->nodes(xpath: $xpath, expression: '//dd:navigator/dd:item') as $item) {
			$name = (string)$xpath->evaluate('string(../@name)', $item);
			$facets[$name][] = ['value' => trim((string)$item->textContent), 'count' => (int)$xpath->evaluate('number(@count)', $item)];
		}

		return $facets;
	}//end facets()
}//end class
