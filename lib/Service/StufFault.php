<?php

/**
 * StufFault — one StUF foutbericht (Fo01, Fo02 or Fo03): its berichtcode, the
 * StUF error code, the description and, for Fo01/Fo02, where the fault lies.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/stuf-adapter/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * One foutbericht StUFXMLBuilder writes inside a SOAP fault.
 *
 * @spec openspec/specs/stuf-adapter/spec.md
 */
final class StufFault {
	/**
	 * Human-readable error description.
	 *
	 * @var string
	 */
	public string $omschrijving = '';

	/**
	 * Where the fault lies, `client` or `server`; null for a Fo03, which names none.
	 *
	 * @var string|null
	 */
	public ?string $plek = null;

	/**
	 * Constructor.
	 *
	 * @param string $berichtcode Fo01, Fo02 or Fo03.
	 * @param string $code        The StUF error code.
	 */
	public function __construct(
		public readonly string $berichtcode,
		public readonly string $code,
	) {
	}//end __construct()
}//end class
