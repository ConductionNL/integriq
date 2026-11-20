<?php

/**
 * WebhookChannel
 *
 * @category  Intake
 * @package   OCA\IntegriqExampleChannel\Intake
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\IntegriqExampleChannel\Intake;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\ChannelCapabilities;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\ReplyResult;

/**
 * An intake channel that reads a JSON webhook `{id, from: {name, email}, text}`.
 *
 * It cannot reply: a webhook is one way, so reply() says unsupported rather
 * than sending the answer over some other channel.
 */
class WebhookChannel implements IntakeChannelAdapterInterface {

	public const CHANNEL_ID = 'example-webhook';

	/**
	 * The channel id.
	 *
	 * @return string
	 */
	public function getChannelId(): string {
		return self::CHANNEL_ID;
	}//end getChannelId()

	/**
	 * What this channel can do.
	 *
	 * @return ChannelCapabilities
	 */
	public function describe(): ChannelCapabilities {
		return new ChannelCapabilities(channelId: self::CHANNEL_ID, label: 'Example webhook');
	}//end describe()

	/**
	 * Read one webhook delivery.
	 *
	 * @param array<string,mixed> $payload The delivery.
	 *
	 * @return InboundMessage
	 *
	 * @throws IntakeChannelException When the delivery has no id or no text.
	 */
	public function receive(array $payload): InboundMessage {
		$id = trim((string)($payload['id'] ?? ''));
		$text = trim((string)($payload['text'] ?? ''));
		if ($id === '' || $text === '') {
			throw new IntakeChannelException(message: 'An "' . self::CHANNEL_ID . '" delivery must carry an id and a text.');
		}

		$from = $payload['from'] ?? [];
		if (is_array($from) === false) {
			$from = [];
		}

		return new InboundMessage(
			channelId: self::CHANNEL_ID,
			externalId: $id,
			correspondent: ['name' => (string)($from['name'] ?? ''), 'address' => (string)($from['email'] ?? '')],
			text: $text,
			rawPayload: $payload
		);
	}//end receive()

	/**
	 * A webhook cannot carry a reply.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string         $text    The reply text.
	 *
	 * @return ReplyResult
	 */
	public function reply(InboundMessage $message, string $text): ReplyResult {
		return ReplyResult::unsupported(self::CHANNEL_ID, 'A webhook is one way.');
	}//end reply()
}//end class
