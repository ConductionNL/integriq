<?php

/**
 * Integriq BrokerCredentialResolver.
 *
 * A broker subscription names its credential as a reference
 * (`protocolSettings.broker.credentialRef`), never as a password. This class
 * swaps the reference for the secret at publish time, through the same
 * inject-only broker lookup a source uses, and puts the secret where the
 * chosen transport reads it.
 *
 * @category Broker
 * @package  OCA\Integriq\Broker
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
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

use OCA\Integriq\Broker\Transport\RabbitMqHttpTransport;
use OCA\Integriq\Exception\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;

/**
 * Resolves a broker subscription's credential reference into the transport's settings.
 *
 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
 */
class BrokerCredentialResolver {

	/**
	 * Constructor.
	 *
	 * @param BrokeredCallService $brokeredCalls The inject-only credential lookup sources use.
	 */
	public function __construct(
		private readonly BrokeredCallService $brokeredCalls,
	) {
	}//end __construct()

	/**
	 * The settings a transport receives, with any credential reference resolved.
	 *
	 * RabbitMQ signs in with a username and password, so the secret is the
	 * password. Any other broker takes the secret as a password when the
	 * subscription names a username, and as a bearer token when it does not.
	 * The reference itself never reaches the transport.
	 *
	 * @param string $brokerId The broker the subscription publishes through.
	 * @param array  $settings The subscription's `protocolSettings.broker` block.
	 *
	 * @return array The settings for the transport.
	 *
	 * @throws BrokeredCallConfigurationException When the reference cannot be resolved.
	 *
	 * @spec openspec/changes/events-broker-subscription-screen/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
	 */
	public function resolve(string $brokerId, array $settings): array {
		$ref = ($settings['credentialRef'] ?? null);
		if (is_array($ref) === false || $ref === []) {
			return $settings;
		}

		unset($settings['credentialRef']);
		$secret = $this->brokeredCalls->resolveCredentialRef(ref: $ref);

		$username = trim((string)($settings['username'] ?? ''));
		if ($brokerId === RabbitMqHttpTransport::BROKER_ID || $username !== '') {
			$settings['password'] = $secret;
			return $settings;
		}

		$settings['token'] = $secret;
		return $settings;

	}//end resolve()
}//end class
