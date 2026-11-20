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
	 * Seconds of clock difference a signature's validity window allows.
	 *
	 * @var int
	 */
	private const CLOCK_LEEWAY = 60;

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

	/**
	 * MessageBird signs its status reports with a `MessageBird-Signature-JWT` header.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function signsStatusCallbacks(): bool {
		return true;
	}//end signsStatusCallbacks()

	/**
	 * Check a MessageBird status report's signature and read its id and status.
	 *
	 * The signature is an HS256 JWT over the source's signing key, carrying the
	 * SHA-256 of the URL (`url_hash`) and, for a non-empty body, of the body
	 * (`payload_hash`), valid between `nbf` and `exp`.
	 *
	 * @param array             $sourceConfiguration The SMS source's configuration (unused).
	 * @param SmsStatusCallback $callback            The callback as it arrived.
	 *
	 * @return DeliveryResult The message id and mapped status.
	 *
	 * @throws SmsProviderException When the signature does not verify or the report names no message.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function readStatusCallback(array $sourceConfiguration, SmsStatusCallback $callback): DeliveryResult {
		$this->verifySignature(callback: $callback);

		$id = self::callbackField(params: $callback->params, names: ['id']);
		if ($id === '') {
			throw $this->unreadableCallback();
		}

		$vendorStatus = strtolower(self::callbackField(params: $callback->params, names: ['status']));

		return new DeliveryResult(
			providerMessageId: $id,
			status: (self::STATUS_MAP[$vendorStatus] ?? 'queued'),
			detail: 'MessageBird status: ' . $vendorStatus
		);
	}//end readStatusCallback()

	/**
	 * Verify the `MessageBird-Signature-JWT` header of a callback.
	 *
	 * @param SmsStatusCallback $callback The callback.
	 *
	 * @return void
	 *
	 * @throws SmsProviderException When the key or token is missing, or the token does not verify.
	 */
	private function verifySignature(SmsStatusCallback $callback): void {
		$key = (string)$callback->signingKey;
		$parts = explode('.', $callback->header(name: 'MessageBird-Signature-JWT'));
		if ($key === '' || count($parts) !== 3) {
			throw $this->badSignature();
		}

		[$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
		$header = json_decode((string)self::base64UrlDecode(value: $encodedHeader), true);
		$claims = json_decode((string)self::base64UrlDecode(value: $encodedClaims), true);
		$signature = self::base64UrlDecode(value: $encodedSignature);
		if (is_array($header) === false || ($header['alg'] ?? null) !== 'HS256' || is_array($claims) === false || $signature === null) {
			throw $this->badSignature();
		}

		$expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedClaims, $key, true);
		if (hash_equals($expected, $signature) === false || self::claimsHold(claims: $claims, callback: $callback) === false) {
			throw $this->badSignature();
		}

	}//end verifySignature()

	/**
	 * Whether a verified token's claims fit this callback and this moment.
	 *
	 * @param array<string, mixed> $claims   The token's claims.
	 * @param SmsStatusCallback    $callback The callback.
	 *
	 * @return bool True when the URL hash, the body hash and the validity window hold.
	 */
	private static function claimsHold(array $claims, SmsStatusCallback $callback): bool {
		$now = time();
		if ((int)($claims['nbf'] ?? PHP_INT_MAX) > ($now + self::CLOCK_LEEWAY) || (int)($claims['exp'] ?? 0) < ($now - self::CLOCK_LEEWAY)) {
			return false;
		}

		if (hash_equals(hash('sha256', $callback->url), (string)($claims['url_hash'] ?? '')) === false) {
			return false;
		}

		if ($callback->rawBody === '') {
			return true;
		}

		return hash_equals(hash('sha256', $callback->rawBody), (string)($claims['payload_hash'] ?? ''));

	}//end claimsHold()

	/**
	 * Decode one base64url segment.
	 *
	 * @param string $value The segment.
	 *
	 * @return string|null The bytes, or null when the segment is not base64url.
	 */
	private static function base64UrlDecode(string $value): ?string {
		$decoded = base64_decode(strtr($value, '-_', '+/'), true);
		if ($decoded === false) {
			return null;
		}

		return $decoded;

	}//end base64UrlDecode()
}//end class
