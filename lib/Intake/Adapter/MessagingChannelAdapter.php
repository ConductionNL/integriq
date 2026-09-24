<?php

/**
 * Integriq MessagingChannelAdapter.
 *
 * The messaging channel: what a resident actually writes on. One adapter
 * rather than one integration per service, because a messaging service is a
 * receiver and a sender, and none of them is a new idea about what a case is.
 * The outbound leg posts to the configured gateway; in mock mode it records
 * the reply and sends nothing.
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake\Adapter;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\ChannelCapabilities;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\ReplyResult;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Receives messaging traffic and answers on the same channel.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-reply-goes-back-over-the-channel-it-arrived-on-req-ic-005
 */
class MessagingChannelAdapter implements IntakeChannelAdapterInterface {

	/**
	 * The channel id.
	 *
	 * @var string
	 */
	public const CHANNEL_ID = 'messaging';

	/**
	 * Constructor.
	 *
	 * @param IntakeChannelSourceResolver $sourceResolver Finds this channel's source configuration.
	 * @param IClientService $clientService The Nextcloud HTTP client factory.
	 */
	public function __construct(
		private readonly IntakeChannelSourceResolver $sourceResolver,
		private readonly IClientService $clientService,
	) {

	}//end __construct()

	/**
	 * The channel id this adapter answers to.
	 *
	 * @return string The channel id.
	 */
	public function getChannelId(): string {
		return self::CHANNEL_ID;

	}//end getChannelId()

	/**
	 * What this channel can do.
	 *
	 * @return ChannelCapabilities The capabilities.
	 */
	public function describe(): ChannelCapabilities {
		return new ChannelCapabilities(
			channelId: self::CHANNEL_ID,
			label: 'Messaging',
			canReply: true,
			supportsLocation: false,
			supportsMedia: true,
			fields: ['text', 'service']);

	}//end describe()

	/**
	 * Normalise one inbound message.
	 *
	 * @param array<string,mixed> $payload The gateway's payload.
	 *
	 * @return InboundMessage The normalised message.
	 *
	 * @throws IntakeChannelException When the payload names no message id or no sender.
	 */
	public function receive(array $payload): InboundMessage {
		$externalId = trim((string)($payload['messageId'] ?? ''));
		$from = ($payload['from'] ?? []);
		if (is_array($from) === false) {
			$from = ['phone' => (string)$from];
		}

		$handle = trim((string)($from['phone'] ?? $from['handle'] ?? ''));
		if ($externalId === '' || $handle === '') {
			throw new IntakeChannelException(
				message: 'A "' . self::CHANNEL_ID . '" message must carry a messageId and a sender handle, '
				. 'because a reply has nowhere to go without one.'
			);
		}

		$timestamp = ($payload['timestamp'] ?? null);
		if ($timestamp !== null) {
			$timestamp = (string)$timestamp;
		}

		return new InboundMessage(
			channelId: self::CHANNEL_ID,
			externalId: $externalId,
			correspondent: [
				'id' => $handle,
				'phone' => $handle,
				'name' => (string)($from['name'] ?? ''),
			],
			text: (string)($payload['text'] ?? ''),
			attachments: $this->attachments(payload: $payload),
			location: null,
			media: [],
			rawPayload: $payload,
			receivedAt: $timestamp,
			fields: [
				'text' => (string)($payload['text'] ?? ''),
				'service' => (string)($payload['service'] ?? ''),
			]);

	}//end receive()

	/**
	 * Answer on the channel the message arrived on.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string $text The reply text.
	 *
	 * @return ReplyResult What happened.
	 */
	public function reply(InboundMessage $message, string $text): ReplyResult {
		$handle = trim((string)($message->getCorrespondent()['phone'] ?? ''));
		if ($handle === '') {
			return ReplyResult::failed(self::CHANNEL_ID, 'The message records no correspondent handle.');
		}

		$configuration = $this->sourceResolver->configurationFor(self::CHANNEL_ID);
		if ($configuration === null) {
			return ReplyResult::failed(
				self::CHANNEL_ID,
				'No messaging source is configured, so there is no gateway to answer through.'
			);
		}

		if (($configuration['mock'] ?? false) === true) {
			return ReplyResult::sent(self::CHANNEL_ID, 'MOCK-REPLY-' . substr(sha1($handle . $text), 0, 12));
		}

		$endpoint = trim((string)($configuration['replyEndpoint'] ?? ''));
		if ($endpoint === '') {
			return ReplyResult::failed(self::CHANNEL_ID, 'The messaging source names no reply endpoint.');
		}

		try {
			$response = $this->clientService->newClient()->post(
				$endpoint,
				[
					'headers' => $this->replyHeaders(configuration: $configuration),
					'json' => ['to' => $handle, 'text' => $text, 'inReplyTo' => $message->getExternalId()],
					'timeout' => 30,
				]
			);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $exception) {
			return ReplyResult::failed(self::CHANNEL_ID, 'The gateway refused the reply: ' . $exception->getMessage());
		}

		$reference = null;
		if (is_array($decoded) === true) {
			$reference = (string)($decoded['messageId'] ?? $decoded['id'] ?? '');
		}

		$sentReference = $reference;
		if ($sentReference === '') {
			$sentReference = null;
		}

		return ReplyResult::sent(self::CHANNEL_ID, $sentReference);

	}//end reply()

	/**
	 * The headers the reply is sent with.
	 *
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return array<string,string> The headers.
	 */
	private function replyHeaders(array $configuration): array {
		$headers = ['Accept' => 'application/json'];
		$token = trim((string)($configuration['accessToken'] ?? ''));
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;

	}//end replyHeaders()

	/**
	 * The message's attachments.
	 *
	 * @param array<string,mixed> $payload The gateway's payload.
	 *
	 * @return array<int,array<string,mixed>> The attachments.
	 */
	private function attachments(array $payload): array {
		$attachments = [];
		foreach (($payload['attachments'] ?? []) as $index => $attachment) {
			if (is_array($attachment) === false) {
				continue;
			}

			$content = (string)base64_decode((string)($attachment['contentBase64'] ?? ''), false);
			$attachments[] = [
				'name' => (string)($attachment['name'] ?? ('attachment-' . ((int)$index + 1))),
				'mime' => (string)($attachment['mime'] ?? 'application/octet-stream'),
				'size' => strlen($content),
				'content' => $content,
			];
		}

		return $attachments;

	}//end attachments()

}//end class
