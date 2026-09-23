<?php

/**
 * Integriq TeamsChannelAdapter.
 *
 * The Microsoft Teams channel: a message in a team or a chat opens or joins a
 * case. One adapter behind the shipped channel contract, because a Teams
 * message is a message, not a new idea about what a case is. The outbound leg
 * answers in the conversation the message came from, through the Bot
 * Framework connector; in mock mode it records the reply and sends nothing.
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
 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake\Adapter;

use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Intake\ChannelCapabilities;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Service\Mail\CaseReferenceDetector;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Receives Microsoft Teams activities and answers in the same conversation.
 *
 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
 */
class TeamsChannelAdapter implements IntakeChannelAdapterInterface {

	/**
	 * The channel id.
	 *
	 * @var string
	 */
	public const CHANNEL_ID = 'teams';

	/**
	 * Host suffixes a connector reply may be sent to.
	 *
	 * The reply carries `Authorization: Bearer <connector access token>`, so the
	 * destination is a credential-disclosure decision, not a routing detail.
	 * `serviceUrl` arrives on the activity, and an activity is attacker-
	 * influenceable — Microsoft's own Bot Framework guidance is that it must be
	 * validated before use. Without a list this method is an SSRF with a bearer
	 * token attached (integriq#1983 security re-review of the delta since
	 * f370f878).
	 *
	 * Suffix match on the host, never a substring match on the URL:
	 * `https://evil.example/smba.trafficmanager.net` must not pass.
	 *
	 * An operator extends this per source with `trustedServiceHosts`, for a
	 * sovereign or test connector.
	 *
	 * @var string[]
	 */
	public const DEFAULT_TRUSTED_SERVICE_HOSTS = [
		'botframework.com',
		'smba.trafficmanager.net',
		'skype.com',
	];

	/**
	 * The attachment content type a Teams file upload carries.
	 *
	 * @var string
	 */
	private const FILE_DOWNLOAD_INFO = 'application/vnd.microsoft.teams.file.download.info';

	/**
	 * Constructor.
	 *
	 * @param IntakeChannelSourceResolver $sourceResolver Finds this channel's source configuration.
	 * @param IClientService $clientService The Nextcloud HTTP client factory.
	 * @param CaseReferenceDetector $referenceDetector Reads the case reference out of the message text.
	 */
	public function __construct(
		private readonly IntakeChannelSourceResolver $sourceResolver,
		private readonly IClientService $clientService,
		private readonly CaseReferenceDetector $referenceDetector,
	) {

	}//end __construct()

	/**
	 * The channel id this adapter answers to.
	 *
	 * @return string The channel id.
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function getChannelId(): string {
		return self::CHANNEL_ID;

	}//end getChannelId()

	/**
	 * What this channel can do.
	 *
	 * @return ChannelCapabilities The capabilities.
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function describe(): ChannelCapabilities {
		return new ChannelCapabilities(
			channelId: self::CHANNEL_ID,
			label: 'Microsoft Teams',
			canReply: true,
			supportsLocation: false,
			supportsMedia: false,
			fields: ['text', 'caseReference', 'conversationId', 'tenantId', 'teamId', 'conversationType']);

	}//end describe()

	/**
	 * Normalise one Teams activity.
	 *
	 * @param array<string,mixed> $payload The Teams activity.
	 *
	 * @return InboundMessage The normalised message.
	 *
	 * @throws IntakeChannelException When the activity names no message id, no author or no conversation.
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function receive(array $payload): InboundMessage {
		$externalId = trim((string)($payload['id'] ?? ''));
		$from = $this->mapAt(value: ($payload['from'] ?? null));
		$conversation = $this->mapAt(value: ($payload['conversation'] ?? null));

		$authorId = trim((string)($from['id'] ?? ''));
		$conversationId = trim((string)($conversation['id'] ?? ''));

		if ($externalId === '' || $authorId === '' || $conversationId === '') {
			throw new IntakeChannelException(
				message: 'A "' . self::CHANNEL_ID . '" activity must carry an id, an author and a conversation id, '
				. 'because a reply has nowhere to go without one.'
			);
		}

		$text = $this->plainText(activity: $payload);
		$channelData = $this->mapAt(value: ($payload['channelData'] ?? null));
		$tenant = $this->mapAt(value: ($channelData['tenant'] ?? null));

		$configuration = $this->sourceResolver->configurationFor(self::CHANNEL_ID);
		$pattern = null;
		if ($configuration !== null && trim((string)($configuration['casePattern'] ?? '')) !== '') {
			$pattern = trim((string)$configuration['casePattern']);
		}

		$reference = $this->referenceDetector->detectInText(text: $text, pattern: $pattern);
		if ($reference === null) {
			$reference = '';
		}

		$timestamp = ($payload['timestamp'] ?? null);
		if ($timestamp !== null) {
			$timestamp = (string)$timestamp;
		}

		return new InboundMessage(
			channelId: self::CHANNEL_ID,
			externalId: $externalId,
			correspondent: [
				'id' => $authorId,
				'name' => (string)($from['name'] ?? ''),
				'aadObjectId' => (string)($from['aadObjectId'] ?? ''),
			],
			text: $text,
			attachments: $this->attachments(payload: $payload),
			location: null,
			media: [],
			rawPayload: $payload,
			receivedAt: $timestamp,
			fields: [
				'text' => $text,
				'caseReference' => $reference,
				'conversationId' => $conversationId,
				'tenantId' => (string)($tenant['id'] ?? ''),
				'teamId' => (string)($channelData['teamsTeamId'] ?? ''),
				'conversationType' => (string)($conversation['conversationType'] ?? ''),
			]);

	}//end receive()

	/**
	 * Answer in the conversation the message arrived in.
	 *
	 * Not in any other conversation and not by mail: a reply that leaves over
	 * a route the person never wrote on looks like success to everyone except
	 * them.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string $text The reply text.
	 *
	 * @return ReplyResult What happened.
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	public function reply(InboundMessage $message, string $text): ReplyResult {
		$conversationId = $this->conversationOf(message: $message);
		if ($conversationId === '') {
			return ReplyResult::failed(self::CHANNEL_ID, 'The message records no Teams conversation.');
		}

		$configuration = $this->sourceResolver->configurationFor(self::CHANNEL_ID);
		if ($configuration === null) {
			return ReplyResult::failed(
				self::CHANNEL_ID,
				'No Teams source is configured, so there is no connector to answer through.'
			);
		}

		if (($configuration['mock'] ?? false) === true) {
			return ReplyResult::sent(
				self::CHANNEL_ID,
				'MOCK-REPLY-' . substr(sha1($conversationId . $text), 0, 12)
			);
		}

		return $this->post(
			message: $message,
			text: $text,
			conversationId: $conversationId,
			configuration: $configuration
		);

	}//end reply()

	/**
	 * The conversation the message arrived in.
	 *
	 * Read off the raw activity first and off `fields` second, so a message
	 * rebuilt from its stored object still knows where to answer.
	 *
	 * @param InboundMessage $message The message.
	 *
	 * @return string The conversation id, or an empty string.
	 */
	private function conversationOf(InboundMessage $message): string {
		$conversation = $this->mapAt(value: ($message->getRawPayload()['conversation'] ?? null));
		$conversationId = trim((string)($conversation['id'] ?? ''));
		if ($conversationId !== '') {
			return $conversationId;
		}

		return trim((string)($message->getFields()['conversationId'] ?? ''));

	}//end conversationOf()

