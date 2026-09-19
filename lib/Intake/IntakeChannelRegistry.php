<?php

/**
 * Integriq IntakeChannelRegistry.
 *
 * Holds the channel adapters this instance has, keyed by channel id, with the
 * same first-wins collision policy OpenRegister's integration registry uses:
 * a second adapter claiming a taken id is refused and logged, so a channel
 * never quietly changes behaviour because a later app registered over it.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\Integriq\Exception\IntakeChannelException;
use Psr\Log\LoggerInterface;

/**
 * The channel adapters this instance knows.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001
 */
class IntakeChannelRegistry {

	/**
	 * The adapters, keyed by channel id.
	 *
	 * @var array<string,IntakeChannelAdapterInterface>
	 */
	private array $adapters = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Records a refused collision.
	 * @param array<int,IntakeChannelAdapterInterface> $adapters The adapters registered at boot.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		array $adapters = [],
	) {
		foreach ($adapters as $adapter) {
			$this->register(adapter: $adapter);
		}

	}//end __construct()

	/**
	 * Add one adapter.
	 *
	 * @param IntakeChannelAdapterInterface $adapter The adapter.
	 *
	 * @return bool True when it was taken, false when its id was already claimed.
	 */
	public function register(IntakeChannelAdapterInterface $adapter): bool {
		$channelId = $adapter->getChannelId();
		if (isset($this->adapters[$channelId]) === true) {
			$this->logger->warning(
				'Integriq intake: a second adapter claimed an existing channel id and was refused.',
				[
					'channel' => $channelId,
					'kept' => $this->adapters[$channelId]::class,
					'refused' => $adapter::class,
				]
			);
			return false;
		}

		$this->adapters[$channelId] = $adapter;
		return true;

	}//end register()

	/**
	 * Whether a channel id has an adapter.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return bool True when it has.
	 */
	public function has(string $channelId): bool {
		return isset($this->adapters[$channelId]);

	}//end has()

	/**
	 * The adapter for one channel id.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return IntakeChannelAdapterInterface The adapter.
	 *
	 * @throws IntakeChannelException When nothing answers to that id.
	 */
	public function get(string $channelId): IntakeChannelAdapterInterface {
		if (isset($this->adapters[$channelId]) === false) {
			throw new IntakeChannelException(
				'No intake channel adapter answers to "' . $channelId . '".'
			);
		}

		return $this->adapters[$channelId];

	}//end get()

	/**
	 * Every channel id, sorted.
	 *
	 * @return array<int,string> The channel ids.
	 */
	public function getChannelIds(): array {
		$ids = array_keys($this->adapters);
		sort($ids);
		return $ids;

	}//end getChannelIds()

	/**
	 * What every channel can do.
	 *
	 * @return array<int,array<string,mixed>> The descriptions, in channel id order.
	 */
	public function describeAll(): array {
		$described = [];
		foreach ($this->getChannelIds() as $channelId) {
			$described[] = $this->adapters[$channelId]->describe()->toArray();
		}

		return $described;

	}//end describeAll()

}//end class
