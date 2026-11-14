<?php

/**
 * Integriq UnsubscribeMaterial.
 *
 * The unsubscribe material a send decision carries (the link, the short SMS
 * text, the List-Unsubscribe headers), read for the channel the message goes
 * out on. Built by MessageComposer for a decided send.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Identity
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
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

/**
 * A decision's unsubscribe material on one channel.
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */
final class UnsubscribeMaterial {

	/**
	 * The unsubscribe url; empty when the material has none.
	 *
	 * @var string
	 */
	public readonly string $url;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $material The decision's `unsubscribe` entry.
	 * @param string $channel The channel the message goes out on.
	 */
	public function __construct(
		private readonly array $material,
		private readonly string $channel,
	) {
		$this->url = (string)($material['url'] ?? '');
	}//end __construct()

	/**
	 * Whether the channel is a phone channel, which gets the short text.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function isPhone(): bool {
		return in_array($this->channel, RecipientKey::PHONE_CHANNELS, true);
	}//end isPhone()

	/**
	 * The short SMS text; empty when the material has none.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function smsText(): string {
		return (string)($this->material['smsText'] ?? '');
	}//end smsText()

	/**
	 * The List-Unsubscribe headers: only for an email, and only when the material has them.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function headers(): array {
		if ($this->channel === RecipientKey::CHANNEL_EMAIL && is_array($this->material['headers'] ?? null) === true) {
			return $this->material['headers'];
		}

		return [];
	}//end headers()
}//end class
