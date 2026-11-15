<?php

/**
 * One fetch of all objects from an API source while SynchronizationService builds and runs it.
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
 * Carries the synchronization, the source and the request being built between
 * the stages of SynchronizationService::getAllObjectsFromApi(), so each stage
 * names the fetch instead of every value it needs.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009
 */
class ApiFetch {

	/**
	 * The source configuration with dotted keys expanded.
	 *
	 * @var array
	 */
	public array $sourceConfig = [];

	/**
	 * The source fetched from.
	 *
	 * @var array
	 */
	public array $source = [];

	/**
	 * Extra Twig context: the `cursor` of an incremental run.
	 *
	 * @var array
	 */
	public array $twigContext = [];

	/**
	 * The endpoint, rendered when it is a template.
	 *
	 * @var mixed
	 */
	public mixed $endpoint = '';

	/**
	 * The query, rendered per value on an incremental run.
	 *
	 * @var mixed
	 */
	public mixed $query = [];

	/**
	 * The call configuration: headers, query and body.
	 *
	 * @var array
	 */
	public array $config = [];

	/**
	 * Which part of the data is sent as the request body ('#' for all of it).
	 *
	 * @var mixed
	 */
	public mixed $useDataAsRequestBody = null;

	/**
	 * Whether the source paginates.
	 *
	 * @var bool|null
	 */
	public ?bool $usesPagination = true;

	/**
	 * The page to start at.
	 *
	 * @var int
	 */
	public int $currentPage = 1;

	/**
	 * Whether this is a test run that returns a single object.
	 *
	 * @var bool|null
	 */
	public ?bool $isTest = false;

	/**
	 * Constructor.
	 *
	 * @param array      $synchronization The synchronization being run.
	 * @param array|null $data            The data to synchronize, or null.
	 */
	public function __construct(
		public readonly array $synchronization,
		public readonly ?array $data,
	) {
	}//end __construct()
}//end class
