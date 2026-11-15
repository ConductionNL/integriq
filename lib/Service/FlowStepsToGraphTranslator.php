<?php

/**
 * Integriq Flow Steps-to-Graph Translator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Translates a legacy Integriq `flow` object's ordered `steps[]` pipeline
 * into the `nodes`/`edges` graph OpenRegister's flow engine executes
 * (retire-integriq-flow-schema Task 2). Pure: nothing is read from or written
 * to the register here — the migration repair step and the occ command decide
 * what to do with the document.
 *
 * THE ID RULE, WHICH IS THE WHOLE MIGRATION
 * -----------------------------------------
 * A step's `order` is a stable identifier that `branch` steps reference BY
 * VALUE (`nextStepOrder`/`defaultNextStepOrder`). It therefore becomes the
 * node id verbatim — node "40" is step order 40 — so every branch target
 * stays valid without renumbering, and a human comparing the two shapes can
 * line them up by eye.
 *
 * REFUSALS, NOT APPROXIMATIONS
 * ----------------------------
 * A flow using a feature the graph translation cannot express faithfully is
 * REFUSED with one sentence per feature (the `EntityNotMigratableException`
 * shape `JobToFlowGenerator` established), never migrated approximately: an
 * approximate flow runs, does something subtly different, and nobody is told.
 * Duplicate `order` values are refused exactly as `FlowRunnerService::run()`
 * refuses them today, rather than emitting a graph with one of the two nodes
 * silently missing.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/retire-integriq-flow-schema/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCP\IL10N;

/**
 * Pure `steps[]` -> `nodes`/`edges` translation for the flow migration.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is a total
 * function over the closed six-type step vocabulary: every type contributes
 * its own mapping arm and its own refusal checks, and that enumeration IS
 * the migration. Splitting it across classes would scatter one closed
 * decision table without removing a single decision.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 */
class FlowStepsToGraphTranslator {

	/**
	 * Legacy step type -> contributed/built-in node type.
	 *
	 * Every entry is a node that exists: the two `integriq-flow-nodes` nodes,
	 * the two nodes `retire-integriq-flow-schema` contributes, and the
	 * engine's own switch anchor for `branch`.
	 *
	 * @var array<string, string>
	 */
	private const TYPE_MAP = [
		'call' => 'openconnector.source-call',
		'mapping' => 'openconnector.apply-mapping',
		'synchronization' => 'openconnector.synchronization-run',
		'event' => 'openconnector.event-emit',
		'approval' => 'openconnector.approval-request',
		'branch' => 'openregister.switch',
	];

	/**
	 * The graph's entry node id.
	 *
	 * @var string
	 */
	private const TRIGGER_ID = 'trigger';

