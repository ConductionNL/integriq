<?php

/**
 * A gateway transport that travels over an on-premise bridge.
 *
 * @category Service
 * @package  OCA\Integriq\Bridge
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

namespace OCA\Integriq\Bridge;

use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;
use Psr\Log\LoggerInterface;

/**
 * Selected on a gateway like any other transport, and recorded in the call log
 * as `bridge:<id>` so a call over a bridge is never invisible. A revoked
 * bridge fails the call naming the revocation, and no traffic passes.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-an-on-premise-bridge-reaches-a-system-behind-the-firewall-req-sg-007
 */
class BridgeTransport implements GatewayTransport {
	/**
	 * Constructor.
	 *
	 * @param BridgeRegistry $bridges The registered bridges.
	 * @param GatewayTransport $inner The transport that actually carries the call.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly BridgeRegistry $bridges,
		private readonly GatewayTransport $inner,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one payload over a bridge.
	 *
	 * @param string $gatewayId The gateway.
	 * @param array<string,mixed> $payload What to send.
	 * @param array<string,mixed> $config Carries `bridge`, the bridge id.
	 *
	 * @return GatewayDelivery What happened.
	 */
	public function send(string $gatewayId, array $payload, array $config = []): GatewayDelivery {
		$bridgeId = (string)($config['bridge'] ?? '');
		if ($bridgeId === '') {
			return GatewayDelivery::notSent(
				$gatewayId,
				'This gateway selects the bridge transport but names no bridge. Nothing was sent.'
			);
		}

		if ($this->bridges->isActive($bridgeId) === false) {
			$this->logger->warning('bridge.call.refused', ['gateway' => $gatewayId, 'bridge' => $bridgeId]);

			return GatewayDelivery::notSent(
				$gatewayId,
				sprintf('The bridge "%s" is revoked or unknown. Nothing was sent.', $bridgeId)
			);
		}

		$outcome = $this->inner->send($gatewayId, $payload, ($config + ['transport' => $this->transportName($bridgeId)]));
		$recorded = $outcome->toArray();

		// The call log names the bridge, so a call that travelled over the
		// customer's network is not indistinguishable from a direct one.
		return new GatewayDelivery(
			$gatewayId,
			(bool)$recorded['delivered'],
			($recorded['identifier'] ?? null),
			(string)$recorded['reason'],
			(bool)$recorded['transmitted'],
			(bool)$recorded['replayable'],
			$this->transportName($bridgeId)
		);
	}//end send()

	/**
	 * How this transport names itself in the call log.
	 *
	 * @param string $bridgeId The bridge id.
	 *
	 * @return string Transport name.
	 */
	public function transportName(string $bridgeId): string {
		return 'bridge:' . $bridgeId;
	}//end transportName()
}//end class
