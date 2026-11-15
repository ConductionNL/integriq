<?php

/**
 * Integriq StUF provider request.
 *
 * One StUF provider request as it reaches StufProviderReceiver: the raw
 * body, the integriq StUF-ZKN source it is for, the route's service
 * (cases or persons), and whether it came on integriq's own route or was
 * forwarded by a sibling app.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * Answer one provider request.
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */
final class ProviderRequest {

	/**
	 * Constructor.
	 *
	 * @param string $rawXml   The raw request body.
	 * @param string $sourceId The integriq StUF-ZKN source the request is for.
	 * @param string $service  SERVICE_CASES or SERVICE_PERSONS.
	 * @param string $origin   The route it arrived on: integriq's own, or forwarded.
	 */
	public function __construct(
		public readonly string $rawXml,
		public readonly string $sourceId,
		public readonly string $service,
		public readonly string $origin,
	) {

	}//end __construct()
}//end class