	/**
	 * The graph's terminal node id.
	 *
	 * @var string
	 */
	private const END_ID = 'end';

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations for the refusal summary.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Translate one flow document's `steps[]` into `nodes` and `edges`.
	 *
	 * @param array $flow The flow's serialised record (needs `steps`, uses `name` in messages).
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} The graph.
	 *
	 * @throws EntityNotMigratableException When the flow uses a feature the graph cannot express.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function translate(array $flow): array {
		$steps = $this->sortedSteps(steps: (array)($flow['steps'] ?? []));

		$reasons = $this->refusalsFor(steps: $steps);
		if ($reasons !== []) {
			throw new EntityNotMigratableException(
				subject: 'flow',
				message: $this->l10n->t(
					'The flow "%1$s" cannot be migrated to a graph yet: %2$s unsupported feature(s).',
					[(string)($flow['name'] ?? ($flow['uuid'] ?? 'unnamed')), (string)count($reasons)]
				),
				reasons: $reasons
			);
		}

		return $this->graphFor(steps: $steps);

	}//end translate()

	/**
	 * The graph of steps that are known to be migratable, in order.
	 *
	 * @param array $steps The steps, sorted by `order`.
	 *
	 * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} The graph.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function graphFor(array $steps): array {
		$graph = new FlowGraph();
		$graph->nodes[] = ['id' => self::TRIGGER_ID, 'type' => 'openregister.trigger-manual', 'config' => []];
		$graph->previousId = self::TRIGGER_ID;

		$orders = array_map(static fn (array $step): int => (int)$step['order'], $steps);
		foreach ($steps as $index => $step) {
			$graph->nextId = $this->nextIdAfter(orders: $orders, index: $index);
			$this->addStep(graph: $graph, step: $step);
		}

		$graph->nodes[] = ['id' => self::END_ID, 'type' => 'openregister.end', 'config' => []];

		if ($graph->previousId !== null) {
			$graph->edges[] = ['id' => $graph->previousId . '-' . self::END_ID, 'from' => $graph->previousId, 'to' => self::END_ID];
		}

		return [
			'nodes' => $graph->nodes,
			'edges' => $graph->edges,
		];

	}//end graphFor()

	/**
	 * The node a step falls through to: the next step's, or the end node.
	 *
	 * @param array<int, int> $orders Every step's order, sorted.
	 * @param int             $index  The step's position.
	 *
	 * @return string The node id.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function nextIdAfter(array $orders, int $index): string {
		if (isset($orders[($index + 1)]) === true) {
			return (string)$orders[($index + 1)];
		}

		return self::END_ID;

	}//end nextIdAfter()

	/**
	 * Add one step's node and the edges into and out of it.
	 *
	 * @param FlowGraph $graph The graph so far.
	 * @param array     $step  The step definition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function addStep(FlowGraph $graph, array $step): void {
		$id = (string)((int)$step['order']);
		$graph->nodes[] = $this->nodeFor(step: $step, id: $id);

		// The edge INTO this step from the sequential predecessor. A
		// branch's own outgoing edges are conditioned below; every other
		// step chains to the next order, which is exactly the runner's
		// sequential walk.
		if ($graph->previousId !== null) {
			$graph->edges[] = ['id' => $graph->previousId . '-' . $id, 'from' => $graph->previousId, 'to' => $id];
		}

		$graph->previousId = $id;
		if ((string)($step['type'] ?? '') === 'branch') {
			array_push($graph->edges, ...$this->branchEdges(step: $step, graph: $graph));

			// The branch's outgoing edges are complete; nothing chains
			// sequentially out of it.
			$graph->previousId = null;
		}

	}//end addStep()

	/**
	 * Every feature of this flow the graph translation cannot express.
	 *
	 * An empty list means the flow is migratable. A non-empty one is the
	 * refusal — migrating anyway would swap declared behaviour for silence
	 * or approximation.
	 *
	 * @param array $steps The steps, sorted by `order`.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function refusalsFor(array $steps): array {
		$reasons = [];

		if ($steps === []) {
			$reasons[] = 'The flow has no steps; there is nothing to migrate.';
		}

		$reasons = array_merge($reasons, $this->duplicateOrderRefusals(steps: $steps));

		$orders = array_map(static fn (array $step): int => (int)($step['order'] ?? 0), $steps);
		foreach ($steps as $step) {
			$reasons = array_merge($reasons, $this->stepFeatureRefusals(step: new FlowStep(step: $step), orders: $orders));
		}

		return $reasons;

	}//end refusalsFor()

	/**
	 * The refusals for step orders that occur more than once.
	 *
	 * @param array $steps The steps, sorted by `order`.
	 *
	 * @return array<int, string> One sentence per repeated order.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function duplicateOrderRefusals(array $steps): array {
		$reasons = [];
		$seen = [];
		foreach ($steps as $step) {
			$order = (int)($step['order'] ?? 0);
			if (isset($seen[$order]) === true) {
				$reasons[] = sprintf(
					'Duplicate step order %d — FlowRunnerService::run() rejects this flow today, and a graph would silently lose one of the two nodes.',
					$order
				);
			}

			$seen[$order] = true;
		}

		return $reasons;

	}//end duplicateOrderRefusals()

	/**
	 * Every feature of one step the graph cannot express: its type, a run-if condition, and the per-type refusals.
	 *
	 * @param FlowStep        $step   The step.
	 * @param array<int, int> $orders Every declared order, for branch-target checks.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function stepFeatureRefusals(FlowStep $step, array $orders): array {
		if (isset(self::TYPE_MAP[$step->type()]) === false) {
			return [sprintf('Step %d has unsupported type "%s".', $step->order(), $step->type())];
		}

		$reasons = [];
		if (empty($step->step['condition']) === false) {
			$reasons[] = sprintf(
				'Step %d carries a run-if `condition`; the graph expresses conditions on branch edges, not as step skips, so this flow needs a manual re-model.',
				$step->order()
			);
		}

		return array_merge($reasons, $this->stepRefusals(step: $step, orders: $orders));

	}//end stepFeatureRefusals()

	/**
	 * Per-type refusals for one step.
	 *
	 * @param FlowStep        $step   The step.
	 * @param array<int, int> $orders Every declared order, for branch-target checks.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function stepRefusals(FlowStep $step, array $orders): array {
		return array_merge(
			$this->referenceRefusals(step: $step),
			$this->configRefusals(step: $step),
			$this->branchRefusals(step: $step, orders: $orders)
		);

	}//end stepRefusals()

	/**
	 * The refusal for a step whose node requires a `configRef` it lacks.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function referenceRefusals(FlowStep $step): array {
		if (in_array($step->type(), ['call', 'mapping', 'synchronization'], true) === false) {
			return [];
		}

		if ($step->configRef() !== '') {
			return [];
		}

		return [sprintf('Step %d (%s) names no `configRef`; the node it maps to requires the referenced entity.', $step->order(), $step->type())];

	}//end referenceRefusals()

	/**
	 * The per-type refusals living in a step's `config` block.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function configRefusals(FlowStep $step): array {
		return match ($step->type()) {
			'call' => $this->callRefusals(step: $step),
			'synchronization' => $this->synchronizationRefusals(step: $step),
			'event' => $this->eventRefusals(step: $step),
			'approval' => $this->approvalRefusals(step: $step),
			default => [],
		};

	}//end configRefusals()

	/**
	 * The `call` step features the source-call node cannot express.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function callRefusals(FlowStep $step): array {
		if (empty($step->config()['requestConfig']) === true) {
			return [];
		}

		return [
			sprintf(
				'Step %d (call) carries a raw `requestConfig`; the source-call node expresses requests as '
				. 'endpoint/method/query/body/headers, so this step needs a manual re-model.',
				$step->order()
			),
		];

	}//end callRefusals()

	/**
	 * The `synchronization` step features the synchronization-run node lacks.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function synchronizationRefusals(FlowStep $step): array {
		$config = $step->config();
		$reasons = [];

		if (($config['isTest'] ?? false) === true) {
			$reasons[] = sprintf('Step %d (synchronization) runs in `isTest` mode, which the synchronization-run node does not offer.', $step->order());
		}

		if (trim((string)($config['mutationType'] ?? '')) !== '') {
			$reasons[] = sprintf('Step %d (synchronization) sets a `mutationType`, which the synchronization-run node does not offer.', $step->order());
		}

		return $reasons;

	}//end synchronizationRefusals()

	/**
	 * The `event` step configuration the event-emit node would reject.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function eventRefusals(FlowStep $step): array {
		if (trim((string)($step->config()['type'] ?? '')) !== '') {
			return [];
		}

		return [sprintf('Step %d (event) names no event `type`.', $step->order())];

	}//end eventRefusals()

	/**
	 * The `approval` step configuration the approval-request node would reject.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function approvalRefusals(FlowStep $step): array {
		if (trim((string)($step->config()['approverGroup'] ?? '')) !== '') {
			return [];
		}

		return [
			sprintf(
				'Step %d (approval) names no `approverGroup`; the approval-request node requires an audience, '
				. 'because a request nobody owns never resolves.',
				$step->order()
			),
		];

	}//end approvalRefusals()

	/**
	 * The refusals for branch targets that resolve to no step.
	 *
	 * @param FlowStep        $step   The step.
	 * @param array<int, int> $orders Every declared order.
	 *
	 * @return array<int, string> One sentence per dangling target.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function branchRefusals(FlowStep $step, array $orders): array {
		if ($step->type() !== 'branch') {
			return [];
		}

		$reasons = [];
		foreach ($this->branchTargets(step: $step->step) as $target) {
			if (in_array($target, $orders, true) === false) {
				$reasons[] = sprintf('Step %d (branch) targets step order %d, which does not exist.', $step->order(), $target);
			}
		}

		return $reasons;

	}//end branchRefusals()

	/**
	 * The graph node standing in for one step.
	 *
	 * @param array $step The step definition.
	 * @param string $id The node id (the step's order, verbatim).
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function nodeFor(array $step, string $id): array {
		$type = (string)$step['type'];
		$node = [
			'id' => $id,
			'type' => self::TYPE_MAP[$type],
			'config' => $this->configFor(step: new FlowStep(step: $step)),
		];

		// The engine reads the policy from the STEP definition
		// (`$step['onError']`), same key, same vocabulary — carry it over
		// verbatim so stop/continue/dead_letter behave as authored.
		if (trim((string)($step['onError'] ?? '')) !== '') {
			$node['onError'] = (string)$step['onError'];
		}

		return $node;

	}//end nodeFor()

	/**
	 * The node config standing in for one step's `configRef`/`config`.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<string, mixed> The node config.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function configFor(FlowStep $step): array {
		return match ($step->type()) {
			'call' => $this->callConfig(step: $step),
			// No `input`/`output`: the node then maps the whole item and
			// replaces it with the result, which is exactly the runner's
			// output-becomes-next-input threading.
			'mapping' => ['mapping' => $step->configRef()],
			'synchronization' => $this->synchronizationConfig(step: $step),
			'event' => $this->eventConfig(step: $step),
			'approval' => $this->approvalConfig(step: $step),
			// The branch case: the switch anchor carries no config — its
			// routing lives on the conditioned edges.
			default => [],
		};

	}//end configFor()

	/**
	 * The source-call node config of a `call` step.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<string, mixed> The node config.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function callConfig(FlowStep $step): array {
		$config = $step->config();

		return [
			'source' => $step->configRef(),
			'endpoint' => (string)($config['endpoint'] ?? ''),
			'method' => (string)($config['method'] ?? 'GET'),
			'output' => 'response',
		];

	}//end callConfig()

	/**
	 * The synchronization-run node config of a `synchronization` step.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<string, mixed> The node config.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function synchronizationConfig(FlowStep $step): array {
		$config = $step->config();
		$node = [
			'synchronization' => $step->configRef(),
			'output' => 'syncResult',
		];
		if (array_key_exists('force', $config) === true) {
			$node['force'] = (bool)$config['force'];
		}

		return $node;

	}//end synchronizationConfig()

	/**
	 * The event-emit node config of an `event` step.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<string, mixed> The node config.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function eventConfig(FlowStep $step): array {
		$config = $step->config();
		$node = [
			'type' => (string)($config['type'] ?? ''),
			'source' => (string)($config['source'] ?? ''),
		];
		if (trim((string)($config['subject'] ?? '')) !== '') {
			$node['subject'] = (string)$config['subject'];
		}

		return $node;

	}//end eventConfig()

	/**
	 * The approval-request node config of an `approval` step.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return array<string, mixed> The node config.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function approvalConfig(FlowStep $step): array {
		$config = $step->config();

		return [
			'question' => $this->approvalQuestion(step: $step),
			'approverGroup' => (string)($config['approverGroup'] ?? ''),
			'ttlSeconds' => (int)($config['ttlSeconds'] ?? ApprovalService::DEFAULT_TTL_SECONDS),
			// The runner's `onReject` vocabulary: anything but `skip`
			// (error, dead_letter) ended the run, so it maps to the
			// node failing on rejection.
			'failOnReject' => ((string)($config['onReject'] ?? 'error')) !== 'skip',
		];

	}//end approvalConfig()

	/**
	 * The question an approval step asks, synthesised when the step has none.
	 *
	 * @param FlowStep $step The step.
	 *
	 * @return string The question.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function approvalQuestion(FlowStep $step): string {
		$question = trim((string)($step->config()['question'] ?? ''));
		if ($question !== '') {
			return $question;
		}

		return sprintf('Approve step %d of this flow.', $step->order());

	}//end approvalQuestion()

	/**
	 * The conditioned edges leaving a branch step.
	 *
	 * Each `branches[]` entry becomes an edge carrying its JsonLogic
	 * condition verbatim — the engine evaluates edge conditions with the
	 * same JsonLogic the runner used. The default edge (no condition) goes
	 * to `defaultNextStepOrder` when declared and otherwise to the next
	 * sequential step, which is the runner's own fallthrough.
	 *
	 * @param array     $step  The branch step definition.
	 * @param FlowGraph $graph The graph so far, with the step's sequential successor.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function branchEdges(array $step, FlowGraph $graph): array {
		$id = (string)((int)$step['order']);
		$edges = [];

		foreach ((array)($step['branches'] ?? []) as $index => $branch) {
			if ($this->isConditionedBranch(branch: $branch) === false) {
				continue;
			}

			$target = (string)((int)$branch['nextStepOrder']);
			$edges[] = [
				'id' => sprintf('%s-%s-%d', $id, $target, (int)$index),
				'from' => $id,
				'to' => $target,
				'condition' => $branch['condition'],
			];
		}

		$defaultTarget = $graph->nextId;
		if (isset($step['defaultNextStepOrder']) === true) {
			$defaultTarget = (string)((int)$step['defaultNextStepOrder']);
		}

		$edges[] = [
			'id' => sprintf('%s-%s-default', $id, $defaultTarget),
			'from' => $id,
			'to' => $defaultTarget,
		];

		return $edges;

	}//end branchEdges()

	/**
	 * Whether a `branches[]` entry is a condition with a target.
	 *
	 * @param mixed $branch The entry.
	 *
	 * @return boolean True when it becomes a conditioned edge.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function isConditionedBranch(mixed $branch): bool {
		return is_array($branch) === true && empty($branch['condition']) === false && isset($branch['nextStepOrder']) === true;

	}//end isConditionedBranch()

	/**
	 * The branch targets a branch step declares, for existence checks.
	 *
	 * @param array $step The branch step definition.
	 *
	 * @return array<int, int> Every referenced step order.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function branchTargets(array $step): array {
		$targets = [];

		foreach ((array)($step['branches'] ?? []) as $branch) {
			if (is_array($branch) === true && isset($branch['nextStepOrder']) === true) {
				$targets[] = (int)$branch['nextStepOrder'];
			}
		}

		if (isset($step['defaultNextStepOrder']) === true) {
			$targets[] = (int)$step['defaultNextStepOrder'];
		}

		return $targets;

	}//end branchTargets()

	/**
	 * Sort steps by `order` ascending — the runner's own execution sequence.
	 *
	 * @param array $steps Raw `steps[]` from the flow record.
	 *
	 * @return array<int, array> Steps sorted ascending by `order`.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function sortedSteps(array $steps): array {
		$steps = array_values(array_filter($steps, static fn ($step): bool => is_array($step)));
		usort(
			$steps,
			static fn (array $a, array $b): int => (((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0)))
		);

		return $steps;

	}//end sortedSteps()
}//end class
