<?php

/**
 * Integriq Subscription Secret Masker.
 *
 * Masks the secrets in an event subscription's `protocolSettings` before the
 * app's own subscription endpoints return it. `protocolSettings` is `writeOnly`
 * on the `event_subscription` schema
 * (`register.d/99-event-subscription-secrets-writeonly.json`), but those
 * endpoints serve the entity's stored array, so this class is what keeps the
 * write-only contract on them (integriq#2211).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * Masks every secret under a subscription's `protocolSettings`.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
 */
class SubscriptionSecretMasker {

	/**
	 * The webhook signing secrets under `protocolSettings`, masked as `**********`.
	 *
	 * @var array<int, string>
	 */
	private const SIGNING_SECRET_KEYS = ['signingSecret', 'previousSigningSecret'];

	/**
	 * Keys under `protocolSettings` whose name looks secret but whose value is not.
	 *
	 * `secretRotatedAt` is the rotation timestamp that starts the grace window.
	 *
	 * @var array<int, string>
	 */
	private const NON_SECRET_SETTING_KEYS = ['secretRotatedAt'];

	/**
	 * Mask every secret in a subscription's `protocolSettings`.
	 *
	 * The signing secrets keep their `**********` marker (the signing modal tests
	 * for its presence). Every other secret-shaped key under `protocolSettings`,
	 * at any depth, is masked through {@see SensitiveFieldRegistry}: a broker
	 * `password` or `token` under `protocolSettings.broker` (read by the RabbitMQ
	 * and Kafka REST transports) and an `Authorization` header under
	 * `protocolSettings.headers`. A `credentialRef` and the non-secret connection
	 * settings are left as they are, so the form can still show the connection
	 * and that a secret is stored.
	 *
	 * @param array $subscription The subscription object array.
	 *
	 * @return array The same array with secret fields replaced by a marker.
	 *
	 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-3
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
	 */
	public function mask(array $subscription): array {
		if (isset($subscription['protocolSettings']) === false || is_array($subscription['protocolSettings']) === false) {
			return $subscription;
		}

		$settings = $subscription['protocolSettings'];
		$registry = new SensitiveFieldRegistry();

		foreach ($settings as $key => $value) {
			$settings[$key] = $this->maskSetting(key: (string) $key, value: $value, registry: $registry);
		}

		$subscription['protocolSettings'] = $settings;

		return $subscription;
	}//end mask()

	/**
	 * Mask one top-level `protocolSettings` value.
	 *
	 * A signing secret becomes the `**********` marker, a known non-secret key is
	 * returned as is, a nested array is masked through the registry at any depth,
	 * and a scalar whose key looks secret becomes the registry placeholder.
	 *
	 * @param string                 $key      The setting key.
	 * @param mixed                  $value    The stored value.
	 * @param SensitiveFieldRegistry $registry The registry that knows secret-shaped names.
	 *
	 * @return mixed The value to serve.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-stored-broker-secrets-are-masked-on-the-apps-subscription-endpoints-req-ebsc-004
	 */
	private function maskSetting(string $key, mixed $value, SensitiveFieldRegistry $registry): mixed {
		if ($value === null || $value === '' || in_array($key, self::NON_SECRET_SETTING_KEYS, true) === true) {
			return $value;
		}

		if (in_array($key, self::SIGNING_SECRET_KEYS, true) === true) {
			return '**********';
		}

		if (is_array($value) === true) {
			return $registry->redactArray(data: $value);
		}

		if ($registry->isSensitiveName(name: $key) === true) {
			return SensitiveFieldRegistry::PLACEHOLDER;
		}

		return $value;
	}//end maskSetting()
}//end class
