<?php

/**
 * What a received message records about the route it arrived on.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Gateway;

/**
 * An obligation is either met by the route or handed to the consumer, and the
 * handed one names the duty, so a consumer can be asked for it rather than
 * assumed to have done it.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-an-electronic-route-declares-the-wmebv-obligations-it-meets-req-sg-004
 */
class InboundRouteReceipt {
	/**
	 * Constructor.
	 *
	 * @param GatewayRegistry $registry The gateway entries.
	 */
	public function __construct(private readonly GatewayRegistry $registry) {
	}//end __construct()

	/**
	 * Record the receipt of one message.
	 *
	 * @param string $routeId The gateway the message arrived on.
	 * @param string $messageId The message's own id.
	 * @param int|null $receivedAt Unix timestamp of receipt.
	 *
	 * @return array<string,mixed> The receipt record.
	 */
	public function record(string $routeId, string $messageId, ?int $receivedAt = null): array {
		$gateway = $this->registry->get($routeId);
		$receivedAt = ($receivedAt ?? time());

		if ($gateway === null) {
			// An unknown route is recorded as unknown. It is never recorded as
			// a route that met nothing, which would read like a checked answer.
			return [
				'route' => $routeId,
				'routeKnown' => false,
				'messageId' => $messageId,
				'receivedAt' => gmdate('c', $receivedAt),
				'wmebvMet' => [],
				'wmebvHandedToConsumer' => [],
			];
		}

		return [
			'route' => $routeId,
			'routeKnown' => true,
			'messageId' => $messageId,
			'receivedAt' => gmdate('c', $receivedAt),
			// Recorded at the moment of receipt, not read back from the entry
			// later: the entry can change, and this record says what was true
			// when the message arrived.
			'wmebvMet' => $gateway->getWmebvMet(),
			'wmebvHandedToConsumer' => $gateway->getWmebvHandedToConsumer(),
		];
	}//end record()
}//end class
