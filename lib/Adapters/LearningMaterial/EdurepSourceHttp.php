<?php

/**
 * Integriq Edurep learning material source (live).
 *
 * The Edurep source on the live SRU endpoint.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use Psr\Log\LoggerInterface;

/**
 * Live Edurep source.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */
final class EdurepSourceHttp extends EdurepSource {
	/**
	 * Constructor.
	 *
	 * @param EdurepHttpTransport $transport The live transport.
	 * @param LoggerInterface $logger Logs skipped records.
	 */
	public function __construct(EdurepHttpTransport $transport, LoggerInterface $logger) {
		parent::__construct(transport: $transport, logger: $logger);
	}//end __construct()
}//end class
