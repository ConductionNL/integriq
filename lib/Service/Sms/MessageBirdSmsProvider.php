<?php

/**
 * Integriq MessageBird SMS provider.
 *
 * Sends through the MessageBird SMS API: `POST {baseUrl}/messages`, the
 * access key injected by the credential broker as `Authorization: AccessKey`.
 * The status read is `GET {baseUrl}/messages/{id}`, mapped from the first
 * recipient's status.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Sms
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
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;

use OCA\Integriq\Exception\SmsProviderException;

/**
 * MessageBird SMS binding, provider id `messagebird`.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
 */
class MessageBirdSmsProvider extends BrokeredSmsProvider {

	public const PROVIDER_ID = 'messagebird';

	public const DEFAULT_BASE_URL = 'https://rest.messagebird.com';

	/**
	 * MessageBird recipient statuses and the status each maps to.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'scheduled' => 'queued',
		'sent' => 'sent',
		'buffered' => 'sent',
		'delivered' => 'delivered',
		'expired' => 'failed',
		'delivery_failed' => 'failed',
	];

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function getProviderId(): string {
		return self::PROVIDER_ID;
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider display name.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function getProviderName(): string {
		return 'MessageBird';
	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The configuration schema.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function getConfigSchema(): array {
		return self::brokeredConfigSchema(
			defaultBaseUrl: self::DEFAULT_BASE_URL,
			credentialHelp: 'The brokered MessageBird access key, injected as "Authorization: AccessKey {secret}".'
		);
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $sourceConfiguration The SMS source's configuration.
	 * @param string $to                  The E.164 recipient.
	 * @param string $body                The message text.
	 * @param array  $options             `reference` to send as MessageBird's reference.
	 *
	 * @return DeliveryResult MessageBird's id and mapped status.
	 *
	 * @throws SmsProviderException When the source is incomplete or MessageBird refuses the message.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function send(array $sourceConfiguration, string $to, string $body, array $options = []): DeliveryResult {
		$json = [
			'originator' => $this->sender(sourceConfiguration: $sourceConfiguration),
			'recipients' => [ltrim($to, '+')],
			'body' => $body,
		];
		if ((string)($options['reference'] ?? '') !== '') {
			$json['reference'] = (string)$options['reference'];
		}

		$response = $this->decode(
			body: $this->dispatch(
				sourceConfiguration: $sourceConfiguration,
				method: 'POST',
				url: self::baseUrl(sourceConfiguration: $sourceConfiguration, default: self::DEFAULT_BASE_URL) . '/messages',
				request: ['json' => $json]
			)
		);

		return $this->result(response: $response);
	}//end send()

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $sourceConfiguration The SMS source's configuration.
	 * @param string $providerMessageId   MessageBird's message id.
	 *
	 * @return DeliveryResult The mapped status.
	 *
	 * @throws SmsProviderException When the read fails.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function fetchStatus(array $sourceConfiguration, string $providerMessageId): DeliveryResult {
		$response = $this->decode(
			body: $this->dispatch(
				sourceConfiguration: $sourceConfiguration,
				method: 'GET',
				url: self::baseUrl(sourceConfiguration: $sourceConfiguration, default: self::DEFAULT_BASE_URL) . '/messages/' . rawurlencode($providerMessageId),
				request: []
			)
		);

		return $this->result(response: $response);
	}//end fetchStatus()

	/**
	 * The id and mapped status of a MessageBird message object.
	 *
	 * @param array<string, mixed> $response The decoded message.
	 *
	 * @return DeliveryResult The result.
	 *
	 * @throws SmsProviderException When the message has no id.
	 */
	private function result(array $response): DeliveryResult {
		$vendorStatus = strtolower((string)($response['recipients']['items'][0]['status'] ?? 'unknown'));

		return new DeliveryResult(
			providerMessageId: $this->requiredField(decoded: $response, field: 'id'),
			status: (self::STATUS_MAP[$vendorStatus] ?? 'queued'),
			detail: 'MessageBird status: ' . $vendorStatus
		);
	}//end result()
}//end class
