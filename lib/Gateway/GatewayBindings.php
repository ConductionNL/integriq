<?php

/**
 * Integriq GatewayBindings.
 *
 * Registers the services of the Gateway component.
 *
 * @category Gateway
 * @package  OCA\Integriq\Gateway
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

namespace OCA\Integriq\Gateway;

use OCA\Integriq\Gateway\Adapter\PropertyRestrictionGatewayInterface;
use OCA\Integriq\Gateway\Adapter\PublicationGateway;
use OCA\Integriq\Gateway\Adapter\PublicationGatewayInterface;
use OCA\Integriq\Gateway\Adapter\WkpbGateway;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the Gateway component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 *
 * Naming every class it wires is what a registrar is for, so the coupling
 * between objects is high by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class GatewayBindings {
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
		$context->registerServiceAlias(GatewayDescriptorInterface::class, GatewayDescriptor::class);
		$context->registerServiceAlias(ZgwRegistryBindingInterface::class, ZgwRegistryBinding::class);
		$context->registerServiceAlias(GatewayRegistryInterface::class, GatewayRegistry::class);
		$context->registerServiceAlias(PublicationGatewayInterface::class, PublicationGateway::class);
		$context->registerServiceAlias(PropertyRestrictionGatewayInterface::class, WkpbGateway::class);
		$this->registerGateways(context: $context);
	}//end register()

	/**
	 * Registers the statutory gateway registry and transport.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The catalogue is a static list of entries.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The factory signature takes the container; this one needs nothing from it.
	 */
	private function registerGateways(IRegistrationContext $context): void {
		// The statutory gateway entries. Declared in one place so the catalogue
		// page and the gateway overview can never disagree about which laws this
		// instance reaches.
		$context->registerService(
			GatewayRegistry::class,
			static function ($c): GatewayRegistry {
				return new GatewayRegistry(entries: GatewayCatalogue::entries());
			}
		);
		$context->registerServiceAlias(GatewayTransport::class, SourceGatewayTransport::class);
	}//end registerGateways()
}
