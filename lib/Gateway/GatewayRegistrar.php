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
 * Entry point of this component's container wiring for Application.
 *
 * The bindings themselves live in {@see GatewayBindings}, inside the component.
 *
 * @spec exclude container wiring only, it forwards to the component's bindings and has no behaviour of its own
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
		(new GatewayBindings())->register(context: $context);
	}//end register()
}//end class
