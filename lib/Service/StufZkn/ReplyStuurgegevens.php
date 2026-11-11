<?php

/**
 * Integriq StUF reply stuurgegevens.
 *
 * What the `stuurgegevens` block of a Bv03 or Fo03 reply carries: the
 * berichtcode, the referentienummer of the message it answers, and the
 * organisatie codes of zender and ontvanger.
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-successfully-processed-zaklk01-replies-with-a-bv03
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * The stuurgegevens of one Bv03 or Fo03 reply.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-successfully-processed-zaklk01-replies-with-a-bv03
 */
final class ReplyStuurgegevens {

	/**
	 * Constructor.
	 *
	 * @param string $berichtcode           `Bv03` or `Fo03`.
	 * @param string $crossRefnummer        The inbound message's referentienummer (may be empty).
	 * @param string $zenderOrganisation    This bridge's own organisatie code.
	 * @param string $ontvangerOrganisation The original sender's organisatie code (may be empty).
	 */
	public function __construct(
		public readonly string $berichtcode,
		public readonly string $crossRefnummer,
		public readonly string $zenderOrganisation,
		public readonly string $ontvangerOrganisation,
	) {

	}//end __construct()

	/**
	 * The organisatie code per role, in document order.
	 *
	 * @return array{zender:string,ontvanger:string}
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-successfully-processed-zaklk01-replies-with-a-bv03
	 */
	public function organisations(): array {
		return ['zender' => $this->zenderOrganisation, 'ontvanger' => $this->ontvangerOrganisation];
	}//end organisations()
}//end class
