<?php

/**
 * Contract of the IntakeChannelRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Intake
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

namespace OCA\Integriq\Intake;

use OCA\Integriq\Exception\IntakeChannelException;

/**
 * Public operations of {@see IntakeChannelRegistry} that other components call.
 */
interface IntakeChannelRegistryInterface {
	/**
	 * Add one adapter.
	 *
	 * @param IntakeChannelAdapterInterface $adapter The adapter.
	 *
	 * @return bool True when it was taken, false when its id was already claimed.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeChannelRegistry::register()
	 */
	public function register(IntakeChannelAdapterInterface $adapter): bool;

	/**
	 * Whether a channel id has an adapter.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return bool True when it has.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeChannelRegistry::has()
	 */
	public function has(string $channelId): bool;

	/**
	 * The adapter for one channel id.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return IntakeChannelAdapterInterface The adapter.
	 *
	 * @throws IntakeChannelException When nothing answers to that id.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeChannelRegistry::get()
	 */
	public function get(string $channelId): IntakeChannelAdapterInterface;

	/**
	 * Every channel id, sorted.
	 *
	 * @return array<int,string> The channel ids.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeChannelRegistry::getChannelIds()
	 */
	public function getChannelIds(): array;

	/**
	 * What every channel can do.
	 *
	 * @return array<int,array<string,mixed>> The descriptions, in channel id order.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on IntakeChannelRegistry::describeAll()
	 */
	public function describeAll(): array;
}
