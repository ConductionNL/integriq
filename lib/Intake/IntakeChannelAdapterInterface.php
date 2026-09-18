<?php

/**
 * Integriq IntakeChannelAdapterInterface.
 *
 * One channel is one adapter: it receives its own payload shape, says what it
 * can do, and sends a reply back where the message came from. Adding a
 * channel is adding an adapter, not releasing every app that consumes intake.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

/**
 * The contract every intake channel answers to.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001
 */
interface IntakeChannelAdapterInterface {

	/**
	 * The channel id this adapter answers to.
	 *
	 * @return string The channel id.
	 */
	public function getChannelId(): string;

	/**
	 * What this channel can do.
	 *
	 * @return ChannelCapabilities The capabilities.
	 */
	public function describe(): ChannelCapabilities;

	/**
	 * Normalise one payload this channel delivered.
	 *
	 * @param array<string,mixed> $payload The channel's own payload shape.
	 *
	 * @return InboundMessage The normalised message.
	 *
	 * @throws \OCA\Integriq\Exception\IntakeChannelException When the payload cannot be read.
	 */
	public function receive(array $payload): InboundMessage;

	/**
	 * Send a reply back over this channel.
	 *
	 * A channel that cannot reply MUST answer an unsupported result rather
	 * than sending over some other channel: a silent fallback looks like
	 * success to everyone except the person who wrote in.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string $text The reply text.
	 *
	 * @return ReplyResult What happened.
	 */
	public function reply(InboundMessage $message, string $text): ReplyResult;

}//end interface
