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

use RuntimeException;

/**
 * Resolved by `providerId` from the source configuration. A provider id
 * nothing answers to fails naming itself and the ids that do exist, because
 * a misspelled provider that silently fell back to the log binding would
 * report letters delivered that were never sent.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-one-provider-seam-with-log-berichtenbox-and-postex-bindings-req-dpa-001
 */
class DigitalPostProviderRegistry {
	/**
	 * Bindings keyed by provider id.
	 *
	 * @var array<string,DigitalPostProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param iterable<DigitalPostProviderInterface> $providers The bindings.
	 */
	public function __construct(iterable $providers = []) {
		foreach ($providers as $provider) {
			if (isset($this->providers[$provider->getProviderId()]) === true) {
				continue;
			}

			$this->providers[$provider->getProviderId()] = $provider;
		}
	}//end __construct()

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
		return isset($this->providers[$providerId]);
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
		if (isset($this->providers[$providerId]) === false) {
			$knownIds = '(none)';
			if ($this->ids() !== []) {
				$knownIds = implode(', ', $this->ids());
			}

			throw new RuntimeException(
				sprintf(
					'No digital post provider is registered under "%s". Registered providers: %s. Nothing was sent.',
					$providerId,
					$knownIds
				)
			);
		}

		return $this->providers[$providerId];
	}//end get()

	/**
	 * Every registered provider id.
	 *
	 * @return array<int,string> Provider ids.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function ids(): array {
		return array_keys($this->providers);
	}//end ids()

	/**
	 * Every binding with the configuration it needs.
	 *
	 * @return array<int,array<string,mixed>> The bindings.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function describeAll(): array {
		$described = [];
		foreach ($this->providers as $id => $provider) {
			$described[] = ['providerId' => $id, 'configSchema' => $provider->getConfigSchema()];
		}

		return $described;
	}//end describeAll()
}//end class
