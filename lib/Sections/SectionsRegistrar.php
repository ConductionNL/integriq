<?php

/**
 * Integriq SectionsRegistrar.
 *
 * Registers the admin settings section.
 *
 * @category Sections
 * @package  OCA\Integriq\Sections
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

namespace OCA\Integriq\Sections;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Entry point of this component's container wiring for Application.
 *
 * The bindings themselves live in {@see SectionsBindings}, inside the component.
 *
 * @spec exclude container wiring only, it forwards to the component's bindings and has no behaviour of its own
 */
final class SectionsRegistrar {
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
		(new SectionsBindings())->register(context: $context);
	}//end register()
}//end class