	/**
	 * Post the reply to the Bot Framework connector.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string $text The reply text.
	 * @param string $conversationId The conversation to answer in.
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return ReplyResult What happened.
	 */
	private function post(
		InboundMessage $message,
		string $text,
		string $conversationId,
		array $configuration,
	): ReplyResult {
		$raw = $message->getRawPayload();

		// CONFIGURED FIRST, payload second. This was the other way round, so a
		// value on the activity overrode the operator's. The payload is still
		// honoured when the source configures nothing, because that is how a
		// multi-tenant connector legitimately works — but it is checked against
		// the trusted list either way.
		$serviceUrl = trim((string)($configuration['serviceUrl'] ?? $raw['serviceUrl'] ?? ''));
		if ($serviceUrl === '') {
			return ReplyResult::failed(
				self::CHANNEL_ID,
				'The activity names no serviceUrl and the source configures none.'
			);
		}

		if ($this->isTrustedServiceUrl(serviceUrl: $serviceUrl, configuration: $configuration) === false) {
			return ReplyResult::failed(
				self::CHANNEL_ID,
				'The serviceUrl is not a trusted connector host, so no reply is sent.'
			);
		}

		$token = trim((string)($configuration['accessToken'] ?? ''));
		if ($token === '') {
			return ReplyResult::failed(
				self::CHANNEL_ID,
				'The Teams source holds no connector access token.'
			);
		}

		$url = rtrim($serviceUrl, '/') . '/v3/conversations/' . rawurlencode($conversationId) . '/activities';

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token],
					'json' => $this->replyBody(message: $message, text: $text),
					'timeout' => 30,
				]
			);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $exception) {
			return ReplyResult::failed(
				self::CHANNEL_ID,
				'Teams refused the reply: ' . $exception->getMessage()
			);
		}

		return ReplyResult::sent(self::CHANNEL_ID, $this->referenceFrom(decoded: $decoded));

	}//end post()

	/**
	 * The activity the reply is posted as.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string $text The reply text.
	 *
	 * @return array<string,mixed> The activity.
	 */
	private function replyBody(InboundMessage $message, string $text): array {
		$body = ['type' => 'message', 'text' => $text];

		$replyToId = trim((string)($message->getRawPayload()['id'] ?? $message->getExternalId()));
		if ($replyToId !== '') {
			$body['replyToId'] = $replyToId;
		}

		return $body;

	}//end replyBody()

	/**
	 * Teams' own id for the posted reply.
	 *
	 * @param mixed $decoded The decoded response body.
	 *
	 * @return string|null The reference, or null when Teams gave none.
	 */
	private function referenceFrom(mixed $decoded): ?string {
		if (is_array($decoded) === false) {
			return null;
		}

		$reference = trim((string)($decoded['id'] ?? ''));
		if ($reference === '') {
			return null;
		}

		return $reference;

	}//end referenceFrom()

	/**
	 * The message text with its markup taken off.
	 *
	 * Teams sends `<at>Name</at>` mentions inline, and HTML when the activity
	 * declares `textFormat: xml`. A rule matching on text should read what was
	 * written, not the markup around it.
	 *
	 * @param array<string,mixed> $activity The Teams activity.
	 *
	 * @return string The plain text.
	 */
	private function plainText(array $activity): string {
		$text = (string)($activity['text'] ?? '');
		if ($text === '') {
			return '';
		}

		$text = strip_tags($text);
		$text = html_entity_decode($text, (ENT_QUOTES | ENT_HTML5), 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text);

		return trim((string)$text);

	}//end plainText()

	/**
	 * The activity's file attachments.
	 *
	 * The bytes stay in Teams. A file upload arrives as a
	 * `file.download.info` attachment carrying a pre-authenticated download
	 * URL, and that URL is what travels: fetching every attachment inside
	 * `receive()` would spend the five seconds Teams allows for the
	 * acknowledgement. Card attachments are not files and are skipped.
	 *
	 * @param array<string,mixed> $payload The Teams activity.
	 *
	 * @return array<int,array<string,mixed>> The attachments.
	 */
	private function attachments(array $payload): array {
		$attachments = [];
		$candidates = ($payload['attachments'] ?? []);
		if (is_array($candidates) === false) {
			return [];
		}

		foreach ($candidates as $index => $attachment) {
			if (is_array($attachment) === false) {
				continue;
			}

			$contentType = (string)($attachment['contentType'] ?? '');
			if (str_starts_with($contentType, 'application/vnd.microsoft.card.') === true) {
				continue;
			}

			$content = $this->mapAt(value: ($attachment['content'] ?? null));
			$downloadUrl = trim((string)($content['downloadUrl'] ?? $attachment['contentUrl'] ?? ''));
			if ($downloadUrl === '') {
				continue;
			}

			$mime = $contentType;
			if ($contentType === self::FILE_DOWNLOAD_INFO) {
				$mime = 'application/octet-stream';
			}

			$attachments[] = [
				'name' => (string)($attachment['name'] ?? ('attachment-' . ((int)$index + 1))),
				'mime' => $mime,
				'size' => 0,
				'content' => '',
				'downloadUrl' => $downloadUrl,
			];
		}//end foreach

		return $attachments;

	}//end attachments()

	/**
	 * The value when it is a map, an empty map otherwise.
	 *
	 * @param mixed $value The value off the activity.
	 *
	 * @return array<string,mixed> The map.
	 */
	private function mapAt(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];

	}//end mapAt()

	/**
	 * Whether a reply may be sent to this serviceUrl.
	 *
	 * Requires https and a host that equals, or is a subdomain of, one of the
	 * trusted suffixes. Anything unparseable is refused rather than allowed.
	 *
	 * @param string $serviceUrl The candidate destination.
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return boolean Whether the destination is trusted.
	 *
	 * @spec openspec/changes/teams-messages-open-cases/specs/intake-channels/spec.md#requirement-a-teams-message-arrives-as-an-intake-channel-req-ic-006
	 */
	private function isTrustedServiceUrl(string $serviceUrl, array $configuration): bool {
		$parts = parse_url($serviceUrl);
		if (is_array($parts) === false) {
			return false;
		}

		if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}

		$host = strtolower(trim((string)($parts['host'] ?? '')));
		if ($host === '') {
			return false;
		}

		foreach ($this->trustedHosts(configuration: $configuration) as $candidate) {
			$suffix = strtolower(trim((string)$candidate));
			if ($suffix === '') {
				continue;
			}

			if ($host === $suffix || str_ends_with($host, '.' . $suffix) === true) {
				return true;
			}
		}

		return false;

	}//end isTrustedServiceUrl()

	/**
	 * The host suffixes this source may reply to.
	 *
	 * Split out so {@see isTrustedServiceUrl()} stays inside the complexity
	 * budget, and because "which hosts are trusted" is a separate question from
	 * "is this URL one of them".
	 *
	 * A configured list REPLACES the default rather than extending it, so an
	 * operator on a sovereign connector is not forced to keep the public hosts.
	 * An empty or malformed value falls back to the default rather than trusting
	 * nothing — the refusal would otherwise look like a broken integration.
	 *
	 * @param array<string,mixed> $configuration The source configuration.
	 *
	 * @return array<int,string> The trusted host suffixes.
	 */
	private function trustedHosts(array $configuration): array {
		$trusted = ($configuration['trustedServiceHosts'] ?? null);
		if (is_array($trusted) === false || $trusted === []) {
			return self::DEFAULT_TRUSTED_SERVICE_HOSTS;
		}

		return $trusted;

	}//end trustedHosts()

}//end class
