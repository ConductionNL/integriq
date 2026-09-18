<?php

/**
 * What happened when a gateway tried to deliver something.
 *
 * @category ValueObject
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
 * A refusal is a failed delivery, with the other side's reason, and it is
 * replayable. A message that never validated is a refusal too, and nothing
 * was transmitted.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-official-publication-is-a-gateway-and-the-document-is-published-by-reference-req-sg-005
 */
final class GatewayDelivery {
	/**
	 * Constructor.
	 *
	 * @param string $gatewayId The gateway that tried.
	 * @param bool $delivered Whether the other side took it.
	 * @param string|null $identifier The identifier the other side returned.
	 * @param string $reason The other side's reason, or the refusal's.
	 * @param bool $transmitted Whether anything left this instance at all.
	 * @param bool $replayable Whether the attempt can be made again as it stands.
	 * @param string $transport The transport the attempt used.
	 */
	public function __construct(
		private readonly string $gatewayId,
		private readonly bool $delivered,
		private readonly ?string $identifier = null,
		private readonly string $reason = '',
		private readonly bool $transmitted = true,
		private readonly bool $replayable = true,
		private readonly string $transport = 'https',
	) {
	}//end __construct()

	/**
	 * The other side took it, and gave this identifier back.
	 *
	 * @param string $gatewayId The gateway.
	 * @param string|null $identifier The returned identifier.
	 * @param string $transport The transport used.
	 *
	 * @return self A delivered outcome.
	 */
	public static function delivered(string $gatewayId, ?string $identifier = null, string $transport = 'https'): self {
		return new self($gatewayId, true, $identifier, '', true, false, $transport);
	}//end delivered()

	/**
	 * The other side refused, in its own words. Replayable as it stands.
	 *
	 * @param string $gatewayId The gateway.
	 * @param string $reason The other side's reason.
	 * @param string $transport The transport used.
	 *
	 * @return self A failed delivery.
	 */
	public static function refused(string $gatewayId, string $reason, string $transport = 'https'): self {
		return new self($gatewayId, false, null, $reason, true, true, $transport);
	}//end refused()

	/**
	 * This instance refused it, and nothing left.
	 *
	 * @param string $gatewayId The gateway.
	 * @param string $reason Why it was refused.
	 *
	 * @return self A failed delivery that never went anywhere.
	 */
	public static function notSent(string $gatewayId, string $reason): self {
		return new self($gatewayId, false, null, $reason, false, false);
	}//end notSent()

	/**
	 * Whether the other side took it.
	 *
	 * @return bool True when delivered.
	 */
	public function isDelivered(): bool {
		return $this->delivered;
	}//end isDelivered()

	/**
	 * Whether anything left this instance at all.
	 *
	 * @return bool True when something was transmitted.
	 */
	public function wasTransmitted(): bool {
		return $this->transmitted;
	}//end wasTransmitted()

	/**
	 * Whether this attempt can be replayed as it stands.
	 *
	 * @return bool True when replayable.
	 */
	public function isReplayable(): bool {
		return $this->replayable;
	}//end isReplayable()

	/**
	 * The identifier the other side returned.
	 *
	 * @return string|null The identifier.
	 */
	public function getIdentifier(): ?string {
		return $this->identifier;
	}//end getIdentifier()

	/**
	 * The reason behind a failure.
	 *
	 * @return string The reason, empty on success.
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * The attempt as the delivery log records it.
	 *
	 * @return array<string,mixed> Serialisable outcome.
	 */
	public function toArray(): array {
		return [
			'gateway' => $this->gatewayId,
			'delivered' => $this->delivered,
			'identifier' => $this->identifier,
			'reason' => $this->reason,
			'transmitted' => $this->transmitted,
			'replayable' => $this->replayable,
			'transport' => $this->transport,
		];
	}//end toArray()
}//end class
