<?php

/**
 * Integriq StUF-ZDS message head.
 *
 * What the stuurgegevens of one StUF-ZDS case message say besides the
 * routing: the berichtcode, the entiteittype, the functie and the
 * referentienummer.
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * The head of one StUF-ZDS case message.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
 */
final class ZdsMessageHead {

	/**
	 * Constructor.
	 *
	 * @param string $messageCode     The berichtcode (Lk01, Lk02, Lv01, Di02, Du01).
	 * @param string $entityType      The entiteittype, '' for none.
	 * @param string $role            The functie.
	 * @param string $referenceNumber The referentienummer.
	 */
	public function __construct(
		public readonly string $messageCode,
		public readonly string $entityType,
		public readonly string $role,
		public readonly string $referenceNumber,
	) {

	}//end __construct()
}//end class
