<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Carries the registry an app registers its node types on.
 */
class RegisterFlowNodesEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param FlowNodeRegistry $registry
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
	) {
		parent::__construct();
	}

	/**
	 * Contribute a node type.
	 *
	 * @param IFlowNode $node
	 * @return void
	 */
	public function registerNode(IFlowNode $node): void {
		$this->registry->register(node: $node);
	}
}
