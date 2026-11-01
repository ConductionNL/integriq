<?php

/**
 * Integriq PdokQuery.
 *
 * One call the PDOK connector makes: the Locatieserver endpoint, its query
 * parameters, how long the answer is cached and whether a resolved answer is
 * written through to the addresses register.
 *
 * @category Connector
 * @package  OCA\Integriq\Connectors
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
 * @spec openspec/specs/pdok-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Connectors;

/**
 * Value object for one PDOK Locatieserver call.
 *
 * @spec openspec/specs/pdok-adapter/spec.md
 */
final class PdokQuery {

	/**
	 * Constructor.
	 *
	 * @param string $endpoint     One of suggest|lookup|free|reverse.
	 * @param array  $params       The query parameters.
	 * @param int    $ttl          How long the answer is cached, in seconds.
	 * @param bool   $writeThrough Whether a resolved answer is looked up in and written to the register.
	 */
	public function __construct(
		public readonly string $endpoint,
		public readonly array $params,
		public readonly int $ttl,
		public readonly bool $writeThrough,
	) {

	}//end __construct()
}//end class
