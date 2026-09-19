<?php

/**
 * The seam a gateway sends through.
 *
 * @category Contract
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Gateway;

/**
 * Every gateway sends through this, so no adapter carries a client of its
 * own and every attempt lands in the same call log with the same transport
 * recorded on it.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */
interface GatewayTransport {
	/**
	 * Send one payload over a gateway.
	 *
	 * @param string $gatewayId The gateway.
	 * @param array<string,mixed> $payload What to send.
	 * @param array<string,mixed> $config The gateway's configuration.
	 *
	 * @return GatewayDelivery What happened.
	 */
	public function send(string $gatewayId, array $payload, array $config = []): GatewayDelivery;
}//end interface
