<?php

/**
 * Integriq brokered SMS provider base.
 *
 * The shared transport of the SMS bindings whose vendor takes a static
 * credential: the product token, access key or Basic value is held by the
 * OpenRegister credential broker under the source's
 * `configuration.authentication.credentialRef`, and every call goes through
 * {@see BrokeredCallService::prepare()} and `dispatch()` exactly as the Mollie
 * binding does. Without a `credentialRef` nothing leaves the instance: there is
 * no plaintext-key fallback (ADR-064).
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
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Brokered dispatch shared by the static-credential SMS bindings.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
 */
abstract class BrokeredSmsProvider implements SmsProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param BrokeredCallService $brokeredCallService The OpenRegister credential broker seam.
	 * @param LoggerInterface     $logger              Logs unexpected transport failures, never a credential.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function __construct(
		protected readonly BrokeredCallService $brokeredCallService,
		protected readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The source's base URL without a trailing slash, or the vendor default.
	 *
	 * @param array  $sourceConfiguration The source's configuration.
	 * @param string $default             The vendor's public API base URL.
	 *
	 * @return string The base URL.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	protected static function baseUrl(array $sourceConfiguration, string $default): string {
		$baseUrl = trim((string)($sourceConfiguration['baseUrl'] ?? ''));
		if ($baseUrl === '') {
			$baseUrl = $default;
		}

		return rtrim($baseUrl, '/');
	}//end baseUrl()

	/**
	 * The sender id or number the source sends from, refusing a source without one.
	 *
	 * @param array $sourceConfiguration The source's configuration.
	 *
	 * @return string The sender.
	 *
	 * @throws SmsProviderException When the source names no sender.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	protected function sender(array $sourceConfiguration): string {
		$sender = trim((string)($sourceConfiguration['senderId'] ?? ''));
		if ($sender === '') {
			throw new SmsProviderException(
				message: 'The `' . $this->getProviderId() . '` SMS source has no `configuration.senderId`; set the sender name or number it sends from.',
				errorCode: 'sender_missing'
			);
		}

		return $sender;
	}//end sender()

	/**
	 * The JSON Schema fragment every brokered binding shares: base URL, sender and broker reference.
	 *
	 * @param string $defaultBaseUrl The vendor's public API base URL.
	 * @param string $credentialHelp What the brokered secret must hold for this vendor.
	 *
	 * @return array<string, mixed> The schema.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	protected static function brokeredConfigSchema(string $defaultBaseUrl, string $credentialHelp): array {
		return [
			'type' => 'object',
			'required' => ['senderId', 'authentication'],
			'properties' => [
				'provider' => ['type' => 'string'],
				'baseUrl' => ['type' => 'string', 'format' => 'uri', 'default' => $defaultBaseUrl],
				'senderId' => ['type' => 'string', 'description' => 'The sender name or number messages are sent from.'],
				'authentication' => [
					'type' => 'object',
					'required' => ['credentialRef'],
					'properties' => [
						'credentialRef' => ['type' => 'object', 'description' => $credentialHelp],
					],
				],
			],
		];
	}//end brokeredConfigSchema()

	/**
	 * Send one brokered call and return its body, refusing a missing credential or a non-2xx answer.
	 *
	 * @param array  $sourceConfiguration The source's configuration.
	 * @param string $method              The HTTP method.
	 * @param string $url                 The full URL.
	 * @param array  $request             The request: `headers`, and `json` or `body`.
	 *
	 * @return string The response body.
	 *
	 * @throws SmsProviderException When no credential is configured, the broker refuses, or the vendor answers non-2xx.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	protected function dispatch(array $sourceConfiguration, string $method, string $url, array $request): string {
		$config = ['authentication' => ($sourceConfiguration['authentication'] ?? [])] + $request;
		if ($this->brokeredCallService->hasCredentialRef(config: $config) === false) {
			throw new SmsProviderException(
				message: 'SMS provider credential missing: the `' . $this->getProviderId() . '` provider requires '
					. '`configuration.authentication.credentialRef`, and none is configured. Configure one through '
					. 'the OpenRegister credential broker; there is no plaintext-key fallback.',
				errorCode: 'credential_missing'
			);
		}

		try {
			$prepared = $this->brokeredCallService->prepare(config: $config, sourceData: ['type' => 'sms'], asynchronous: false);
			$response = $this->brokeredCallService->dispatch(
				credentialId: $prepared['credentialId'],
				actingUserId: $prepared['actingUserId'],
				method: $method,
				url: $url,
				config: $config
			);
		} catch (BrokeredCallConfigurationException $exception) {
			throw new SmsProviderException(message: $exception->getMessage(), previous: $exception);
		} catch (Throwable $exception) {
			throw $this->unexpectedFailure(exception: $exception);
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new SmsProviderException(
				message: $this->getProviderName() . ' responded with HTTP ' . $status . '.',
				errorCode: 'provider_http_' . $status
			);
		}

		return (string)$response->getBody();
	}//end dispatch()

	/**
	 * Decode a JSON response body into an array, refusing anything else.
	 *
	 * @param string $body The response body.
	 *
	 * @return array<string, mixed> The decoded object.
	 *
	 * @throws SmsProviderException When the body is not a JSON object.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	protected function decode(string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			throw new SmsProviderException(message: $this->getProviderName() . ' returned a response that is not a JSON object.');
		}

		return $decoded;
	}//end decode()

	/**
	 * A string field of a decoded response, refusing an empty or missing one.
	 *
	 * @param array<string, mixed> $decoded The decoded response.
	 * @param string               $field   The field name.
	 *
	 * @return string The value.
	 *
	 * @throws SmsProviderException When the field is missing or empty.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	protected function requiredField(array $decoded, string $field): string {
		$value = $decoded[$field] ?? null;
		if (is_scalar($value) === false || (string)$value === '') {
			throw new SmsProviderException(message: $this->getProviderName() . ' returned no `' . $field . '`.');
		}

		return (string)$value;
	}//end requiredField()

	/**
	 * Log an unexpected transport failure and wrap it.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return SmsProviderException The exception to throw.
	 */
	private function unexpectedFailure(Throwable $exception): SmsProviderException {
		$this->logger->warning(
			'[' . static::class . '] unexpected transport failure',
			['exception' => $exception->getMessage()]
		);

		return new SmsProviderException(
			message: 'The ' . $this->getProviderName() . ' request failed unexpectedly: ' . $exception->getMessage(),
			previous: $exception
		);
	}//end unexpectedFailure()
}//end class
