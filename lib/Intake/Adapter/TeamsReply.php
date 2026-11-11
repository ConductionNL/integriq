<?php

/**
 * Integriq TeamsReply: one reply being posted to the Bot Framework connector —
 * the message answered and the text, the conversation, the source
 * configuration and, once checked, the connector address and its token.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake\Adapter
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
 * @spec openspec/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake\Adapter;

use OCA\Integriq\Intake\InboundMessage;

/**
 * One Teams reply in progress. Built and used by TeamsChannelAdapter only.
 *
 * @spec openspec/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
 */
final class TeamsReply {
	/**
	 * The conversation to answer in.
	 *
	 * @var string
	 */
	public string $conversationId = '';

	/**
	 * The source configuration.
	 *
	 * @var array<string,mixed>
	 */
	public array $configuration = [];

	/**
	 * The connector address, once trusted.
	 *
	 * @var string
	 */
	public string $serviceUrl = '';

	/**
	 * The connector access token.
	 *
	 * @var string
	 */
	public string $token = '';

	/**
	 * Constructor.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string         $text    The reply text.
	 */
	public function __construct(
		public readonly InboundMessage $message,
		public readonly string $text,
	) {
	}//end __construct()
}//end class
