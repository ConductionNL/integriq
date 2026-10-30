<?php

/**
 * Integriq PropertySourceRegistrar.
 *
 * Registers the services of the PropertySource component.
 *
 * @category PropertySource
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

use OCA\Integriq\PropertySource\Provider\BagPropertySource;
use OCA\Integriq\PropertySource\Provider\BrpPropertySource;
use OCA\Integriq\PropertySource\Provider\KvkPropertySource;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the PropertySource component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class PropertySourceRegistrar {
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
		$context->registerServiceAlias(ListResyncServiceInterface::class, ListResyncService::class);
		$context->registerServiceAlias(PropertySourceResolverInterface::class, PropertySourceResolver::class);
		$context->registerServiceAlias(PropertySourceRegistryInterface::class, PropertySourceRegistry::class);
		$this->registerPropertySourceRegistry(context: $context);
	}//end register()

	/**
	 * Registers the property-source registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerPropertySourceRegistry(IRegistrationContext $context): void {
		// The property-source registry: one keyed list of the registry
		// bindings a schema property can name through
		// `x-openregister-property-source`. Registered explicitly rather than
		// autowired so the order, and therefore the first-wins collision
		// policy, is readable in one place.
		$context->registerService(
			PropertySourceRegistry::class,
			static function ($c): PropertySourceRegistry {
				return new PropertySourceRegistry(
					providers: [
						$c->get(BagPropertySource::class),
						$c->get(BrpPropertySource::class),
						$c->get(KvkPropertySource::class),
					],
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
	}//end registerPropertySourceRegistry()
}
