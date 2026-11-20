<?php

/**
 * Integriq unified search query.
 *
 * The `ISearchQuery` integriq hands to Talk's own unified search provider when
 * it searches Talk as the requesting person
 * (connectors-graph-document-search-chat-threads, design D5).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Chat
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
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Chat;

use ArrayIterator;
use OCP\Search\IFilter;
use OCP\Search\IFilterCollection;
use OCP\Search\ISearchQuery;
use Traversable;

/**
 * A term, a limit and a cursor; no filters and no route, so the provider
 * searches every conversation the person is in. A date range is applied by
 * the caller afterwards.
 *
 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
 */
class IntegriqSearchQuery implements ISearchQuery {

	/**
	 * Constructor.
	 *
	 * @param string     $term   The search term.
	 * @param int        $limit  At most this many entries.
	 * @param int|string $cursor Where the previous page ended; 0 for the first page.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function __construct(
		private readonly string $term,
		private readonly int $limit,
		private readonly int|string $cursor = 0,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The term.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getTerm(): string {
		return $this->term;
	}//end getTerm()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name The filter name.
	 *
	 * @return IFilter|null Always null: the query carries no filters.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) the interface names it; this query has no filters.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getFilter(string $name): ?IFilter {
		return null;
	}//end getFilter()

	/**
	 * {@inheritDoc}
	 *
	 * @return IFilterCollection An empty collection.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getFilters(): IFilterCollection {
		return new class implements IFilterCollection {
			/**
			 * {@inheritDoc}
			 *
			 * @param string $name The filter name.
			 *
			 * @return bool Always false.
			 *
			 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
			 */
			public function has(string $name): bool {
				return false;
			}//end has()

			/**
			 * {@inheritDoc}
			 *
			 * @param string $name The filter name.
			 *
			 * @return IFilter|null Always null.
			 *
			 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
			 */
			public function get(string $name): ?IFilter {
				return null;
			}//end get()

			/**
			 * {@inheritDoc}
			 *
			 * @return Traversable<string, IFilter> Nothing.
			 *
			 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
			 */
			public function getIterator(): Traversable {
				return new ArrayIterator([]);
			}//end getIterator()

			/**
			 * {@inheritDoc}
			 *
			 * @return int Zero.
			 *
			 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
			 */
			public function count(): int {
				return 0;
			}//end count()
		};
	}//end getFilters()

	/**
	 * {@inheritDoc}
	 *
	 * @return int Newest first.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getSortOrder(): int {
		return ISearchQuery::SORT_DATE_DESC;
	}//end getSortOrder()

	/**
	 * {@inheritDoc}
	 *
	 * @return int The limit.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getLimit(): int {
		return $this->limit;
	}//end getLimit()

	/**
	 * {@inheritDoc}
	 *
	 * @return int|string The cursor.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getCursor() {
		return $this->cursor;
	}//end getCursor()

	/**
	 * {@inheritDoc}
	 *
	 * @return string Empty: the search runs outside any page.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getRoute(): string {
		return '';
	}//end getRoute()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string> Empty.
	 *
	 * @spec openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011
	 */
	public function getRouteParameters(): array {
		return [];
	}//end getRouteParameters()
}//end class
