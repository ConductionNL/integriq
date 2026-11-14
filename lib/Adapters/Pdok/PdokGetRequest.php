<?php

/**
 * Integriq PDOK GET request.
 *
 * One GET a PDOK WFS or WMS client sends: the dataset slug, the query string
 * and the response type it expects.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Pdok
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

namespace OCA\Integriq\Adapters\Pdok;

/**
 * The dataset, query and expected response type of one PDOK GET.
 *
 * @spec openspec/specs/pdok-adapter/spec.md#requirement-pdok-connector-class
 */
final class PdokGetRequest {

	/**
	 * Constructor.
	 *
	 * @param string              $dataset        Dataset slug to interpolate.
	 * @param array<string,mixed> $query          Query-string parameters.
	 * @param string              $expectedAccept The expected response MIME type.
	 */
	public function __construct(
		public readonly string $dataset,
		public readonly array $query,
		public readonly string $expectedAccept,
	) {
	}//end __construct()
}//end class
