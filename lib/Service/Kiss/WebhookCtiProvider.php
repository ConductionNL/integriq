<?php

/**
 * Integriq Webhook CTI Provider.
 *
 * The binding a real PBX posts to. It owns no vendor knowledge: the field
 * mapping and the kind map come from the source's configuration, so a new
 * phone system is a configuration change rather than a class.
 *
 * Verification reuses {@see \OCA\Integriq\Service\WebhookSignatureService}, the
 * same HMAC-over-the-raw-body check four other inbound endpoints already use,
 * rather than a signature scheme of its own. A source that configures no
 * signature falls back to a shared secret in a header; a source that
 * configures NEITHER is refused, because an unauthenticated public endpoint is
 * not a configuration anybody chooses on purpose.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Service\WebhookSignatureService;

/**
 * The mapped-webhook telephony binding.
 */
class WebhookCtiProvider implements CtiProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param CallEventNormaliser $normaliser The shared normaliser.
	 * @param WebhookSignatureService $signatures The shared HMAC check.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CallEventNormaliser $normaliser,
		private readonly WebhookSignatureService $signatures,
	) {
	}//end __construct()

	/**
	 * The binding id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function getProviderId(): string {
		return 'webhook';

	}//end getProviderId()

	/**
	 * What this binding needs configured.
	 *
	 * @return array<string, mixed> The JSON Schema fragment.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function getConfigSchema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'signatureSecret' => [
					'type'        => 'string',
					'title'       => 'Signature secret',
					'description' => 'HMAC secret the phone system signs the request body with. '
						.'Preferred over a shared secret, because a signature covers the body as well as the caller.',
				],
				'sharedSecret'    => [
					'type'        => 'string',
					'title'       => 'Shared secret',
					'description' => 'Used when the phone system cannot sign: the value it must send in X-Api-Key. '
						.'One of the two is required.',
				],
				'eventsPath'      => [
					'type'        => 'string',
					'title'       => 'Events path',
					'description' => 'Dotted path to the list of events in the payload. Leave empty when the body is one event.',
				],
				'fieldMapping'    => [
					'type'        => 'object',
					'title'       => 'Field mapping',
					'description' => 'Our field name to the vendor dotted path: kind, callId, callerNumber, agentId, at, durationSeconds.',
				],
				'kindMapping'     => [
					'type'        => 'object',
					'title'       => 'Kind mapping',
					'description' => 'The vendor word for each call kind, such as alerting to ringing. A kind that maps to none of ours is dropped.',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * Whether the request is genuine.
	 *
	 * @param array $sourceConfiguration The source configuration.
	 * @param array $headers The inbound headers.
	 * @param string $rawBody The raw body.
	 *
	 * @return boolean True when the request is genuine.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function verify(array $sourceConfiguration, array $headers, string $rawBody): bool {
		$signatureSecret = (string) ($sourceConfiguration['signatureSecret'] ?? '');
		if ($signatureSecret !== '') {
			return $this->signatures->verify(
				rawBody: $rawBody,
				headerValue: (string) ($headers['x-signature'] ?? ''),
				config: ['secret' => $signatureSecret, 'timestamp' => ($headers['x-timestamp'] ?? '')]
			);
		}

		$sharedSecret = (string) ($sourceConfiguration['sharedSecret'] ?? '');
		if ($sharedSecret !== '') {
			return hash_equals($sharedSecret, (string) ($headers['x-api-key'] ?? ''));
		}

		// Neither configured. Refusing is the only safe reading: an
		// unauthenticated public endpoint is not something anybody configures
		// on purpose, and admitting here would make it one silently.
		return false;

	}//end verify()

	/**
	 * Normalise a vendor payload through the source's mapping.
	 *
	 * @param array $sourceConfiguration The source configuration.
	 * @param array $payload The decoded payload.
	 *
	 * @return array<int, array<string, mixed>> The normalised events.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function normalize(array $sourceConfiguration, array $payload): array {
		$items = $this->itemsFrom(
			payload: $payload,
			path: (string) ($sourceConfiguration['eventsPath'] ?? '')
		);

		$mapping = ($sourceConfiguration['fieldMapping'] ?? []);
		$kindMap = ($sourceConfiguration['kindMapping'] ?? []);

		$safeMapping = [];
		if (is_array($mapping) === true) {
			$safeMapping = $mapping;
		}

		$safeKindMap = [];
		if (is_array($kindMap) === true) {
			$safeKindMap = $kindMap;
		}

		$events = [];
		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$normalised = $this->normaliser->normalise(
				payload: $item,
				mapping: $safeMapping,
				kindMap: $safeKindMap
			);

			if ($normalised !== null) {
				$events[] = $normalised;
			}
		}

		return $events;

	}//end normalize()

	/**
	 * The events in a payload.
	 *
	 * @param array $payload The payload.
	 * @param string $path Dotted path to the list, or '' when the body is one event.
	 *
	 * @return array<int, mixed> The events.
	 */
	private function itemsFrom(array $payload, string $path): array {
		if ($path === '') {
			return [$payload];
		}

		$value = $payload;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return [];
			}

			$value = $value[$segment];
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];

	}//end itemsFrom()

}//end class
