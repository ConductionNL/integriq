<?php

/**
 * Integriq AdaptersRegistrar.
 *
 * Registers the container bindings the Adapters component offers to the
 * rest of the app.
 *
 * @category Adapters
 * @package  OCA\Integriq\Adapters
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

namespace OCA\Integriq\Adapters;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Entry point of this component's container wiring for Application.
 *
 * The bindings themselves live in {@see AdaptersBindings}, inside the component.
 *
 * @spec exclude container wiring only, it forwards to the component's bindings and has no behaviour of its own
 */
final class AdaptersRegistrar {
	/**
	 * Register the contract aliases on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it binds each contract to its class and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		(new AdaptersBindings())->register(context: $context);
	}//end register()
}//end class
