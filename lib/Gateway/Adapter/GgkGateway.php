<?php

/**
 * The GGK route for the social domain.
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
 * The Gemeentelijk Gegevensknooppunt route, beside CORV and behind the same
 * contract.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */
class GgkGateway extends MessageGateway {
	/**
	 * The gateway id.
	 *
	 * @return string Gateway id.
	 */
	public function id(): string {
		return 'ggk';
	}//end id()

	/**
	 * The GGK message types this gateway carries.
	 *
	 * @return array<string,array<int,string>> Message type to required elements.
	 */
	public function schemas(): array {
		return [
			'toewijzing' => ['bsn', 'aanbieder', 'productCode', 'ingangsdatum'],
			'declaratie' => ['bsn', 'aanbieder', 'periode', 'bedrag'],
			'retourbericht' => ['referentie', 'status'],
		];
	}//end schemas()
}//end class
