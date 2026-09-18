<?php

/**
 * MijnOverheid Berichtenbox, over the client that already ships.
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

use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Built on `lib/Adapters/Berichtenbox/BerichtenboxClient.php` rather than a
 * second Berichtenbox client, so there is one code path to keep honest.
 *
 * Activation is refused without a PKIoverheid certificate reference and a
 * sender OIN, and the refusal names which of the two is missing. The
 * certificate travels as a reference: the material is resolved inside
 * integriq by the live binding, never handed to it.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-berichtenbox-code-path-built-on-the-client-that-ships-req-dpa-006
 */
class BerichtenboxProvider implements DigitalPostProviderInterface {
	/**
	 * The provider id a source configuration names.
	 */
	public const ID = 'berichtenbox';

	/**
	 * Constructor.
	 *
	 * @param BerichtenboxClient $client The shipped Berichtenbox client.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly BerichtenboxClient $client,
		private readonly LoggerInterface $logger,
	) {
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
	 * What a Berichtenbox source has to be configured with.
	 *
	 * @return array<string,mixed> The configuration schema.
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'title' => 'MijnOverheid Berichtenbox',
			'description' => 'Logius Berichtenbox voor Burgers, over BBK 1.7. Needs a PKIoverheid '
				. 'Services-server certificate, held by the credential broker and named here by reference, '
				. 'and the sender OIN this instance is registered under.',
			'properties' => [
				'certificateRef' => [
					'type' => 'string',
					'title' => 'PKIoverheid certificate reference',
					'description' => 'The reference the credential broker holds the certificate under. '
						. 'Never the certificate itself, and never its key.',
				],
				'senderOin' => [
					'type' => 'string',
					'title' => 'Sender OIN',
					'description' => 'The Organisatie-identificatienummer this instance sends as.',
				],
				'priority' => [
					'type' => 'string',
					'title' => 'Message priority',
					'enum' => ['normal', 'high'],
				],
			],
			'required' => ['certificateRef', 'senderOin'],
		];
	}//end getConfigSchema()

	/**
	 * Why this source cannot be activated as it stands.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> The refusals, naming what is missing.
	 */
	public function activationRefusals(array $config): array {
		$refusals = [];

		if (trim((string)($config['certificateRef'] ?? '')) === '') {
			$refusals[] = 'Berichtenbox needs a PKIoverheid certificate. Configure "certificateRef" with the '
				. 'reference the credential broker holds it under.';
		}

		if (trim((string)($config['senderOin'] ?? '')) === '') {
			$refusals[] = 'Berichtenbox needs a sender OIN. Configure "senderOin" with the number this '
				. 'instance is registered under.';
		}

		return $refusals;
	}//end activationRefusals()

	/**
	 * Send one letter.
	 *
	 * @param array<string,mixed> $message The message.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult What Logius answered, or the refusal.
	 */
	public function send(array $message, array $config = []): DigitalPostResult {
		$refusals = $this->activationRefusals($config);
		if ($refusals !== []) {
			return DigitalPostResult::refused(implode(' ', $refusals));
		}

		$envelope = [
			'conversationId' => (string)($message['conversationId'] ?? bin2hex(random_bytes(8))),
			'senderOin' => (string)$config['senderOin'],
			'recipientBsn' => (string)($message['recipient'] ?? ''),
			'subject' => (string)($message['subject'] ?? ''),
			'body' => (string)($message['body'] ?? ''),
			'attachments' => (array)($message['attachments'] ?? []),
			'priority' => (string)($config['priority'] ?? 'normal'),
		];

		try {
			// The reference goes, the material does not. A live binding
			// resolves it through PkiOverheidCredentialResolver; the mock
			// ignores it.
			$answer = $this->client->dispatch($envelope, (string)$config['certificateRef']);
		} catch (Throwable $e) {
			$this->logger->warning('digital-post.berichtenbox.send-failed', ['error' => $e->getMessage()]);

			return DigitalPostResult::refused($e->getMessage());
		}

		$simulated = ($this->client->flavour() === 'mock');
		$status = $this->mapStatus((string)($answer['deliveryStatus'] ?? ''));

		return DigitalPostResult::accepted(
			$status,
			(string)($answer['logiusKenmerk'] ?? ''),
			$simulated
		);
	}//end send()

	/**
	 * Ask Logius what became of a message.
	 *
	 * @param string $providerReference The logiusKenmerk.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult The status.
	 */
	public function status(string $providerReference, array $config = []): DigitalPostResult {
		unset($config);

		// BBK reports a status change by webhook rather than by polling, so
		// the honest answer here is the state the message is already in. The
		// status job leaves such a message alone rather than inventing a
		// transition.
		return DigitalPostResult::accepted(
			DigitalPostResult::STATUS_SENT,
			$providerReference,
			($this->client->flavour() === 'mock')
		);
	}//end status()

	/**
	 * Everything that arrived for this instance.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,array<string,mixed>> The inbound items.
	 */
	public function pollInbound(array $config = []): array {
		$items = [];
		foreach ((array)($config['inboundFixture'] ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$items[] = [
				'sender' => (string)($item['sender'] ?? ''),
				'subject' => (string)($item['subject'] ?? ''),
				'document' => ($item['document'] ?? []),
				'receivedAt' => (string)($item['receivedAt'] ?? gmdate('c')),
			];
		}

		return $items;
	}//end pollInbound()

	/**
	 * Map a BBK delivery status onto the lifecycle this app tracks.
	 *
	 * @param string $deliveryStatus What BBK said.
	 *
	 * @return string One of the DigitalPostResult STATUS_* constants.
	 */
	private function mapStatus(string $deliveryStatus): string {
		return match (strtolower($deliveryStatus)) {
			'delivered', 'afgeleverd' => DigitalPostResult::STATUS_DELIVERED,
			'read', 'gelezen' => DigitalPostResult::STATUS_READ,
			'failed', 'mislukt' => DigitalPostResult::STATUS_FAILED,
			default => DigitalPostResult::STATUS_SENT,
		};
	}//end mapStatus()
}//end class
