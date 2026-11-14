<?php

/**
 * Integriq SMS provider registry.
 *
 * Every SMS binding, keyed by its provider id. The dispatcher asks it for the
 * binding a source names: no value means the log binding, a value no binding
 * answers to gets the refusing {@see UnknownSmsProvider}.
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

/**
 * The SMS bindings by provider id.
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
 */
class SmsProviderRegistry {

	/**
	 * The bindings, keyed by provider id, log first.
	 *
	 * @var array<string, SmsProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param LogSmsProvider              $logProvider The default binding, for a source with no provider value.
	 * @param RestNotifyNlProvider|null   $notifyNl    NotifyNL.
	 * @param CmComSmsProvider|null       $cmCom       CM.com.
	 * @param MessageBirdSmsProvider|null $messageBird MessageBird.
	 * @param TwilioSmsProvider|null      $twilio      Twilio.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function __construct(
		private readonly LogSmsProvider $logProvider,
		?RestNotifyNlProvider $notifyNl = null,
		?CmComSmsProvider $cmCom = null,
		?MessageBirdSmsProvider $messageBird = null,
		?TwilioSmsProvider $twilio = null,
	) {
		foreach ([$logProvider, $notifyNl, $cmCom, $messageBird, $twilio] as $provider) {
			if ($provider !== null) {
				$this->providers[$provider->getProviderId()] = $provider;
			}
		}

	}//end __construct()

	/**
	 * The binding a source's provider value names.
	 *
	 * @param string|null $providerId The source's `configuration.provider`, or null when it has none.
	 *
	 * @return SmsProviderInterface The binding; the refusing one for an unknown value.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-an-unknown-sms-provider-value-is-refused-req-smsp-002
	 */
	public function resolve(?string $providerId): SmsProviderInterface {
		if ($providerId === null || $providerId === '') {
			return $this->logProvider;
		}

		return ($this->providers[$providerId] ?? new UnknownSmsProvider(value: $providerId, known: $this->ids()));
	}//end resolve()

	/**
	 * The provider ids the registry holds, log first.
	 *
	 * @return list<string> The ids.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function ids(): array {
		return array_keys($this->providers);
	}//end ids()

	/**
	 * Every binding, keyed by provider id, log first.
	 *
	 * @return array<string, SmsProviderInterface> The bindings.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-cmcom-messagebird-and-twilio-send-through-the-sms-provider-seam-req-smsp-001
	 */
	public function all(): array {
		return $this->providers;
	}//end all()

	/**
	 * Every binding with its display name and the configuration it needs, log first.
	 *
	 * @return list<array{providerId: string, name: string, configSchema: array<string, mixed>}> The descriptions.
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function describeAll(): array {
		$described = [];
		foreach ($this->providers as $id => $provider) {
			$described[] = [
				'providerId' => $id,
				'name' => $provider->getProviderName(),
				'configSchema' => $provider->getConfigSchema(),
			];
		}

		return $described;
	}//end describeAll()
}//end class
