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
 * Binds each Flow contract to the class that implements it, so callers
 * outside the Flow component depend on the contract only.
 *
 * Naming every contract and its class is this class's whole job, so its
 * coupling grows with the number of contracts by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
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
		$context->registerServiceAlias(SynchronizationRunNodeInterface::class, SynchronizationRunNode::class);
		$context->registerServiceAlias(ContractCommitNodeInterface::class, ContractCommitNode::class);
		$context->registerServiceAlias(SourcePaginateNodeInterface::class, SourcePaginateNode::class);
		$context->registerServiceAlias(ContractSweepNodeInterface::class, ContractSweepNode::class);
		$context->registerServiceAlias(ContractMatchNodeInterface::class, ContractMatchNode::class);
		$context->registerServiceAlias(FetchFileNodeInterface::class, FetchFileNode::class);
		$context->registerServiceAlias(ApplyMappingNodeInterface::class, ApplyMappingNode::class);
	}

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
		// Deliberately NOT guarded on the OR event class existing.
		//
		// The guard that used to stand here ran during register(), and at that
		// point OpenRegister's classes are not autoloadable yet — apps are
		// registered in an order that puts `integriq` before
		// `openregister`. So `class_exists()` answered FALSE on a perfectly
		// healthy instance and this returned early: the nodes never registered,
		// `source-call` and `synchronization-run` were absent from the palette,
		// and a flow naming either failed only when it RAN. Verified on a clean
		// install — the guard logged `class_exists at register(): false`, and
		// removing it took the registry from 10 nodes to 12.
		//
		// Dropping it is safe, which is why the guard was never buying anything:
		// `::class` resolves to a string and does not autoload, and
		// addServiceListener() is lazy — FlowNodeListener is only constructed if
		// the event actually fires, which can only happen when OpenRegister is
		// present and dispatching it. On an instance whose OpenRegister predates
		// the flow engine, nothing dispatches and this stays inert, which is
		// exactly the resilience the guard was reaching for.
		$dispatcher->addServiceListener(
			eventName: \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
			className: \OCA\Integriq\Flow\FlowNodeListener::class
		);
	}//end registerListeners()
}
