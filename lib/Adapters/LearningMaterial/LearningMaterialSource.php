<?php

/**
 * Integriq learning material source.
 *
 * The contract every open learning material source implements: Edurep now,
 * Edusources once SURF offers a supported API.
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
 * Searches and finds learning material records.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */
interface LearningMaterialSource {
	/**
	 * Search the source.
	 *
	 * @param string $query The user's words.
	 * @param array<string,mixed> $filters `levels`, `licenses`, `formats`, `collection`, `excludeLicenses`,
	 *                                     `excludeFormats`, `excludeCollections` (lists), `language` (string),
	 *                                     `pageSize` (int) and `facets` (false leaves the counts out).
	 * @param int $page The page, from 1.
	 *
	 * @return SearchResult The page with its total and facet counts.
	 *
	 * @throws SourceUnavailableException When the source does not answer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function search(string $query, array $filters, int $page): SearchResult;

	/**
	 * Find one record by its identifier.
	 *
	 * @param string $id The record's identifier.
	 *
	 * @return MaterialRecord|null The record, or null when the source has none.
	 *
	 * @throws SourceUnavailableException When the source does not answer.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-find-returns-one-record-by-its-identifier
	 */
	public function find(string $id): ?MaterialRecord;
}//end interface
