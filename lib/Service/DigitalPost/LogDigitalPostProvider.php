<?php

/**
 * The development binding: it writes a line and sends nothing.
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

use Psr\Log\LoggerInterface;

/**
 * It reaches `delivered`, and every result it returns says `simulated`, so a
 * screen can show that the letter never left the instance rather than a
 * delivery nobody made.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#scenario-the-log-binding-answers-delivered
 */
class LogDigitalPostProvider implements DigitalPostProviderInterface {
	/**
	 * The provider id a source configuration names.
	 */
	public const ID = 'log';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(private readonly LoggerInterface $logger) {
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
	 * The log binding needs nothing configured.
	 *
	 * @return array<string,mixed> An empty configuration schema.
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'title' => 'Log',
			'description' => 'Writes what would have been sent to the log. Nothing leaves the instance.',
			'properties' => [],
			'required' => [],
		];
	}//end getConfigSchema()

	/**
	 * Nothing can stop this binding activating.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,string> Always empty.
	 */
	public function activationRefusals(array $config): array {
		unset($config);

		return [];
	}//end activationRefusals()

	/**
	 * Log the message and report it delivered, and simulated.
	 *
	 * @param array<string,mixed> $message The message.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult A simulated delivery.
	 */
	public function send(array $message, array $config = []): DigitalPostResult {
		unset($config);

		// The recipient and the body are deliberately not logged: a BSN and a
		// letter's contents have no business in a log line.
		$this->logger->info(
			'digital-post.log.send',
			[
				'subject' => (string)($message['subject'] ?? ''),
				'attachmentCount' => count((array)($message['attachments'] ?? [])),
			]
		);

		return DigitalPostResult::accepted(
			DigitalPostResult::STATUS_DELIVERED,
			'log-' . bin2hex(random_bytes(8)),
			true
		);
	}//end send()

	/**
	 * A logged message stays delivered.
	 *
	 * @param string $providerReference The provider's reference.
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return DigitalPostResult A simulated delivery.
	 */
	public function status(string $providerReference, array $config = []): DigitalPostResult {
		unset($config);

		return DigitalPostResult::accepted(DigitalPostResult::STATUS_DELIVERED, $providerReference, true);
	}//end status()

	/**
	 * Nothing ever arrives at a log.
	 *
	 * @param array<string,mixed> $config The source configuration.
	 *
	 * @return array<int,array<string,mixed>> Always empty.
	 */
	public function pollInbound(array $config = []): array {
		unset($config);

		return [];
	}//end pollInbound()
}//end class
