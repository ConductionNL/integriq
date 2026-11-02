<?php

/**
 * Contract of the BridgeRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Bridge
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

namespace OCA\Integriq\Bridge;

/**
 * Public operations of {@see BridgeRegistry} that other components call.
 */
interface BridgeRegistryInterface {
	/**
	 * Every registered bridge, keyed by id.
	 *
	 * @return array<string,array<string,mixed>> The bridges.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on BridgeRegistry::all()
	 */
	public function all(): array;

	/**
	 * Register a bridge and issue it a token.
	 *
	 * @param string $bridgeId The bridge id.
	 * @param string $label What it is called.
	 *
	 * @return array<string,mixed> The bridge, with its token. The token is
	 *                             returned once, here, and stored hashed.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on BridgeRegistry::register()
	 */
	public function register(string $bridgeId, string $label = ''): array;

	/**
	 * Revoke a bridge.
	 *
	 * @param string $bridgeId The bridge id.
	 *
	 * @return bool True when a bridge was revoked.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on BridgeRegistry::revoke()
	 */
	public function revoke(string $bridgeId): bool;

	/**
	 * Whether a bridge may carry traffic right now.
	 *
	 * @param string $bridgeId The bridge id.
	 *
	 * @return bool True when it is registered and not revoked.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on BridgeRegistry::isActive()
	 */
	public function isActive(string $bridgeId): bool;

	/**
	 * Whether a token belongs to an active bridge.
	 *
	 * @param string $bridgeId The bridge id.
	 * @param string $token The token the bridge presented.
	 *
	 * @return bool True when the bridge authenticates.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on BridgeRegistry::authenticates()
	 */
	public function authenticates(string $bridgeId, string $token): bool;
}
