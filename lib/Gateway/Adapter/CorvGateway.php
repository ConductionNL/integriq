<?php

/**
 * The CORV route for the justice domain.
 *
 * @category Adapter
 * @package  OCA\Integriq\Gateway\Adapter
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

namespace OCA\Integriq\Gateway\Adapter;

/**
 * Message schemas and a catalogue entry. The delivery is the shared
 * machinery's, not this adapter's.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */
class CorvGateway extends MessageGateway {
	/**
	 * The gateway id.
	 *
	 * @return string Gateway id.
	 */
	public function id(): string {
		return 'corv';
	}//end id()

	/**
	 * The CORV message types this gateway carries.
	 *
	 * @return array<string,array<int,string>> Message type to required elements.
	 */
	public function schemas(): array {
		return [
			'zorgmelding' => ['bsn', 'melder', 'meldingsdatum', 'aanleiding'],
			'verzoekTotOnderzoek' => ['bsn', 'verzoeker', 'verzoekdatum', 'grond'],
			'terugmelding' => ['zaakIdentificatie', 'resultaat', 'datum'],
		];
	}//end schemas()
}//end class
