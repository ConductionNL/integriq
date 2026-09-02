<?php

/**
 * Integriq Flow Steps-to-Graph Translator.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

		$nodes = [
			['id' => self::TRIGGER_ID, 'type' => 'openregister.trigger-manual', 'config' => []],
		];
		$edges = [];

		$orders = array_map(static fn (array $step): int => (int)$step['order'], $steps);
		$previousId = self::TRIGGER_ID;

		foreach ($steps as $index => $step) {
			$id = (string)((int)$step['order']);
			$nodes[] = $this->nodeFor(step: $step, id: $id);

			// The edge INTO this step from the sequential predecessor. A
			// branch's own outgoing edges are conditioned below; every other
			// step chains to the next order, which is exactly the runner's
			// sequential walk.
			if ($previousId !== null) {
				$edges[] = ['id' => $previousId . '-' . $id, 'from' => $previousId, 'to' => $id];
			}

			$nextId = self::END_ID;
			if (isset($orders[($index + 1)]) === true) {
				$nextId = (string)$orders[($index + 1)];
			}

			if ((string)($step['type'] ?? '') === 'branch') {
				foreach ($this->branchEdges(step: $step, id: $id, nextId: $nextId) as $edge) {
					$edges[] = $edge;
				}

				// The branch's outgoing edges are complete; nothing chains
				// sequentially out of it.
				$previousId = null;
				continue;
			}

			$previousId = $id;
		}//end foreach

		$nodes[] = ['id' => self::END_ID, 'type' => 'openregister.end', 'config' => []];

		if ($previousId !== null) {
			$edges[] = ['id' => $previousId . '-' . self::END_ID, 'from' => $previousId, 'to' => self::END_ID];
		}

		return [
			'nodes' => $nodes,
			'edges' => $edges,
		];

	}//end translate()

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

		$seen = [];
		$orders = [];
		foreach ($steps as $step) {
			$order = (int)($step['order'] ?? 0);
			if (isset($seen[$order]) === true) {
				$reasons[] = sprintf(
					'Duplicate step order %d — FlowRunnerService::run() rejects this flow today, and a graph would silently lose one of the two nodes.',
					$order
				);
			}

			$seen[$order] = true;
			$orders[] = $order;
		}

		foreach ($steps as $step) {
			$order = (int)($step['order'] ?? 0);
			$type = (string)($step['type'] ?? '');

			if (isset(self::TYPE_MAP[$type]) === false) {
				$reasons[] = sprintf('Step %d has unsupported type "%s".', $order, $type);
				continue;
			}

			if (empty($step['condition']) === false) {
				$reasons[] = sprintf(
					'Step %d carries a run-if `condition`; the graph expresses conditions on branch edges, not as step skips, so this flow needs a manual re-model.',
					$order
				);
			}

			$reasons = array_merge($reasons, $this->stepRefusals(step: $step, order: $order, type: $type, orders: $orders));
		}//end foreach

		return $reasons;

	}//end refusalsFor()

	/**
	 * Per-type refusals for one step.
	 *
	 * @param array $step The step definition.
	 * @param int $order The step's order.
	 * @param string $type The step's (supported) type.
	 * @param array<int, int> $orders Every declared order, for branch-target checks.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function stepRefusals(array $step, int $order, string $type, array $orders): array {
		$config = (array)($step['config'] ?? []);

		return array_merge(
			$this->referenceRefusals(step: $step, order: $order, type: $type),
			$this->configRefusals(config: $config, order: $order, type: $type),
			$this->branchRefusals(step: $step, order: $order, type: $type, orders: $orders)
		);

	}//end stepRefusals()

	/**
	 * The refusal for a step whose node requires a `configRef` it lacks.
	 *
	 * @param array $step The step definition.
	 * @param int $order The step's order.
	 * @param string $type The step's (supported) type.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function referenceRefusals(array $step, int $order, string $type): array {
		if (in_array($type, ['call', 'mapping', 'synchronization'], true) === false) {
			return [];
		}

		if (trim((string)($step['configRef'] ?? '')) !== '') {
			return [];
		}

		return [sprintf('Step %d (%s) names no `configRef`; the node it maps to requires the referenced entity.', $order, $type)];

	}//end referenceRefusals()

	/**
	 * The per-type refusals living in a step's `config` block.
	 *
	 * @param array $config The step's config block.
	 * @param int $order The step's order.
	 * @param string $type The step's (supported) type.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function configRefusals(array $config, int $order, string $type): array {
		return match ($type) {
			'call' => $this->callRefusals(config: $config, order: $order),
			'synchronization' => $this->synchronizationRefusals(config: $config, order: $order),
			'event' => $this->eventRefusals(config: $config, order: $order),
			'approval' => $this->approvalRefusals(config: $config, order: $order),
			default => [],
		};

	}//end configRefusals()

	/**
	 * The `call` step features the source-call node cannot express.
	 *
	 * @param array $config The step's config block.
	 * @param int $order The step's order.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function callRefusals(array $config, int $order): array {
		if (empty($config['requestConfig']) === true) {
			return [];
		}

		return [
			sprintf(
				'Step %d (call) carries a raw `requestConfig`; the source-call node expresses requests as '
				. 'endpoint/method/query/body/headers, so this step needs a manual re-model.',
				$order
			),
		];

	}//end callRefusals()

	/**
	 * The `synchronization` step features the synchronization-run node lacks.
	 *
	 * @param array $config The step's config block.
	 * @param int $order The step's order.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function synchronizationRefusals(array $config, int $order): array {
		$reasons = [];

		if (($config['isTest'] ?? false) === true) {
			$reasons[] = sprintf('Step %d (synchronization) runs in `isTest` mode, which the synchronization-run node does not offer.', $order);
		}

		if (trim((string)($config['mutationType'] ?? '')) !== '') {
			$reasons[] = sprintf('Step %d (synchronization) sets a `mutationType`, which the synchronization-run node does not offer.', $order);
		}

		return $reasons;

	}//end synchronizationRefusals()

	/**
	 * The `event` step configuration the event-emit node would reject.
	 *
	 * @param array $config The step's config block.
	 * @param int $order The step's order.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function eventRefusals(array $config, int $order): array {
		if (trim((string)($config['type'] ?? '')) !== '') {
			return [];
		}

		return [sprintf('Step %d (event) names no event `type`.', $order)];

	}//end eventRefusals()

	/**
	 * The `approval` step configuration the approval-request node would reject.
	 *
	 * @param array $config The step's config block.
	 * @param int $order The step's order.
	 *
	 * @return array<int, string> Zero or one sentence.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function approvalRefusals(array $config, int $order): array {
		if (trim((string)($config['approverGroup'] ?? '')) !== '') {
			return [];
		}

		return [
			sprintf(
				'Step %d (approval) names no `approverGroup`; the approval-request node requires an audience, '
				. 'because a request nobody owns never resolves.',
				$order
			),
		];

	}//end approvalRefusals()

	/**
	 * The refusals for branch targets that resolve to no step.
	 *
	 * @param array $step The step definition.
	 * @param int $order The step's order.
	 * @param string $type The step's (supported) type.
	 * @param array<int, int> $orders Every declared order.
	 *
	 * @return array<int, string> One sentence per dangling target.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function branchRefusals(array $step, int $order, string $type, array $orders): array {
		if ($type !== 'branch') {
			return [];
		}

		$reasons = [];
		foreach ($this->branchTargets(step: $step) as $target) {
			if (in_array($target, $orders, true) === false) {
				$reasons[] = sprintf('Step %d (branch) targets step order %d, which does not exist.', $order, $target);
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
			'config' => $this->configFor(step: $step, type: $type),
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
	 * @param array $step The step definition.
	 * @param string $type The step's type.
	 *
	 * @return array<string, mixed> The node config.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function configFor(array $step, string $type): array {
		$config = (array)($step['config'] ?? []);
		$configRef = trim((string)($step['configRef'] ?? ''));

		switch ($type) {
			case 'call':
				return [
					'source' => $configRef,
					'endpoint' => (string)($config['endpoint'] ?? ''),
					'method' => (string)($config['method'] ?? 'GET'),
					'output' => 'response',
				];
			case 'mapping':
				// No `input`/`output`: the node then maps the whole item and
				// replaces it with the result, which is exactly the runner's
				// output-becomes-next-input threading.
				return ['mapping' => $configRef];
			case 'synchronization':
				$node = [
					'synchronization' => $configRef,
					'output' => 'syncResult',
				];
				if (array_key_exists('force', $config) === true) {
					$node['force'] = (bool)$config['force'];
				}

				return $node;
			case 'event':
				$node = [
					'type' => (string)($config['type'] ?? ''),
					'source' => (string)($config['source'] ?? ''),
				];
				if (trim((string)($config['subject'] ?? '')) !== '') {
					$node['subject'] = (string)$config['subject'];
				}

				return $node;
			case 'approval':
				return [
					'question' => $this->approvalQuestion(step: $step, config: $config),
					'approverGroup' => (string)($config['approverGroup'] ?? ''),
					'ttlSeconds' => (int)($config['ttlSeconds'] ?? ApprovalService::DEFAULT_TTL_SECONDS),
					// The runner's `onReject` vocabulary: anything but `skip`
					// (error, dead_letter) ended the run, so it maps to the
					// node failing on rejection.
					'failOnReject' => ((string)($config['onReject'] ?? 'error')) !== 'skip',
				];
			default:
				// The branch case: the switch anchor carries no config — its
				// routing lives on the conditioned edges.
				return [];
		}//end switch

	}//end configFor()

	/**
	 * The question an approval step asks, synthesised when the step has none.
	 *
	 * @param array $step The step definition.
	 * @param array $config The step's config block.
	 *
	 * @return string The question.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function approvalQuestion(array $step, array $config): string {
		$question = trim((string)($config['question'] ?? ''));
		if ($question !== '') {
			return $question;
		}

		return sprintf('Approve step %d of this flow.', (int)($step['order'] ?? 0));

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
	 * @param array $step The branch step definition.
	 * @param string $id The branch node's id.
	 * @param string $nextId The sequential successor's node id (or the end node).
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function branchEdges(array $step, string $id, string $nextId): array {
		$edges = [];

		foreach ((array)($step['branches'] ?? []) as $index => $branch) {
			if (is_array($branch) === false || empty($branch['condition']) === true || isset($branch['nextStepOrder']) === false) {
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

		$defaultTarget = $nextId;
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
