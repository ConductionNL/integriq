<?php

/**
 * Integriq GatewaySend: which gateway a payload went to and over which transport,
 * for reading what the gateway answered.
 *
 * @category Service
 * @package  OCA\Integriq\Gateway
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
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Gateway;

/**
 * One send over a gateway's configured source.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */
final class GatewaySend {
	/**
	 * Constructor.
	 *
	 * @param string $gatewayId The gateway.
	 * @param string $transport The transport the delivery reports.
	 */
	public function __construct(
		public readonly string $gatewayId,
		public readonly string $transport,
	) {
	}//end __construct()
}//end class
