<?php

/**
 * Integriq OCS request.
 *
 * One call to a sibling app's OCS API through CallService: which API, the
 * Source whose credentials are used, the endpoint, the HTTP method and any
 * extra Guzzle-shaped config.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
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
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Call a sibling app's OCS API once.
 *
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */
final class OcsRequest {

	/**
	 * Constructor.
	 *
	 * @param OcsApi       $api      The API called.
	 * @param ObjectEntity $source   The Source to call.
	 * @param string       $endpoint The endpoint, base path included.
	 * @param string       $method   HTTP method.
	 * @param array        $config   Extra Guzzle-shaped config (headers/query/json).
	 */
	public function __construct(
		public readonly OcsApi $api,
		public readonly ObjectEntity $source,
		public readonly string $endpoint,
		public readonly string $method,
		public readonly array $config = [],
	) {

	}//end __construct()
}//end class
