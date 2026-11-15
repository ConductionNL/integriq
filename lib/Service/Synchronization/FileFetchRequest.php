<?php

/**
 * One fetch_file request: where to fetch from, what to fetch and for which object.
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
 * The source, the fetch_file rule configuration, the endpoint value (one
 * endpoint, a file object or a list of either), the object the files belong
 * to and the rule id, as SynchronizationService hands them from one file
 * fetching stage to the next.
 *
 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-objects-multiple-files-shall-be-fetched-concurrently
 */
final class FileFetchRequest {

	/**
	 * Holds the values of one fetch_file request.
	 *
	 * @param array       $source   The source to fetch from.
	 * @param array       $config   The fetch_file rule configuration.
	 * @param mixed       $endpoint The endpoint value read from the item data.
	 * @param string|null $objectId The object the files belong to, when known.
	 * @param int         $ruleId   The rule that asked for the fetch, for error attribution.
	 */
	public function __construct(
		public readonly array $source,
		public readonly array $config,
		public readonly mixed $endpoint,
		public readonly ?string $objectId,
		public readonly int $ruleId,
	) {
	}//end __construct()
}//end class
