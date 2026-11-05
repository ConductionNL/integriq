<?php

/**
 * Integriq learning material search result.
 *
 * One page of normalised records with the total and the facet counts.
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

/**
 * A page of learning material records.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */
final class SearchResult {
	/**
	 * The total.
	 *
	 * @var int
	 */
	public readonly int $total;

	/**
	 * The page.
	 *
	 * @var int
	 */
	public readonly int $page;

	/**
	 * The page size.
	 *
	 * @var int
	 */
	public readonly int $pageSize;

	/**
	 * Facet name to its values with counts.
	 *
	 * @var array<string,list<array{value: string, count: int}>>
	 */
	public readonly array $facets;

	/**
	 * Constructor.
	 *
	 * @param array{total?: int, page?: int, pageSize?: int, facets?: array<string,list<array{value: string, count: int}>>} $summary The counts.
	 * @param list<MaterialRecord> $records The page's records.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function __construct(
		array $summary,
		public readonly array $records,
	) {
		$this->total = (int)($summary['total'] ?? count($records));
		$this->page = (int)($summary['page'] ?? 1);
		$this->pageSize = (int)($summary['pageSize'] ?? count($records));
		$this->facets = ($summary['facets'] ?? []);
	}//end __construct()

	/**
	 * The result as a plain array.
	 *
	 * @return array<string,mixed> `total`, `page`, `pageSize`, `records` and `facets`.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function toArray(): array {
		return [
			'total' => $this->total,
			'page' => $this->page,
			'pageSize' => $this->pageSize,
			'records' => array_map(static fn (MaterialRecord $record): array => $record->toArray(), $this->records),
			'facets' => $this->facets,
		];
	}//end toArray()
}//end class
