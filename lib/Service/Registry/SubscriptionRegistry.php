<?php

/**
 * Keyed registry of the subscription bindings this instance ships.
 *
 * @category Registry
 * @package  OCA\Integriq\Service\Registry
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

namespace OCA\Integriq\Service\Registry;

/**
 * A registry id maps to exactly one binding, and an unknown id is an absence
 * the caller has to handle rather than a silent nothing.
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
class SubscriptionRegistry {
	/**
	 * Bindings keyed by registry id.
	 *
	 * @var array<string,SubscriptionProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param iterable<SubscriptionProviderInterface> $providers The bindings.
	 */
	public function __construct(iterable $providers = []) {
		foreach ($providers as $provider) {
			if (isset($this->providers[$provider->registryId()]) === true) {
				continue;
			}

			$this->providers[$provider->registryId()] = $provider;
		}
	}//end __construct()

	/**
	 * The binding for a registry id, or null when nothing answers to it.
	 *
	 * @param string $registryId Registry id.
	 *
	 * @return SubscriptionProviderInterface|null The binding.
	 */
	public function get(string $registryId): ?SubscriptionProviderInterface {
		return ($this->providers[$registryId] ?? null);
	}//end get()

	/**
	 * Every registered binding.
	 *
	 * @return array<string,SubscriptionProviderInterface> Bindings keyed by registry id.
	 */
	public function all(): array {
		return $this->providers;
	}//end all()
}//end class
