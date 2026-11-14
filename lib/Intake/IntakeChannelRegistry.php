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
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use Psr\Log\LoggerInterface;

/**
 * The channel adapters this instance knows.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-channel-is-a-declared-adapter-behind-one-contract-req-ic-001
 */
class IntakeChannelRegistry implements IntakeChannelRegistryInterface {

	/**
	 * The adapters, keyed by channel id.
	 *
	 * @var array<string,IntakeChannelAdapterInterface>
	 */
	private array $adapters = [];

	/**
	 * The app that contributed each channel, keyed by channel id.
	 *
	 * @var array<string,string>
	 */
	private array $appIds = [];

	/**
	 * Whether the extension apps have been asked yet.
	 *
	 * @var boolean
	 */
	private bool $extended = false;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Records a refused collision.
	 * @param array<int,IntakeChannelAdapterInterface> $adapters The adapters registered at boot.
	 * @param ConnectorExtensionCollector|null $extensions Asks the extension apps for theirs, on first use.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		array $adapters = [],
		private readonly ?ConnectorExtensionCollector $extensions = null,
	) {
		foreach ($adapters as $adapter) {
			$this->register(adapter: $adapter);
		}

	}//end __construct()

	/**
	 * The app that contributed a channel: `integriq` for a built-in.
	 *
	 * @param string $channelId The channel id.
	 *
	 * @return string|null The app id, or null when no adapter answers to it.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function appIdOf(string $channelId): ?string {
		$this->extend();
		return ($this->appIds[$channelId] ?? null);

	}//end appIdOf()

	/**
	 * Register what the extension apps contribute, once, after the built-ins.
	 *
	 * A contribution claiming a channel id that is already taken is refused,
	 * logged and recorded; the built-in keeps it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-adds-and-never-replaces-a-built-in-req-cxk-002
	 */
	private function extend(): void {
		if ($this->extended === true || $this->extensions === null) {
			return;
		}

		$this->extended = true;
		foreach ($this->extensions->collect()->getIntakeChannels() as $contribution) {
			$adapter   = $contribution['adapter'];
			$channelId = $adapter->getChannelId();
			if (isset($this->adapters[$channelId]) === true) {
				$this->extensions->refused(
					seam: ConnectorExtensionCollector::SEAM_INTAKE_CHANNEL,
					id: $channelId,
					appId: $contribution['appId'],
					item: $adapter,
					keptApp: $this->appIds[$channelId]
				);
				continue;
			}

			$this->adapters[$channelId] = $adapter;
			$this->appIds[$channelId]   = $contribution['appId'];
			$this->extensions->accepted(
				seam: ConnectorExtensionCollector::SEAM_INTAKE_CHANNEL,
				id: $channelId,
				appId: $contribution['appId'],
				item: $adapter
			);
		}//end foreach

	}//end extend()

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
		$this->appIds[$channelId]   = 'integriq';
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
		$this->extend();
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
		$this->extend();
		if (isset($this->adapters[$channelId]) === false) {
			throw new IntakeChannelException(
				message: 'No intake channel adapter answers to "' . $channelId . '".'
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
		$this->extend();
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
		$this->extend();
		$described = [];
		foreach ($this->getChannelIds() as $channelId) {
			$described[] = $this->adapters[$channelId]->describe()->toArray();
		}

		return $described;

	}//end describeAll()

}//end class
