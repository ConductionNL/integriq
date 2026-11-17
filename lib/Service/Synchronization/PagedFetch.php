<?php

/**
 * One paginated fetch of a source while SynchronizationService reads it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

/**
 * Carries the source, the synchronization, the request for the next page and
 * what has been read so far between the stages of
 * SynchronizationService::fetchAllPagesOptimized(), so each stage names the
 * fetch instead of every value it needs.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
 */
class PagedFetch {

	/**
	 * The endpoint of the next page.
	 *
	 * @var string
	 */
	public string $endpoint = '';

	/**
	 * The request configuration of the next page.
	 *
	 * @var array
	 */
	public array $config = [];

	/**
	 * The page number of the current page.
	 *
	 * @var int
	 */
	public int $currentPage = 1;

	/**
	 * Whether the source paginates by `next` endpoint URLs; null until known.
	 *
	 * @var bool|null
	 */
	public ?bool $usesNextEndpoint = null;

	/**
	 * The synchronization's source config, as stored.
	 *
	 * @var array
	 */
	public array $sourceConfig = [];

	/**
	 * The most pages this fetch reads (`maxPages`, as configured).
	 *
	 * @var mixed
	 */
	public mixed $maxPages = null;

	/**
	 * The objects read so far.
	 *
	 * @var array
	 */
	public array $objects = [];

	/**
	 * The number of pages fetched so far.
	 *
	 * @var int
	 */
	public int $pageCount = 0;

	/**
	 * Whether every page of the source was read (REQ-009).
	 *
	 * @var bool
	 */
	public bool $complete = true;

	/**
	 * Why the fetch is incomplete, or null.
	 *
	 * @var string|null
	 */
	public ?string $failureReason = null;

	/**
	 * Constructor.
	 *
	 * @param array $source          The data source configuration.
	 * @param array $synchronization The synchronization; its `currentPage` follows the fetch.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	public function __construct(
		public readonly array $source,
		public array $synchronization,
	) {
	}//end __construct()

	/**
	 * Stop the fetch before the source was fully read.
	 *
	 * @param string $reason Why: `page_fetch_failed`, `max_pages_reached` or `memory_ceiling_reached`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	public function markIncomplete(string $reason): void {
		$this->complete = false;
		$this->failureReason = $reason;
	}//end markIncomplete()

	/**
	 * The fetch's answer: the objects and the fetch-completeness metadata.
	 *
	 * @return array{objects: array, complete: bool, failureReason: ?string, pagesFetched: int}
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
	 */
	public function result(): array {
		return ['objects' => $this->objects, 'complete' => $this->complete, 'failureReason' => $this->failureReason, 'pagesFetched' => $this->pageCount];
	}//end result()
}//end class
