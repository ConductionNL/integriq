<?php

/**
 * One page fetched from a source while SynchronizationService reads it.
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
 * Carries the source, the synchronization and the page's answer between the
 * stages of SynchronizationService::fetchSinglePageData(), so each stage names
 * the page instead of every value it needs.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class SourcePage {

	/**
	 * The synchronization being run.
	 *
	 * @var array
	 */
	public array $synchronization = [];

	/**
	 * The call log's response, or null when the call had none.
	 *
	 * @var array|null
	 */
	public ?array $response = null;

	/**
	 * The call log's status code, or null when it has none.
	 *
	 * @var int|null
	 */
	public ?int $statusCode = null;

	/**
	 * The response body, decoded and decompressed where the response asks for it.
	 *
	 * @var mixed
	 */
	public mixed $body = null;

	/**
	 * Constructor.
	 *
	 * @param array  $source   The source called.
	 * @param string $endpoint The endpoint called.
	 */
	public function __construct(
		public readonly array $source,
		public readonly string $endpoint,
	) {
	}//end __construct()
}//end class
