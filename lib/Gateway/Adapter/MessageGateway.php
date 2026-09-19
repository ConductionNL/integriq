<?php

/**
 * Shared shape for a sector gateway that validates before it sends.
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

use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;

/**
 * A message that does not validate never leaves, and the refusal names the
 * element that was missing. Delivery itself goes through the shared transport,
 * so a sector gateway is message schemas and nothing more.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-corv-and-ggk-ship-as-sector-gateways-req-sg-003
 */
abstract class MessageGateway {
	/**
	 * Constructor.
	 *
	 * @param GatewayTransport $transport The shared delivery machinery.
	 */
	public function __construct(protected readonly GatewayTransport $transport) {
	}//end __construct()

	/**
	 * The gateway id this adapter answers to.
	 *
	 * @return string Gateway id.
	 */
	abstract public function id(): string;

	/**
	 * Which elements each message type requires.
	 *
	 * @return array<string,array<int,string>> Message type to required elements.
	 */
	abstract public function schemas(): array;

	/**
	 * Check one message against its schema.
	 *
	 * @param string $messageType The message type.
	 * @param array<string,mixed> $message The message.
	 *
	 * @return array<int,string> The refusals, empty when the message validates.
	 */
	public function validate(string $messageType, array $message): array {
		$schemas = $this->schemas();
		if (isset($schemas[$messageType]) === false) {
			return [
				sprintf(
					'"%s" is not a message type the %s gateway carries. It carries: %s.',
					$messageType,
					$this->id(),
					implode(', ', array_keys($schemas))
				),
			];
		}

		$refusals = [];
		foreach ($schemas[$messageType] as $element) {
			if (($message[$element] ?? null) === null || $message[$element] === '') {
				$refusals[] = sprintf('The required element "%s" is missing from this %s message.', $element, $messageType);
			}
		}

		return $refusals;
	}//end validate()

	/**
	 * Send one message, refusing it here when it does not validate.
	 *
	 * @param string $messageType The message type.
	 * @param array<string,mixed> $message The message.
	 * @param array<string,mixed> $config The gateway's configuration.
	 *
	 * @return GatewayDelivery What happened.
	 */
	public function send(string $messageType, array $message, array $config = []): GatewayDelivery {
		$refusals = $this->validate(messageType: $messageType, message: $message);
		if ($refusals !== []) {
			return GatewayDelivery::notSent($this->id(), implode(' ', $refusals));
		}

		return $this->transport->send(
			$this->id(),
			['messageType' => $messageType, 'message' => $message],
			$config
		);
	}//end send()
}//end class
