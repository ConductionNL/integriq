<?php

/**
 * The payment provider bindings this instance has.
 *
 * @category Registry
 * @package  OCA\Integriq\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;

/**
 * Payment providers keyed by the id a payment source names in
 * `configuration.provider`. Integriq's own bindings come first and keep their
 * ids; an extension app's provider is added on first use, and one that claims
 * a taken id is refused and logged.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
 */
class PaymentProviderRegistry {

	/**
	 * Bindings keyed by provider id.
	 *
	 * @var array<string,PaymentProviderInterface>
	 */
	private array $providers = [];

	/**
	 * The app that contributed each binding, keyed by provider id.
	 *
	 * @var array<string,string>
	 */
	private array $appIds = [];

	/**
	 * Whether the extension apps have been asked yet.
	 *
	 * @var boolean
	 */
	private bool $extended = false;

	/**
	 * Constructor.
	 *
	 * @param iterable<PaymentProviderInterface> $providers  Integriq's own bindings; the first wins a duplicate id.
	 * @param ConnectorExtensionCollector|null   $extensions Asks the extension apps for theirs, on first use.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function __construct(
		iterable $providers = [],
		private readonly ?ConnectorExtensionCollector $extensions = null,
	) {
		foreach ($providers as $provider) {
			$id = $provider->getProviderId();
			if (isset($this->providers[$id]) === false) {
				$this->providers[$id] = $provider;
				$this->appIds[$id]    = 'integriq';
			}
		}
	}//end __construct()

	/**
	 * Whether a binding answers to this provider id.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return bool True when one is registered.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function has(string $providerId): bool {
		$this->extend();
		return isset($this->providers[$providerId]);
	}//end has()

	/**
	 * The binding for a provider id, or null when nothing answers to it.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return PaymentProviderInterface|null The binding.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function find(string $providerId): ?PaymentProviderInterface {
		$this->extend();
		return ($this->providers[$providerId] ?? null);
	}//end find()

	/**
	 * Every registered provider id.
	 *
	 * @return array<int,string> The ids, built-ins first.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function ids(): array {
		$this->extend();
		return array_keys($this->providers);
	}//end ids()

	/**
	 * The app that contributed a binding: `integriq` for a built-in.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return string|null The app id, or null when nothing answers to it.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function appIdOf(string $providerId): ?string {
		$this->extend();
		return ($this->appIds[$providerId] ?? null);
	}//end appIdOf()

	/**
	 * Register what the extension apps contribute, once, after the built-ins.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-adds-and-never-replaces-a-built-in-req-cxk-002
	 */
	private function extend(): void {
		if ($this->extended === true || $this->extensions === null) {
			return;
		}

		$this->extended = true;
		foreach ($this->extensions->collect()->getPaymentProviders() as $contribution) {
			$provider = $contribution['provider'];
			$id       = $provider->getProviderId();
			if (isset($this->providers[$id]) === true) {
				$this->extensions->refused(
					seam: ConnectorExtensionCollector::SEAM_PAYMENT,
					id: $id,
					appId: $contribution['appId'],
					item: $provider,
					keptApp: $this->appIds[$id]
				);
				continue;
			}

			$this->providers[$id] = $provider;
			$this->appIds[$id]    = $contribution['appId'];
			$this->extensions->accepted(
				seam: ConnectorExtensionCollector::SEAM_PAYMENT,
				id: $id,
				appId: $contribution['appId'],
				item: $provider
			);
		}//end foreach
	}//end extend()
}//end class
