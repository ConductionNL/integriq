<?php

/**
 * Contract of the ListResyncService.
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

/**
 * Public operations of {@see ListResyncService} that other components call.
 */
interface ListResyncServiceInterface {
	/**
	 * The list currently served for a provider.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return array<int,array<string,mixed>> The stored list.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on ListResyncService::currentList()
	 */
	public function currentList(string $providerId): array;

	/**
	 * The last resync report for a provider.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return array<string,mixed> Report with `lastResyncAt`, `changed`, `succeeded` and `message`.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on ListResyncService::lastReport()
	 */
	public function lastReport(string $providerId): array;

	/**
	 * Resync one list-shaped provider now.
	 *
	 * @param string $providerId Provider id.
	 * @param array<string,mixed> $config Declared provider configuration.
	 *
	 * @return array<string,mixed> The resync report that was stored.
	 *
	 * @throws \OCA\Integriq\PropertySource\Exception\UnknownPropertySourceException When nothing answers to the id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on ListResyncService::resync()
	 */
	public function resync(string $providerId, array $config = []): array;
}
