<?php

/**
 * Contract of the EndpointRulePluginRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Rule\Plugin
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

namespace OCA\Integriq\Rule\Plugin;

/**
 * Public operations of {@see EndpointRulePluginRegistry} that other components call.
 */
interface EndpointRulePluginRegistryInterface {
	/**
	 * Register a plug-in. The first plug-in to claim an id keeps it.
	 *
	 * @param EndpointRulePluginInterface $plugin The plug-in.
	 *
	 * @return boolean True when registered, false when the id was empty or taken.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function register(EndpointRulePluginInterface $plugin): bool;

	/**
	 * The plug-in registered under an id, or null when none is.
	 *
	 * @param string $pluginId The id a custom rule names.
	 *
	 * @return EndpointRulePluginInterface|null The plug-in.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function pluginFor(string $pluginId): ?EndpointRulePluginInterface;

	/**
	 * The ids of every registered plug-in, sorted.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function ids(): array;
}
