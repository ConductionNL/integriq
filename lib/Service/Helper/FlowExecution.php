<?php

/**
 * Integriq Flow Execution.
 *
 * The state one pass of the flow step loop works on: the flow's steps sorted
 * by `order`, the position of each `order` in that list, the FlowToken that
 * threads step output into the next step, and the flow_run being executed.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Steps, FlowToken and flow_run of one step-loop pass.
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
 */
final class FlowExecution {

	/**
	 * Step list position, keyed by step `order`.
	 *
	 * @var array<int, int>
	 */
	private readonly array $indexByOrder;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $steps     Steps sorted ascending by `order`.
	 * @param FlowToken                        $flowToken The in-flight FlowToken.
	 * @param ObjectEntity                     $flowRun   The flow_run being executed or resumed.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 */
	public function __construct(
		public readonly array $steps,
		public readonly FlowToken $flowToken,
		public readonly ObjectEntity $flowRun,
	) {
		$indexByOrder = [];
		foreach ($steps as $i => $step) {
			$indexByOrder[(int)$step['order']] = $i;
		}

		$this->indexByOrder = $indexByOrder;
	}//end __construct()

	/**
	 * The list position of the step with the given `order`.
	 *
	 * @param integer $order A step `order`.
	 *
	 * @return integer|null The position, or null when no step has that order.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
	 */
	public function indexOf(int $order): ?int {
		return ($this->indexByOrder[$order] ?? null);
	}//end indexOf()

	/**
	 * The list position the step loop starts at: the first step for a fresh
	 * run or a flow without steps, else the step whose `order` is `$startOrder`.
	 *
	 * @param integer|null $startOrder Resume point (a step `order`), or null to start at the first step.
	 *
	 * @return integer|null The position, or null when `$startOrder` does not resolve to a step.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	public function startIndex(?int $startOrder): ?int {
		if ($startOrder === null || count($this->steps) === 0) {
			return 0;
		}

		return $this->indexOf(order: $startOrder);
	}//end startIndex()
}//end class
