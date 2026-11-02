<?php

/**
 * Integriq FlowRegistrar.
 *
 * Registers the container bindings the Flow component offers to the
 * rest of the app.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
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

namespace OCA\Integriq\Flow;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Entry point of this component's container wiring for Application.
 *
 * The bindings themselves live in {@see FlowBindings}, inside the component.
 *
 * @spec exclude container wiring only, it forwards to the component's bindings and has no behaviour of its own
 */
final class FlowRegistrar {
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
		(new FlowBindings())->register(context: $context);
	}//end register()

	/**
	 * Register the flow nodes this app contributes to OpenRegister's flow engine.
	 *
	 * Feature-detected on the OR flow engine being present: without it this is a
	 * no-op, so Integriq still boots on an instance whose OpenRegister
	 * predates the flow engine.
	 *
	 * @param IEventDispatcher $dispatcher The NC event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function registerListeners(IEventDispatcher $dispatcher): void {
		(new FlowBindings())->registerListeners(dispatcher: $dispatcher);
	}//end registerListeners()
}//end class
