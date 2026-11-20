<?php

/**
 * The digital post bindings this instance ships.
 *
 * @category Registry
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use OCA\Integriq\Service\Helper\ProviderIndex;
use RuntimeException;

/**
 * Resolved by `providerId` from the source configuration. A provider id
 * nothing answers to fails naming itself and the ids that do exist, because
 * a misspelled provider that silently fell back to the log binding would
 * report letters delivered that were never sent.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
class DigitalPostProviderRegistry implements DigitalPostProviderRegistryInterface {
	/**
	 * Bindings keyed by provider id.
	 *
	 * @var ProviderIndex<DigitalPostProviderInterface>
	 */
	private readonly ProviderIndex $index;

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
	 * @param iterable<DigitalPostProviderInterface> $providers  The bindings.
	 * @param ConnectorExtensionCollector|null       $extensions Asks the extension apps for theirs, on first use.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function __construct(
		iterable $providers = [],
		private readonly ?ConnectorExtensionCollector $extensions = null,
	) {
		$this->index = new ProviderIndex(kind: 'digital post', providers: $providers);
		foreach ($this->index->ids() as $id) {
			$this->appIds[$id] = 'integriq';
		}
	}//end __construct()

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
		foreach ($this->extensions->collect()->getDigitalPostProviders() as $contribution) {
			$provider = $contribution['provider'];
			$id       = $provider->getProviderId();
			if ($this->index->add(provider: $provider) === false) {
				$this->extensions->refused(
					seam: ConnectorExtensionCollector::SEAM_DIGITAL_POST,
					id: $id,
					appId: $contribution['appId'],
					item: $provider,
					keptApp: $this->appIds[$id]
				);
				continue;
			}

			$this->appIds[$id] = $contribution['appId'];
			$this->extensions->accepted(
				seam: ConnectorExtensionCollector::SEAM_DIGITAL_POST,
				id: $id,
				appId: $contribution['appId'],
				item: $provider
			);
		}//end foreach
	}//end extend()

	/**
	 * Whether a binding answers to this provider id.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return bool True when one is registered.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function has(string $providerId): bool {
		$this->extend();
		return $this->index->has(providerId: $providerId);
	}//end has()

	/**
	 * The binding for a provider id.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return DigitalPostProviderInterface The binding.
	 *
	 * @throws RuntimeException When nothing answers to the id.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function get(string $providerId): DigitalPostProviderInterface {
		$this->extend();
		return $this->index->get(providerId: $providerId);
	}//end get()

	/**
	 * Every registered provider id.
	 *
	 * @return array<int,string> Provider ids.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function ids(): array {
		$this->extend();
		return $this->index->ids();
	}//end ids()

	/**
	 * Every binding with the configuration it needs.
	 *
	 * @return array<int,array<string,mixed>> The bindings.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function describeAll(): array {
		$this->extend();
		$described = [];
		foreach ($this->index->ids() as $id) {
			$described[] = ['providerId' => $id, 'configSchema' => $this->index->get(providerId: $id)->getConfigSchema()];
		}

		return $described;
	}//end describeAll()
}//end class
