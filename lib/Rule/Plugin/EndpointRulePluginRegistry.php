<?php

/**
 * Integriq registry of endpoint rule plug-ins.
 *
 * @category Rule
 * @package  OCA\Integriq\Rule\Plugin
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Rule\Plugin;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Holds the rule plug-ins by id. Integriq's own plug-ins arrive through the
 * constructor; sibling apps add theirs through
 * {@see RegisterEndpointRulePluginsEvent}, dispatched once on first lookup so
 * every app has registered its listeners by then.
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
 */
class EndpointRulePluginRegistry {
	/**
	 * Registered plug-ins keyed by id.
	 *
	 * @var array<string, EndpointRulePluginInterface>
	 */
	private array $plugins = [];

	/**
	 * Whether sibling apps have been asked for their plug-ins yet.
	 *
	 * @var boolean
	 */
	private bool $collected = false;

	/**
	 * Constructor.
	 *
	 * @param iterable<EndpointRulePluginInterface> $plugins    Integriq's own plug-ins.
	 * @param IEventDispatcher|null                 $dispatcher Asks sibling apps for theirs; null registers none.
	 * @param LoggerInterface|null                  $logger     Notes an id collision.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function __construct(
		iterable $plugins = [],
		private readonly ?IEventDispatcher $dispatcher = null,
		private readonly ?LoggerInterface $logger = null,
	) {
		foreach ($plugins as $plugin) {
			$this->register(plugin: $plugin);
		}
	}//end __construct()

	/**
	 * Register a plug-in. The first plug-in to claim an id keeps it.
	 *
	 * @param EndpointRulePluginInterface $plugin The plug-in.
	 *
	 * @return boolean True when registered, false when the id was empty or taken.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function register(EndpointRulePluginInterface $plugin): bool {
		$pluginId = trim($plugin->id());
		if ($pluginId === '') {
			$this->logger?->warning('endpoint-rule-plugin.no-id', ['class' => get_class($plugin)]);
			return false;
		}

		if (isset($this->plugins[$pluginId]) === true) {
			$this->logger?->warning(
				'endpoint-rule-plugin.collision',
				[
					'id' => $pluginId,
					'kept' => get_class($this->plugins[$pluginId]),
					'ignored' => get_class($plugin),
				]
			);
			return false;
		}

		$this->plugins[$pluginId] = $plugin;
		return true;
	}//end register()

	/**
	 * The plug-in registered under an id, or null when none is.
	 *
	 * @param string $pluginId The id a custom rule names.
	 *
	 * @return EndpointRulePluginInterface|null The plug-in.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function pluginFor(string $pluginId): ?EndpointRulePluginInterface {
		$this->collect();
		return ($this->plugins[trim($pluginId)] ?? null);
	}//end pluginFor()

	/**
	 * The ids of every registered plug-in, sorted.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function ids(): array {
		$this->collect();
		$ids = array_keys($this->plugins);
		sort($ids);
		return $ids;
	}//end ids()

	/**
	 * Ask sibling apps for their plug-ins, once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	private function collect(): void {
		if ($this->collected === true) {
			return;
		}

		$this->collected = true;
		$this->dispatcher?->dispatchTyped(new RegisterEndpointRulePluginsEvent(registry: $this));
	}//end collect()
}//end class
