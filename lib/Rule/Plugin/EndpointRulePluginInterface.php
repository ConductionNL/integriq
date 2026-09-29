<?php

/**
 * Integriq endpoint rule plug-in contract.
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * A step a sibling app adds to integriq's endpoint rule pipeline.
 *
 * A rule of type `custom` names a plug-in id in `configuration.plugin`, and
 * integriq runs the plug-in registered under that id. This is the code route
 * for your own logic in the pipeline; a flow is the no-code route. Integriq
 * runs no tenant scripts, so a JavaScript rule is refused instead.
 *
 * Register a plug-in by listening for {@see RegisterEndpointRulePluginsEvent}
 * in your app's `register()` and calling `$event->register($plugin)`.
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
 */
interface EndpointRulePluginInterface {
	/**
	 * The id a `custom` rule names in `configuration.plugin`. Unique across
	 * the instance: a second plug-in with a taken id is ignored.
	 *
	 * @return string The plug-in id.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function pluginId(): string;

	/**
	 * Run the step.
	 *
	 * @param array $rule The rule object (its `configuration` carries the plug-in's own settings).
	 * @param array $data The pipeline data: `body`, `parameters`, `headers`, `path`, `method`.
	 *
	 * @return array|JSONResponse The data the next rule and the consumer receive, or a response that
	 *                            ends the pipeline with that answer.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function process(array $rule, array $data): array|JSONResponse;
}//end interface
