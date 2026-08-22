<?php

/**
 * Integriq Log SMS Provider.
 *
 * Sandbox/mock binding for {@see SmsProviderInterface}: performs no real
 * network call, needs no credential, and returns a synthetic `MOCK-SMS-<n>`
 * message id from `send()`. Every `fetchStatus()` call reports a deterministic
 * `delivered` result. It is the default for dev/CI and mirrors
 * LogPeppolAccessPointProvider / LogPsd2AggregatorProvider.
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
 * @spec openspec/specs/notifynl-sms-channel/spec.md#scenario-the-log-provider-sends-without-a-network-call-or-secret
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Sms;

/**
 * Sandbox SMS provider: no network call, synthetic message ids, canned status.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md#scenario-the-log-provider-sends-without-a-network-call-or-secret
 */
class LogSmsProvider implements SmsProviderInterface {

	/**
	 * Per-process counter for synthetic message ids (`MOCK-SMS-<n>`).
	 *
	 * A per-process, in-memory counter is sufficient for a sandbox binding —
	 * ids only need to be locally unique for the duration of one request/job
	 * run (mirrors LogPeppolAccessPointProvider::$counter).
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `log` provider identifier.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
	 */
	public function getProviderId(): string {
		return 'log';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The provider display name.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
	 */
	public function getProviderName(): string {
		return 'Sandbox / log (no network call)';
	}//end getProviderName()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty config schema — the log provider needs no configuration.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-log-and-notifynl-rest-provider-bindings-req-002
	 */
	public function getConfigSchema(): array {
		return [
			'type' => 'object',
			'properties' => [],
		];

	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused — the log provider needs no configuration or secret.
	 * @param string $to The recipient in E.164 format.
	 * @param string $body Free-text message body (logged only — no network call is made).
	 * @param array $options Unused.
	 *
	 * @return DeliveryResult A synthetic `queued` result carrying a `MOCK-SMS-<n>` id.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#scenario-the-log-provider-sends-without-a-network-call-or-secret
	 */
	public function send(array $sourceConfiguration, string $to, string $body, array $options = []): DeliveryResult {
		self::$counter++;

		return new DeliveryResult(
			providerMessageId: 'MOCK-SMS-' . self::$counter,
			status: 'queued',
			detail: null
		);

	}//end send()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused.
	 * @param string $providerMessageId The synthetic message id from {@see send()}.
	 *
	 * @return DeliveryResult A deterministic `delivered` result.
	 *
	 * @spec openspec/specs/notifynl-sms-channel/spec.md#requirement-delivery-status-polling-and-callback-req-007
	 */
	public function fetchStatus(array $sourceConfiguration, string $providerMessageId): DeliveryResult {
		return new DeliveryResult(
			providerMessageId: $providerMessageId,
			status: 'delivered',
			detail: 'Mock status: sandbox providers do not track real delivery'
		);

	}//end fetchStatus()
}//end class
