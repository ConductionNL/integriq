<?php

/**
 * Integriq ObservabilityRegistrar.
 *
 * Registers the container bindings the Observability component offers to the
 * rest of the app.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Entry point of this component's container wiring for Application.
 *
 * The bindings themselves live in {@see ObservabilityBindings}, inside the component.
 *
 * @spec exclude container wiring only, it forwards to the component's bindings and has no behaviour of its own
 */
final class ObservabilityRegistrar {
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
		(new ObservabilityBindings())->register(context: $context);
	}//end register()

	/**
	 * Register the listeners on the event dispatcher.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's listeners and has no behaviour of its own
	 */
	public function registerListeners(IEventDispatcher $dispatcher): void {
		(new ObservabilityBindings())->registerListeners(dispatcher: $dispatcher);
	}//end registerListeners()
}//end class
