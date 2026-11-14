<?php

/**
 * Integriq CM.com SMS provider.
 *
 * Sends through the CM.com Business Messaging API: `POST {baseUrl}/message`,
 * the product token injected by the credential broker as the
 * `X-CM-PRODUCTTOKEN` header. CM.com assigns no message id, so the binding
 * sends its own reference and keeps that as the provider message id; CM.com
 * reports delivery through its status callback, not through a status read.
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
 * CM.com SMS binding, provider id `cmcom`.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
 */
class CmComSmsProvider extends BrokeredSmsProvider {

	public const PROVIDER_ID = 'cmcom';

	/**
	 * CM.com status report codes and the status each maps to.
	 *
	 * @var array<int, string>
	 */
	private const STATUS_MAP = [
		'0' => 'sent',
		'1' => 'failed',
		'2' => 'delivered',
		'3' => 'failed',
	];

	public const DEFAULT_BASE_URL = 'https://gw.messaging.cm.com/v1.0';

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
		return 'CM.com';
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
			credentialHelp: 'The brokered CM.com product token, injected as the X-CM-PRODUCTTOKEN header.'
		);
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $sourceConfiguration The SMS source's configuration.
	 * @param string $to                  The E.164 recipient.
	 * @param string $body                The message text.
	 * @param array  $options             `reference` to send as CM.com's reference.
	 *
	 * @return DeliveryResult The acceptance, with the reference as provider message id.
	 *
	 * @throws SmsProviderException When the source is incomplete or CM.com refuses the message.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function send(array $sourceConfiguration, string $to, string $body, array $options = []): DeliveryResult {
		$reference = (string)($options['reference'] ?? '');
		if ($reference === '') {
			$reference = bin2hex(random_bytes(16));
		}

		$message = [
			'from' => $this->sender(sourceConfiguration: $sourceConfiguration),
			'to' => [['number' => $to]],
			'body' => ['type' => 'auto', 'content' => $body],
			'reference' => $reference,
		];
		$response = $this->decode(
			body: $this->dispatch(
				sourceConfiguration: $sourceConfiguration,
				method: 'POST',
				url: self::baseUrl(sourceConfiguration: $sourceConfiguration, default: self::DEFAULT_BASE_URL) . '/message',
				request: ['json' => ['messages' => ['msg' => [$message]]]]
			)
		);

		$accepted = ($response['messages'][0] ?? []);
		if (is_array($accepted) === false || strtolower((string)($accepted['status'] ?? '')) !== 'accepted') {
			throw new SmsProviderException(
				message: 'CM.com did not accept the message: ' . (string)($response['details'] ?? 'no details'),
				errorCode: 'provider_rejected'
			);
		}

		return new DeliveryResult(providerMessageId: $reference, status: 'sent', detail: 'Accepted by CM.com');
	}//end send()

	/**
	 * CM.com offers no status read: delivery arrives through its status callback.
	 *
	 * @param array  $sourceConfiguration The SMS source's configuration.
	 * @param string $providerMessageId   The reference the message was sent with.
	 *
	 * @return DeliveryResult Never returns.
	 *
	 * @throws SmsProviderException Always, naming the callback as the way status arrives.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function fetchStatus(array $sourceConfiguration, string $providerMessageId): DeliveryResult {
		throw new SmsProviderException(
			message: 'CM.com has no status read; the status of "' . $providerMessageId . '" arrives through its delivery callback.',
			errorCode: 'status_read_unsupported'
		);
	}//end fetchStatus()

	/**
	 * CM.com does not sign its status reports: the per-source token in the callback URL is the check.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function signsStatusCallbacks(): bool {
		return false;
	}//end signsStatusCallbacks()

	/**
	 * Read a CM.com status report: the reference the message was sent with and its status code.
	 *
	 * Accepts the flat report (`reference`, `statuscode` or `status`) and the
	 * JSON report nesting it under `messages.msg`, whose `status` is an object
	 * with a `code`. Codes: 0 accepted, 1 rejected, 2 delivered, 3 failed.
	 *
	 * @param array             $sourceConfiguration The SMS source's configuration (unused).
	 * @param SmsStatusCallback $callback            The callback as it arrived.
	 *
	 * @return DeliveryResult The reference and mapped status.
	 *
	 * @throws SmsProviderException When the report names no reference.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
	 */
	public function readStatusCallback(array $sourceConfiguration, SmsStatusCallback $callback): DeliveryResult {
		$report = $callback->params;
		$nested = ($report['messages']['msg'] ?? null);
		if (is_array($nested) === true) {
			if (array_is_list($nested) === true) {
				$nested = ($nested[0] ?? []);
			}

			$report = (array)$nested;
		}

		$status = ($report['status'] ?? null);
		if (is_array($status) === true) {
			$report['statuscode'] = ($status['code'] ?? null);
			$report['errordescription'] = ($status['errorDescription'] ?? ($report['errordescription'] ?? null));
		}

		$reference = self::callbackField(params: $report, names: ['reference']);
		if ($reference === '') {
			throw $this->unreadableCallback();
		}

		$code = self::callbackField(params: $report, names: ['statuscode', 'status']);
		$detail = 'CM.com status code: ' . $code;
		$error = self::callbackField(params: $report, names: ['errordescription']);
		if ($error !== '') {
			$detail .= ' (' . $error . ')';
		}

		return new DeliveryResult(providerMessageId: $reference, status: (self::STATUS_MAP[$code] ?? 'sent'), detail: $detail);
	}//end readStatusCallback()
}//end class
