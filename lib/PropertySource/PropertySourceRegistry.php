<?php

/**
 * Keyed registry of the property-source providers this instance ships.
 *
 * @category Registry
 * @package  OCA\Integriq\PropertySource
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

namespace OCA\Integriq\PropertySource;

use OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException;
use Psr\Log\LoggerInterface;

/**
 * First registration wins on an id collision, as the integration registry does.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
 */
class PropertySourceRegistry {
	/**
	 * Providers keyed by their id.
	 *
	 * @var array<string,PropertySourceProviderInterface>
	 */
	private array $providers = [];

	/**
	 * Constructor.
	 *
	 * @param iterable<PropertySourceProviderInterface> $providers Providers discovered through DI.
	 * @param LoggerInterface|null $logger Structured logger, for collisions.
	 */
	public function __construct(iterable $providers = [], private readonly ?LoggerInterface $logger = null) {
		foreach ($providers as $provider) {
			$this->register($provider);
		}
	}//end __construct()

	/**
	 * Register one provider. A second registration under a taken id is ignored.
	 *
	 * @param PropertySourceProviderInterface $provider The provider to register.
	 *
	 * @return bool True when this provider took the id.
	 */
	public function register(PropertySourceProviderInterface $provider): bool {
		$id = $provider->id();
		if (isset($this->providers[$id]) === true) {
			$this->logger?->warning(
				'property-source.collision',
				[
					'id' => $id,
					'kept' => get_class($this->providers[$id]),
					'ignored' => get_class($provider),
				]
			);
			return false;
		}

		$this->providers[$id] = $provider;
		return true;
	}//end register()

	/**
	 * Whether a provider answers to this id.
	 *
	 * @param string $id Provider id.
	 *
	 * @return bool True when a provider is registered under the id.
	 */
	public function has(string $id): bool {
		return isset($this->providers[$id]);
	}//end has()

	/**
	 * The provider registered under this id.
	 *
	 * @param string $id Provider id a schema property named.
	 *
	 * @return PropertySourceProviderInterface The provider.
	 *
	 * @throws UnknownPropertySourceException When nothing answers to the id.
	 */
	public function get(string $id): PropertySourceProviderInterface {
		if (isset($this->providers[$id]) === false) {
			throw new UnknownPropertySourceException($id, $this->ids());
		}

		return $this->providers[$id];
	}//end get()

	/**
	 * Every registered provider id.
	 *
	 * @return array<int,string> Provider ids.
	 */
	public function ids(): array {
		return array_keys($this->providers);
	}//end ids()

	/**
	 * What every registered provider says about itself.
	 *
	 * @return array<int,array<string,mixed>> Provider descriptions.
	 */
	public function describeAll(): array {
		return array_values(array_map(static fn (PropertySourceProviderInterface $p): array => $p->describe(), $this->providers));
	}//end describeAll()
}//end class
