<?php

/**
 * OpenConnector SMS Provider Interface.
 *
 * Narrow domain seam through which every outbound SMS send and delivery-status
 * lookup occurs. A new gateway vendor (MessageBird, Twilio, ...) is added by
 * implementing this interface, never by editing SmsDispatchService or
 * NotifyNlController — mirrors PeppolAccessPointProviderInterface /
 * Psd2AggregatorProviderInterface (see design.md "Provider seam vs category
 * IntegrationProvider").
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Sms;

use OCA\OpenConnector\Exception\SmsProviderException;

/**
 * An SMS channel binding: send-by-template/body + delivery-status lookup.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */
interface SmsProviderInterface {
	/**
	 * Stable machine identifier for this binding (e.g. `log`, `notifynl`).
	 *
	 * Selected at runtime via the SMS source's `configuration.provider` field
	 * — see {@see \OCA\OpenConnector\Service\SmsDispatchService::resolveProvider()}.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-generic-sms-provider-contract-req-001
	 */
	public function getProviderId(): string;

	/**
	 * Human-readable display name for this binding (settings UI, logs).
	 *
	 * @return string The provider display name.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-generic-sms-provider-contract-req-001
	 */
	public function getProviderName(): string;

	/**
	 * The JSON Schema describing this provider's `configuration` object.
	 *
	 * Documents exactly which keys a source of this provider needs (e.g.
	 * `baseUrl`, `authentication`, `senderId`, `templateMapping`) so the
	 * settings surface and operators have one authoritative shape per binding.
	 *
	 * @return array<string, mixed> A JSON Schema (object) fragment.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-generic-sms-provider-contract-req-001
	 */
	public function getConfigSchema(): array;

	/**
	 * Send one SMS message.
	 *
	 * @param array $sourceConfiguration The SMS source's `configuration` object.
	 * @param string $to The recipient in E.164 format (already validated by the caller).
	 * @param string $body Free-text message body. Template-only providers (e.g. NotifyNL) ignore
	 *                     this in favour of `options.templateId` + `options.personalisation` and
	 *                     use it only for local logging/audit context.
	 * @param array $options Provider-specific extras, e.g. `templateId`, `personalisation`, `reference`.
	 *
	 * @return DeliveryResult The provider's initial acceptance result (`providerMessageId` + `status`).
	 *
	 * @throws SmsProviderException When the provider is unreachable, rejects the request, or is misconfigured.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function send(array $sourceConfiguration, string $to, string $body, array $options = []): DeliveryResult;

	/**
	 * Fetch the current delivery status of a previously sent message.
	 *
	 * @param array $sourceConfiguration The SMS source's `configuration` object.
	 * @param string $providerMessageId The provider-assigned message id returned by {@see send()}.
	 *
	 * @return DeliveryResult The current normalised status.
	 *
	 * @throws SmsProviderException When the provider is unreachable, the message id is unknown, or a config error occurs.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md
	 */
	public function fetchStatus(array $sourceConfiguration, string $providerMessageId): DeliveryResult;
}//end interface
