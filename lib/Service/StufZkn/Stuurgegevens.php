<?php

/**
 * Integriq StUF stuurgegevens.
 *
 * What a built StUF-ZKN message's `stuurgegevens` block carries: the
 * berichtcode, the referentienummer, zender and ontvanger, and the elements
 * that follow tijdstipBericht (functie or entiteittype).
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
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * The stuurgegevens of one outbound StUF-ZKN message.
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 */
final class Stuurgegevens {

	/**
	 * Constructor.
	 *
	 * @param string               $berichtcode      Di02, Lk01, ...
	 * @param string               $referentienummer The message's referentienummer.
	 * @param array                $zender           `organisatie` and optional `applicatie` of this bridge.
	 * @param array                $ontvanger        `organisatie` and optional `applicatie` of the other side.
	 * @param array<string,string> $tail             Elements after tijdstipBericht (functie or entiteittype).
	 */
	public function __construct(
		public readonly string $berichtcode,
		public readonly string $referentienummer,
		public readonly array $zender,
		public readonly array $ontvanger,
		public readonly array $tail,
	) {

	}//end __construct()
}//end class
