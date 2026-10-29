<?php

/**
 * Integriq RuleRegistrar.
 *
 * Registers the services of the Rule component.
 *
 * @category Rule
 * @package  OCA\Integriq\Rule
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

namespace OCA\Integriq\Rule;

use OCA\Integriq\Rule\Plugin\ConnectRelationsPlugin;
use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistry;
use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistryInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Registers the services the Rule component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class RuleRegistrar {
	/**
	 * Register this component's services on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's services and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(EndpointRulePluginRegistryInterface::class, EndpointRulePluginRegistry::class);
		$this->registerEndpointRulePlugins(context: $context);
	}//end register()

	/**
	 * Registers the endpoint rule plug-in registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerEndpointRulePlugins(IRegistrationContext $context): void {
		// Endpoint rule plug-ins (gateway-endpoint-transform-and-plugins D2):
		// integriq's own connectRelations, plus whatever sibling apps register
		// on RegisterEndpointRulePluginsEvent, dispatched on first lookup.
		$context->registerService(
			EndpointRulePluginRegistry::class,
			static function ($c): EndpointRulePluginRegistry {
				return new EndpointRulePluginRegistry(
					plugins: [$c->get(ConnectRelationsPlugin::class)],
					dispatcher: $c->get(IEventDispatcher::class),
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
	}//end registerEndpointRulePlugins()
}
