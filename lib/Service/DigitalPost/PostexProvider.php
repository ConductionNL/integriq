<?php

/**
 * Postex, the commercial digital and paper post route.
 *
 * @category Provider
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Gateway\GatewayTransport;

/**
 * A REST binding over the shared gateway transport, so Postex carries no
 * client of its own and every call lands in the same call log as the rest.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
class PostexProvider implements DigitalPostProviderInterface {
	/**
	 * The provider id a source configuration names.
	 */
	public const ID = 'postex';

	/**
	 * The source slug this binding reads and writes through unless the
	 * configuration names another.
	 */
	public const SOURCE_SLUG = 'postex';

	/**
	 * Constructor.
	 *
	 * @param GatewayTransport $transport The shared outbound transport.
	 */
	public function __construct(private readonly GatewayTransport $transport) {
	}//end __construct()

	/**
	 * The provider id.
	 *
	 * @return string Provider id.
	 */
	public function getProviderId(): string {
		return self::ID;
	}//end getProviderId()

	/**
	 * What a Postex source has to be configured with.
	 *
	 * @return array<string,mixed> The configuration schema.
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'title' => 'Postex',
			'description' => 'Postex digital and paper post. Credentials live on the source, by reference, '
				. 'as for every other API source.',
			'properties' => [
				'source' => [
					'type' => 'string',
					'title' => 'Source',
					'description' => 'The slug of the configured Postex source. Defaults to "postex".',
				],
				'campaign' => [
					'type' => 'string',
					'title' => 'Campaign',
					'description' => 'The Postex campaign a message is sent under.',
				],
			],
			'required' => ['campaign'],
		];
	}//end getConfigSchema()

	/**
	 * Why this source cannot be activated as it stands.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> The refusals.
	 */
	public function activationRefusals(array $config): array {
		if (trim((string)($config['campaign'] ?? '')) === '') {
			return ['Postex needs a campaign. Configure "campaign" with the one this instance sends under.'];
		}

		return [];
	}//end activationRefusals()

	/**
	 * Send one message.
	 *
	 * @param array<string,mixed> $message The message.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult What Postex answered, or the refusal.
	 */
	public function send(array $message, array $config = []): DigitalPostResult {
		$refusals = $this->activationRefusals($config);
		if ($refusals !== []) {
			return DigitalPostResult::refused(implode(' ', $refusals));
		}

		$outcome = $this->transport->send(
			self::ID,
			[
				'campaign' => (string)$config['campaign'],
				'recipient' => ($message['recipient'] ?? ''),
				'subject' => (string)($message['subject'] ?? ''),
				'body' => (string)($message['body'] ?? ''),
				'attachments' => (array)($message['attachments'] ?? []),
			],
			[
				'source' => (string)($config['source'] ?? self::SOURCE_SLUG),
				'endpoint' => '/messages',
				'method' => 'POST',
			]
		);

		if ($outcome->isDelivered() === false) {
			return DigitalPostResult::refused($outcome->getReason());
		}

		return DigitalPostResult::accepted(DigitalPostResult::STATUS_SENT, $outcome->getIdentifier());
	}//end send()

	/**
	 * Ask Postex what became of a message.
	 *
	 * @param string $providerReference The Postex message id.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult The status.
	 */
	public function status(string $providerReference, array $config = []): DigitalPostResult {
		$outcome = $this->transport->send(
			self::ID,
			[],
			[
				'source' => (string)($config['source'] ?? self::SOURCE_SLUG),
				'endpoint' => '/messages/' . rawurlencode($providerReference),
				'method' => 'GET',
			]
		);

		if ($outcome->isDelivered() === false) {
			// A status call that did not answer says nothing about the letter,
			// so the message keeps the state it is in rather than being
			// reported as failed because a poll timed out.
			return DigitalPostResult::accepted(DigitalPostResult::STATUS_SENT, $providerReference);
		}

		return DigitalPostResult::accepted(DigitalPostResult::STATUS_DELIVERED, $providerReference);
	}//end status()

	/**
	 * Everything that arrived for this instance.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,array<string,mixed>> The inbound items.
	 */
	public function pollInbound(array $config = []): array {
		unset($config);

		// Postex is an outbound route. It receives nothing, and saying so is
		// better than an empty poll that looks like a working inbox.
		return [];
	}//end pollInbound()
}//end class
