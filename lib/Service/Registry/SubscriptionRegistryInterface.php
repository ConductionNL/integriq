<?php

/**
 * Contract of the SubscriptionRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Registry
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

namespace OCA\Integriq\Service\Registry;

/**
 * Public operations of {@see SubscriptionRegistry} that other components call.
 */
interface SubscriptionRegistryInterface {
	/**
	 * The binding for a registry id, or null when nothing answers to it.
	 *
	 * @param string $registryId Registry id.
	 *
	 * @return SubscriptionProviderInterface|null The binding.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SubscriptionRegistry::get()
	 */
	public function get(string $registryId): ?SubscriptionProviderInterface;

	/**
	 * Every registered binding.
	 *
	 * @return array<string,SubscriptionProviderInterface> Bindings keyed by registry id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on SubscriptionRegistry::all()
	 */
	public function all(): array;
}
