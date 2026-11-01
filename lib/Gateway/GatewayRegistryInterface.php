<?php

/**
 * Contract of the GatewayRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Gateway
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

namespace OCA\Integriq\Gateway;

use InvalidArgumentException;

/**
 * Public operations of {@see GatewayRegistry} that other components call.
 */
interface GatewayRegistryInterface {
	/**
	 * Register one entry.
	 *
	 * @param array<string,mixed>|GatewayDescriptor $entry The entry.
	 *
	 * @return GatewayDescriptor The registered descriptor.
	 *
	 * @throws InvalidArgumentException When the entry cannot be registered.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayRegistry::register()
	 */
	public function register(array|GatewayDescriptor $entry): GatewayDescriptor;

	/**
	 * One gateway by id.
	 *
	 * @param string $id Gateway id.
	 *
	 * @return GatewayDescriptor|null The gateway, or null when nothing answers to the id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayRegistry::get()
	 */
	public function get(string $id): ?GatewayDescriptor;

	/**
	 * Every registered gateway, optionally narrowed to one standard.
	 *
	 * @param string|null $standard The standard to filter by.
	 *
	 * @return array<int,GatewayDescriptor> The gateways.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayRegistry::all()
	 */
	public function all(?string $standard = null): array;

	/**
	 * Every standard this instance has a gateway for, as the catalogue facet.
	 *
	 * @return array<int,string> Standards, in the order they were registered.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayRegistry::standards()
	 */
	public function standards(): array;

	/**
	 * The gateway overview: every gateway with its jurisdiction.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayRegistry::overview()
	 */
	public function overview(): array;

	/**
	 * The overview as a comma-separated export.
	 *
	 * @return string The export.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on GatewayRegistry::exportOverview()
	 */
	public function exportOverview(): string;
}
