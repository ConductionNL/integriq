<?php

/**
 * Integriq KISS request.
 *
 * One call to the Klantinteracties API: the method, the path below
 * `configuration.baseUrl`, the query and the JSON body.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

/**
 * Call the Klantinteracties API once.
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
final class KissRequest {

	/**
	 * Constructor.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $path     Path below `configuration.baseUrl`, leading slash included.
	 * @param array      $query    Query parameters; none when empty.
	 * @param array|null $jsonBody JSON body; none when null.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		public readonly array $query=[],
		public readonly ?array $jsonBody=null,
	) {

	}//end __construct()
}//end class
