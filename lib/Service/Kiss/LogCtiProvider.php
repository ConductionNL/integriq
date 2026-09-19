<?php

/**
 * Integriq Log CTI Provider.
 *
 * The sandbox binding: accepts a payload already in the shared shape, verifies
 * against a plain shared secret, and logs what it normalised. Use it to wire a
 * KCC panel end to end before a real PBX exists.
 *
 * It still VERIFIES. A sandbox binding that admitted anything would be an
 * unauthenticated public endpoint on every instance that left one configured,
 * and "it is only the log provider" is exactly what nobody checks.
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

use Psr\Log\LoggerInterface;

/**
 * The sandbox telephony binding.
 */
class LogCtiProvider implements CtiProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param CallEventNormaliser $normaliser The shared normaliser.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CallEventNormaliser $normaliser,
		private readonly LoggerInterface $logger,
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
		return 'log';

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
			'required'   => ['sharedSecret'],
			'properties' => [
				'sharedSecret' => [
					'type'        => 'string',
					'title'       => 'Shared secret',
					'description' => 'The value a caller must send in X-Api-Key. Required even here: a binding that admits anything is a public endpoint.',
				],
			],
		];

	}//end getConfigSchema()

	/**
	 * Whether the caller presented the configured secret.
	 *
	 * @param array $sourceConfiguration The source configuration.
	 * @param array $headers The inbound headers.
	 * @param string $rawBody The raw body.
	 *
	 * @return boolean True when the secret matches.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function verify(array $sourceConfiguration, array $headers, string $rawBody): bool {
		$expected = (string) ($sourceConfiguration['sharedSecret'] ?? '');
		if ($expected === '') {
			// An unconfigured secret is not an open door.
			return false;
		}

		return hash_equals($expected, (string) ($headers['x-api-key'] ?? ''));

	}//end verify()

	/**
	 * Normalise a payload already in the shared shape.
	 *
	 * @param array $sourceConfiguration The source configuration.
	 * @param array $payload The decoded payload.
	 *
	 * @return array<int, array<string, mixed>> The normalised events.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function normalize(array $sourceConfiguration, array $payload): array {
		$items = ($payload['events'] ?? [$payload]);
		if (is_array($items) === false) {
			return [];
		}

		$events = [];
		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$normalised = $this->normaliser->normalise(payload: $item);
			if ($normalised !== null) {
				$this->logger->info('[LogCtiProvider] '.$normalised['kind'].' on call '.$normalised['callId']);
				$events[] = $normalised;
			}
		}

		return $events;

	}//end normalize()

}//end class
