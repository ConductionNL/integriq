<?php

/**
 * Document search source contract.
 *
 * The typed commands DocumentSearchRequested and DocumentFetchRequested
 * (connectors-graph-document-search) are answered by any source that
 * implements this contract. Microsoft 365 is the first one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\Saas;

/**
 * A source a sibling app can search and fetch from, as one person.
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
 */
interface DocumentSearchSource {

	/**
	 * Search the source for terms in a period, as one person.
	 *
	 * @param string            $terms       The search terms.
	 * @param string|null       $from        Start of the period, Y-m-d.
	 * @param string|null       $to          End of the period, Y-m-d.
	 * @param array<int,string> $entityTypes The types to search; empty for all.
	 * @param int               $limit       At most this many hits.
	 * @param string            $userId      The person searching.
	 *
	 * @return array{hits: array<int, array<string, mixed>>, moreCount: int, notices: array<int, string>} The hits.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function search(string $terms, ?string $from, ?string $to, array $entityTypes, int $limit, string $userId): array;

	/**
	 * Fetch the content behind a search hit's handle.
	 *
	 * @param string $handle The hit's `remoteId`.
	 * @param string $userId The person fetching.
	 *
	 * @return array{fileName: string, mimeType: string, content: string}|null Null for an unknown handle or a refusal.
	 *
	 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009
	 */
	public function fetch(string $handle, string $userId): ?array;
}//end interface
