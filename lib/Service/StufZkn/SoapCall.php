<?php

/**
 * Integriq SOAP call.
 *
 * One StUF envelope as StufZknClient posts it: the source configuration that
 * decides the address and the authentication, the SOAPAction, the envelope,
 * an explicit address ('' for `configuration.baseUrl`) and the read timeout
 * (0 for the HTTP client's default).
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-secure-transport
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * Post one StUF envelope.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-secure-transport
 */
final class SoapCall {

	/**
	 * Constructor.
	 *
	 * @param array  $sourceConfiguration The `stuf-zkn` source's `configuration` object.
	 * @param string $soapAction          The SOAPAction header value.
	 * @param string $envelopeXml         The rendered envelope.
	 * @param string $url                 The address; '' for `configuration.baseUrl`.
	 * @param int    $timeoutSeconds      The read timeout; 0 for the client default.
	 */
	public function __construct(
		public readonly array $sourceConfiguration,
		public readonly string $soapAction,
		public readonly string $envelopeXml,
		public readonly string $url='',
		public readonly int $timeoutSeconds=0,
	) {

	}//end __construct()
}//end class
