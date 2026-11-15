<?php

/**
 * Integriq OwnSendRequest.
 *
 * One decision request from one of integriq's own senders: the channel, the
 * category, the recipients, the asking app, and the sender's options
 * (correlationId, baseUrl, inReplyTo, requiresConsent). Built by
 * OptOutRegistry::decideForSend() and never handed out.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Identity;

/**
 * An own sender's decision request.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
 */
final class OwnSendRequest {

	/**
	 * The recipients.
	 *
	 * @var list<array<string,mixed>>
	 */
	public array $recipients = [];

	/**
	 * The asking app; `integriq` unless the options name another.
	 *
	 * @var string
	 */
	public string $sourceApp = 'integriq';

	/**
	 * The sender's options: `sourceApp`, `correlationId`, `baseUrl`, `inReplyTo`, `requiresConsent`.
	 *
	 * @var array<string,mixed>
	 */
	public array $options = [];

	/**
	 * Constructor.
	 *
	 * @param string $channel The channel.
	 * @param string $category The category.
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $category,
	) {
	}//end __construct()

	/**
	 * Take the sender's options, and the asking app they name.
	 *
	 * @param array<string,mixed> $options The sender's options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function withOptions(array $options): void {
		$this->sourceApp = (string)($options['sourceApp'] ?? 'integriq');
		$this->options = $options;
	}//end withOptions()

	/**
	 * The inbound message the send answers, from the sender's options.
	 *
	 * @return string|null The `inReplyTo` value, or null when absent or empty.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function inReplyTo(): ?string {
		if (isset($this->options['inReplyTo']) === true && $this->options['inReplyTo'] !== '') {
			return (string)$this->options['inReplyTo'];
		}

		return null;
	}//end inReplyTo()
}//end class
