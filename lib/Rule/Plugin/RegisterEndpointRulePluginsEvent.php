<?php

/**
 * Integriq event a sibling app answers to register endpoint rule plug-ins.
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

use OCP\EventDispatcher\Event;

/**
 * Dispatched once, the first time integriq looks up a rule plug-in. A sibling
 * app registers its plug-ins here without integriq knowing about it:
 *
 *     $context->registerEventListener(RegisterEndpointRulePluginsEvent::class, MyPluginsListener::class);
 *
 * and in the listener `$event->register(new MyPlugin())`.
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
 */
class RegisterEndpointRulePluginsEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param EndpointRulePluginRegistry $registry The registry the plug-ins are added to.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function __construct(
		private readonly EndpointRulePluginRegistry $registry,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Register a plug-in.
	 *
	 * @param EndpointRulePluginInterface $plugin The plug-in.
	 *
	 * @return boolean True when registered, false when its id was already taken.
	 *
	 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-custom-rule-runs-a-registered-plug-in-req-gtp-002
	 */
	public function register(EndpointRulePluginInterface $plugin): bool {
		return $this->registry->register(plugin: $plugin);
	}//end register()
}//end class
