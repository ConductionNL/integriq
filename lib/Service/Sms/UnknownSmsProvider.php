<?php

/**
 * Integriq unknown SMS provider.
 *
 * Stands in for a provider value no binding answers to. It sends nothing and
 * refuses both calls naming the value, so the dispatcher stores the message as
 * `failed` with that reason instead of reporting a send that never happened.
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
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;

use OCA\Integriq\Exception\SmsProviderException;

/**
 * The refusing binding for an unknown provider value.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
 */
final class UnknownSmsProvider implements SmsProviderInterface {

	/**
	 * Constructor.
	 *
	 * @param string       $value The provider value the source named.
	 * @param list<string> $known The provider ids the registry holds.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function __construct(
		private readonly string $value,
		private readonly array $known,
	) {

	}//end __construct()

	/**
	 * The value the source named, so the stored message shows it.
	 *
	 * @return string The value.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function getProviderId(): string {
		return $this->value;
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function getProviderName(): string {
		return 'Unknown provider "' . $this->value . '"';
	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty object schema.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object'];
	}//end getConfigSchema()

	/**
	 * Refuse the send, naming the unknown value.
	 *
	 * @param array  $sourceConfiguration The source's configuration.
	 * @param string $to                  The recipient.
	 * @param string $body                The text.
	 * @param array  $options             The options.
	 *
	 * @return DeliveryResult Never returns.
	 *
	 * @throws SmsProviderException Always.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
	 */
	public function send(array $sourceConfiguration, string $to, string $body, array $options = []): DeliveryResult {
		throw $this->refusal();
	}//end send()

	/**
	 * Refuse the status read, naming the unknown value.
	 *
	 * @param array  $sourceConfiguration The source's configuration.
	 * @param string $providerMessageId   The message id.
	 *
	 * @return DeliveryResult Never returns.
	 *
	 * @throws SmsProviderException Always.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function fetchStatus(array $sourceConfiguration, string $providerMessageId): DeliveryResult {
		throw $this->refusal();
	}//end fetchStatus()

	/**
	 * The refusal naming the value and the known ids.
	 *
	 * @return SmsProviderException The exception.
	 */
	private function refusal(): SmsProviderException {
		return new SmsProviderException(
			message: 'Unknown SMS provider "' . $this->value . '"; the SMS source must name one of: ' . implode(', ', $this->known) . '.',
			errorCode: 'unknown_provider'
		);
	}//end refusal()
}//end class
