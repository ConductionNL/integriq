<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\FlowNodeRegistry.
 *
 * Mirrors the deployed registry's collision behaviour: a duplicate node type
 * id is REFUSED (the first registration wins and the second is rejected),
 * never silently displaced by load order. Only the surface the integriq
 * listener test exercises is declared.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Minimal stub for the flow node registry.
 */
class FlowNodeRegistry {
	/**
	 * Registered nodes, keyed by type id.
	 *
	 * @var array<string, IFlowNode>
	 */
	private array $nodes = [];

	/**
	 * Node registrations that were refused as duplicates.
	 *
	 * @var array<int, string>
	 */
	public array $refused = [];

	/**
	 * Register a node type, refusing a duplicate id.
	 *
	 * @param IFlowNode $node
	 * @return void
	 */
	public function register(IFlowNode $node): void {
		$id = $node->getId();
		if ($id === '') {
			return;
		}

		if (isset($this->nodes[$id]) === true) {
			$this->refused[] = $id;
			return;
		}

		$this->nodes[$id] = $node;
	}

	/**
	 * Every registered node type.
	 *
	 * @return array<string, IFlowNode>
	 */
	public function all(): array {
		return $this->nodes;
	}
}
