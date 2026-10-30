<?php

/**
 * Contract of the PropertySourceRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\PropertySource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\PropertySource;

use OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException;

/**
 * Public operations of {@see PropertySourceRegistry} that other components call.
 */
interface PropertySourceRegistryInterface {
	/**
	 * Register one provider. A second registration under a taken id is ignored.
	 *
	 * @param PropertySourceProviderInterface $provider The provider to register.
	 *
	 * @return bool True when this provider took the id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceRegistry::register()
	 */
	public function register(PropertySourceProviderInterface $provider): bool;

	/**
	 * Whether a provider answers to this id.
	 *
	 * @param string $id Provider id.
	 *
	 * @return bool True when a provider is registered under the id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceRegistry::has()
	 */
	public function has(string $id): bool;

	/**
	 * The provider registered under this id.
	 *
	 * @param string $id Provider id a schema property named.
	 *
	 * @return PropertySourceProviderInterface The provider.
	 *
	 * @throws UnknownPropertySourceException When nothing answers to the id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceRegistry::get()
	 */
	public function get(string $id): PropertySourceProviderInterface;

	/**
	 * Every registered provider id.
	 *
	 * @return array<int,string> Provider ids.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceRegistry::ids()
	 */
	public function ids(): array;

	/**
	 * What every registered provider says about itself.
	 *
	 * @return array<int,array<string,mixed>> Provider descriptions.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on PropertySourceRegistry::describeAll()
	 */
	public function describeAll(): array;
}
