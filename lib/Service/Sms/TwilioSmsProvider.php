<?php

/**
 * Integriq Twilio SMS provider.
 *
 * Sends through the Twilio Programmable Messaging API:
 * `POST {baseUrl}/2010-04-01/Accounts/{accountSid}/Messages.json`, form
 * encoded. The broker substitutes one static secret and cannot compute a
 * value, so it holds the base64 of `SID:token` and the header template is
 * `Basic {secret}`. The account SID itself is plain configuration. The status
 * read is the same path with `/{sid}.json`.
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
 * Twilio SMS binding, provider id `twilio`.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
 */
class TwilioSmsProvider extends BrokeredSmsProvider {

	public const PROVIDER_ID = 'twilio';

	public const DEFAULT_BASE_URL = 'https://api.twilio.com';

	/**
	 * Twilio message statuses and the status each maps to.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'accepted' => 'queued',
		'scheduled' => 'queued',
		'queued' => 'queued',
		'sending' => 'sent',
		'sent' => 'sent',
		'delivered' => 'delivered',
		'undelivered' => 'failed',
		'failed' => 'failed',
		'canceled' => 'failed',
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
		return 'Twilio';
	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> The configuration schema.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function getConfigSchema(): array {
		$schema = self::brokeredConfigSchema(
			defaultBaseUrl: self::DEFAULT_BASE_URL,
			credentialHelp: 'The brokered base64 of "AccountSID:AuthToken", injected as "Authorization: Basic {secret}". '
				. 'The bare auth token is refused by Twilio.'
		);
		$schema['required'][] = 'accountSid';
		$schema['properties']['accountSid'] = [
			'type' => 'string',
			'pattern' => '^AC[0-9a-zA-Z]+$',
			'description' => 'The Twilio account SID.',
		];

		return $schema;
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $sourceConfiguration The SMS source's configuration.
	 * @param string $to                  The E.164 recipient.
	 * @param string $body                The message text.
	 * @param array  $options             Unused.
	 *
	 * @return DeliveryResult Twilio's SID and mapped status.
	 *
	 * @throws SmsProviderException When the source is incomplete or Twilio refuses the message.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function send(array $sourceConfiguration, string $to, string $body, array $options = []): DeliveryResult {
		$form = http_build_query(['To' => $to, 'From' => $this->sender(sourceConfiguration: $sourceConfiguration), 'Body' => $body]);
		$response = $this->decode(
			body: $this->dispatch(
				sourceConfiguration: $sourceConfiguration,
				method: 'POST',
				url: $this->messagesUrl(sourceConfiguration: $sourceConfiguration) . '.json',
				request: ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'body' => $form]
			)
		);

		return $this->result(response: $response);
	}//end send()

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $sourceConfiguration The SMS source's configuration.
	 * @param string $providerMessageId   Twilio's message SID.
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
				url: $this->messagesUrl(sourceConfiguration: $sourceConfiguration) . '/' . rawurlencode($providerMessageId) . '.json',
				request: []
			)
		);

		return $this->result(response: $response);
	}//end fetchStatus()

	/**
	 * The account's Messages resource, without the `.json` suffix.
	 *
	 * @param array $sourceConfiguration The SMS source's configuration.
	 *
	 * @return string The URL.
	 *
	 * @throws SmsProviderException When the source names no valid account SID.
	 */
	private function messagesUrl(array $sourceConfiguration): string {
		$accountSid = trim((string)($sourceConfiguration['accountSid'] ?? ''));
		if (preg_match('/^AC[0-9a-zA-Z]+$/', $accountSid) !== 1) {
			throw new SmsProviderException(
				message: 'The `twilio` SMS source needs `configuration.accountSid`, the account SID starting with "AC".',
				errorCode: 'account_sid_missing'
			);
		}

		return self::baseUrl(sourceConfiguration: $sourceConfiguration, default: self::DEFAULT_BASE_URL)
			. '/2010-04-01/Accounts/' . $accountSid . '/Messages';
	}//end messagesUrl()

	/**
	 * The SID and mapped status of a Twilio message resource.
	 *
	 * @param array<string, mixed> $response The decoded message.
	 *
	 * @return DeliveryResult The result.
	 *
	 * @throws SmsProviderException When the message has no SID.
	 */
	private function result(array $response): DeliveryResult {
		$vendorStatus = strtolower((string)($response['status'] ?? 'unknown'));
		$detail = 'Twilio status: ' . $vendorStatus;
		if ((string)($response['error_message'] ?? '') !== '') {
			$detail .= ' (' . (string)$response['error_message'] . ')';
		}

		return new DeliveryResult(
			providerMessageId: $this->requiredField(decoded: $response, field: 'sid'),
			status: (self::STATUS_MAP[$vendorStatus] ?? 'queued'),
			detail: $detail
		);
	}//end result()

	/**
	 * Twilio signs its status callbacks with an `X-Twilio-Signature` header.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function signsStatusCallbacks(): bool {
		return true;
	}//end signsStatusCallbacks()

	/**
	 * Check a Twilio status callback's signature and read its SID and status.
	 *
	 * The signature is the base64 HMAC-SHA1, keyed with the account's auth
	 * token, of the URL followed by every posted parameter name and value in
	 * name order.
	 *
	 * @param array             $sourceConfiguration The SMS source's configuration (unused).
	 * @param SmsStatusCallback $callback            The callback as it arrived.
	 *
	 * @return DeliveryResult The SID and mapped status.
	 *
	 * @throws SmsProviderException When the signature does not verify or the callback names no message.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function readStatusCallback(array $sourceConfiguration, SmsStatusCallback $callback): DeliveryResult {
		$key = (string)$callback->signingKey;
		$signature = $callback->header(name: 'X-Twilio-Signature');
		if ($key === '' || $signature === '') {
			throw $this->badSignature();
		}

		$posted = [];
		parse_str($callback->rawBody, $posted);
		ksort($posted, SORT_STRING);
		$signed = $callback->url;
		foreach ($posted as $name => $value) {
			if (is_scalar($value) === false) {
				throw $this->badSignature();
			}

			$signed .= $name . (string)$value;
		}

		if (hash_equals(base64_encode(hash_hmac('sha1', $signed, $key, true)), $signature) === false) {
			throw $this->badSignature();
		}

		$sid = self::callbackField(params: $posted, names: ['MessageSid', 'SmsSid']);
		if ($sid === '') {
			throw $this->unreadableCallback();
		}

		$vendorStatus = strtolower(self::callbackField(params: $posted, names: ['MessageStatus', 'SmsStatus']));
		$detail = 'Twilio status: ' . $vendorStatus;
		$errorCode = self::callbackField(params: $posted, names: ['ErrorCode']);
		if ($errorCode !== '') {
			$detail .= ' (error ' . $errorCode . ')';
		}

		return new DeliveryResult(providerMessageId: $sid, status: (self::STATUS_MAP[$vendorStatus] ?? 'queued'), detail: $detail);
	}//end readStatusCallback()
}//end class
