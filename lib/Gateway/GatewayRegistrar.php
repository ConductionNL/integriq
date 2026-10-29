<?php

/**
 * Integriq GatewayRegistrar.
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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the Gateway component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class GatewayRegistrar {
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
