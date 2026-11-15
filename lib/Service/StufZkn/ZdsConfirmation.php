<?php

/**
 * Integriq StUF-ZDS confirmation.
 *
 * One asynchronous confirmation (Bv03/Fo03 family) as the receiver read it:
 * the parsed envelope, its berichtcode and crossRefnummer, and the raw body.
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use SimpleXMLElement;

/**
 * The parts of one received confirmation.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */
final class ZdsConfirmation {

	/**
	 * Constructor.
	 *
	 * @param SimpleXMLElement $root     The parsed envelope.
	 * @param string           $code     The berichtcode.
	 * @param string           $crossRef The crossRefnummer: the outbound message it concludes.
	 * @param string           $rawXml   The raw body.
	 */
	public function __construct(
		public readonly SimpleXMLElement $root,
		public readonly string $code,
		public readonly string $crossRef,
		public readonly string $rawXml,
	) {

	}//end __construct()
}//end class
