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

/**
 * Binds each Flow contract to the class that implements it, so callers
 * outside the Flow component depend on the contract only.
 *
 * Naming every contract and its class is this class's whole job, so its
 * coupling grows with the number of contracts by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
		$context->registerServiceAlias(ApplyMappingNodeInterface::class, ApplyMappingNode::class);
	}
}
