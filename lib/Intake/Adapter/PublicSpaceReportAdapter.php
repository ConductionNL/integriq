<?php

/**
 * Integriq PublicSpaceReportAdapter.
 *
 * The public space report channel: a resident reports a broken streetlight
 * from a phone, with coordinates and photos. It is the highest volume case
 * type a gemeente has, which is why location and media are first class on the
 * inbound shape rather than pasted into the text.
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
use OCA\Integriq\Intake\ReplyResult;

/**
 * Receives public space reports.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-public-space-report-carries-its-location-and-its-media-req-ic-004
 */
class PublicSpaceReportAdapter implements IntakeChannelAdapterInterface {

	/**
	 * The channel id.
	 *
	 * @var string
	 */
	public const CHANNEL_ID = 'public-space-report';

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
			self::CHANNEL_ID,
			'Public space report',
			false,
			true,
			true,
			['category', 'description', 'street', 'reportedAt'],
		);

	}//end describe()

	/**
	 * Normalise one report.
	 *
	 * @param array<string,mixed> $payload The report as the reporting app sends it.
	 *
	 * @return InboundMessage The normalised message.
	 *
	 * @throws IntakeChannelException When the report names no id.
	 */
	public function receive(array $payload): InboundMessage {
		$externalId = trim((string)($payload['id'] ?? ''));
		if ($externalId === '') {
			throw new IntakeChannelException(
				'A "' . self::CHANNEL_ID . '" report must carry an id, so the same report cannot arrive twice.'
			);
		}

		$reporter = ($payload['reporter'] ?? []);
		if (is_array($reporter) === false) {
			$reporter = [];
		}

		$reportedAt = ($payload['reportedAt'] ?? null);
		if ($reportedAt !== null) {
			$reportedAt = (string)$reportedAt;
		}

		return new InboundMessage(
			self::CHANNEL_ID,
			$externalId,
			[
				'name' => (string)($reporter['name'] ?? ''),
				'address' => (string)($reporter['email'] ?? ''),
				'phone' => (string)($reporter['phone'] ?? ''),
			],
			(string)($payload['description'] ?? ''),
			[],
			$this->location($payload),
			$this->media($payload),
			$payload,
			$reportedAt,
			[
				'category' => (string)($payload['category'] ?? ''),
				'description' => (string)($payload['description'] ?? ''),
				'street' => (string)($payload['location']['address'] ?? ''),
				'reportedAt' => (string)($payload['reportedAt'] ?? ''),
			],
		);

	}//end receive()

	/**
	 * A report channel carries no reply leg.
	 *
	 * @param InboundMessage $message The message being replied to.
	 * @param string $text The reply text.
	 *
	 * @return ReplyResult Always unsupported, never a fallback to another channel.
	 */
	public function reply(InboundMessage $message, string $text): ReplyResult {
		return ReplyResult::unsupported(
			self::CHANNEL_ID,
			'The public space report channel delivers reports and carries no reply leg.'
		);

	}//end reply()

	/**
	 * The report's location, when it has one.
	 *
	 * A report without coordinates gets none: a municipality centroid would
	 * look exactly like a measured position to the person sent out to it.
	 *
	 * @param array<string,mixed> $payload The report.
	 *
	 * @return array<string,mixed>|null The location, or null.
	 */
	private function location(array $payload): ?array {
		$location = ($payload['location'] ?? null);
		if (is_array($location) === false) {
			return null;
		}

		$latitude = ($location['latitude'] ?? null);
		$longitude = ($location['longitude'] ?? null);
		if (is_numeric($latitude) === false || is_numeric($longitude) === false) {
			return null;
		}

		return [
			'latitude' => (float)$latitude,
			'longitude' => (float)$longitude,
			'address' => (string)($location['address'] ?? ''),
		];

	}//end location()

	/**
	 * The report's photos, in the order they were sent.
	 *
	 * @param array<string,mixed> $payload The report.
	 *
	 * @return array<int,array<string,mixed>> The media.
	 */
	private function media(array $payload): array {
		$media = [];
		foreach (($payload['photos'] ?? []) as $index => $photo) {
			if (is_array($photo) === false) {
				continue;
			}

			$content = (string)base64_decode((string)($photo['contentBase64'] ?? ''), false);
			$media[] = [
				'name' => (string)($photo['name'] ?? ('photo-' . ((int)$index + 1) . '.jpg')),
				'mime' => (string)($photo['mime'] ?? 'image/jpeg'),
				'size' => strlen($content),
				'content' => $content,
			];
		}

		return $media;

	}//end media()

}//end class
