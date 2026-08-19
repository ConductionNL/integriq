<?php

/**
 * OpenConnector Rule → Flow migration generator.
 *
 * Task 3.3 of `flow-native-synchronization` ("Rules → trigger-object + switch"):
 * renders an existing Rule entity into a GENERATED FLOW DOCUMENT started by
 * `openregister.trigger-object`, with the rule's JsonLogic conditions expressed
 * on an `openregister.switch`.
 *
 * It writes a document and nothing else. It creates no flow, detaches no rule
 * from its endpoint, and never enables anything: `enabled` is `false` on every
 * document it produces.
 *
 * A RULE HAS NO SCOPE OF ITS OWN
 * ------------------------------
 * `openregister.trigger-object` needs an event, a register and a schema, and it
 * refuses a partial trigger — with good reason: a trigger with no register
 * either matches nothing or matches every object event in the instance, and
 * both are silent. A Rule record carries none of the three. It inherits them
 * from the ENDPOINT it hangs off (`endpoint.rules` lists rule slugs, and the
 * endpoint carries `targetType`/`targetId`/`method`), so this generator is given
 * the pair and refuses a rule whose endpoint does not actually list it.
 *
 * WHAT IT EMITS
 * -------------
 *   openregister.trigger-object   (event derived from the endpoint's method)
 *     → openregister.switch       (only when the rule has conditions)
 *         --match--> the action step --> openregister.end
 *         --else---------------------> openregister.end
 *
 * THE ITEM SHAPE AT EACH STEP, AND WHY THE SWITCH COMES FIRST
 * ----------------------------------------------------------
 * An object-triggered run is queued with the changed object as its subject, and
 * `FlowItems::fromSubject()` makes that exactly ONE item whose `json` is the
 * object. That single-item cardinality is what makes the switch correct here:
 * `FlowTokenRouter::takenExits()` evaluates an exit condition against
 * `$items[0]` ONLY and then routes the WHOLE token down that exit. With one
 * item that is a per-object decision, which is what the rule's condition was.
 * Put the same switch AFTER `openconnector.synchronization-run` — which emits
 * one item per synchronised object — and the condition would be read off the
 * first synchronised object and applied to all of them. So the switch sits
 * immediately after the trigger, before anything fans out, and the action step
 * is the last thing in the branch.
 *
 * THE CONDITION DIALECT
 * ---------------------
 * Both sides evaluate JsonLogic through the same `jwadhams/json-logic-php`, and
 * OpenRegister's `FlowExpression` REGISTERS EXTRA OPERATORS on top, so the
 * operator vocabulary is a superset and no operator needs translating. What
 * differs is the DATA the expression is applied to. A rule is evaluated against
 * the endpoint's request envelope (`body`, `parameters`, `headers`, `path`,
 * `logicResult`, …); a flow condition is evaluated against
 * `{json, binary, itemIndex, itemCount, context, subject}` where `json` is the
 * object. So `body.x` becomes `json.x` and NOTHING ELSE translates — a
 * condition on a header or a query parameter has no object-event equivalent at
 * all, and is refused by name rather than quietly dropped (a dropped condition
 * makes a rule fire on every object instead of some).
 *
 * Sub-scope operators (`map`, `filter`, `reduce`, `all`, `some`, `none`) REBIND
 * what `var` means inside them, so a blind `body.` → `json.` rewrite would be
 * wrong in exactly the places it looked right. They are refused by name.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Exception\EntityNotMigratableException;
use OCA\OpenConnector\Flow\SynchronizationRunNode;
use OCP\IL10N;

/**
 * Renders a Rule into a disabled, reviewable trigger-object flow document.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#3-migration--deprecation
 */
class RuleToFlowGenerator {

	/**
	 * The item key the synchronization result is written under.
	 *
	 * @var string
	 */
	public const KEY_SYNC_RESULT = 'syncResult';

	/**
	 * The exit taken when the rule's conditions hold.
	 *
	 * @var string
	 */
	public const EXIT_MATCH = 'match';

	/**
	 * The exit taken when they do not — the else a branching node must declare.
	 *
	 * @var string
	 */
	public const EXIT_OTHERWISE = 'otherwise';

	/**
	 * The only rule timing an object trigger can stand in for.
	 *
	 * @var string
	 */
	private const SUPPORTED_TIMING = 'after';

	/**
	 * The rule type that runs a synchronization.
	 *
	 * @var string
	 */
	private const TYPE_SYNCHRONIZATION = 'synchronization';

	/**
	 * The rule type that runs a flow.
	 *
	 * @var string
	 */
	private const TYPE_FLOW = 'flow';

	/**
	 * The `configuration.synchronization` sub-keys the run step cannot express.
	 *
	 * Every one of them changes what the legacy rule did around the run —
	 * which object it pulled, how long it waited, what it kept — and
	 * `openconnector.synchronization-run` reads none of them.
	 *
	 * @var array<int, string>
	 */
	private const UNSUPPORTED_SYNC_KEYS = ['objectIdPath', 'preDelay', 'postDelay', 'retainResponse'];

	/**
	 * Constructor.
	 *
	 * @param MigrationEntityReader $reader Reads the Rule and Endpoint entities out of OpenRegister.
	 * @param RuleConditionTranslator $conditions Rewrites the rule's JsonLogic onto the flow item.
	 * @param RuleEndpointScope $scope Derives the object trigger from the endpoint that runs the rule.
	 * @param MigrationSubject $subject Reads each entity's identity for names and traceability.
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(
		private readonly MigrationEntityReader $reader,
		private readonly RuleConditionTranslator $conditions,
		private readonly RuleEndpointScope $scope,
		private readonly MigrationSubject $subject,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Generate the flow document for a rule on one of its endpoints.
	 *
	 * @param string $ruleReference The rule's uuid, slug or reference.
	 * @param string $endpointReference The endpoint's uuid, slug or reference.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When either entity cannot be read, or the
	 *                                      rule uses a feature the flow vocabulary
	 *                                      cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFor(string $ruleReference, string $endpointReference): array {
		$hint = $this->l10n->t(
			'A rule carries no register or schema of its own; it inherits them from the endpoint it is '
			. 'attached to, so both have to be named.'
		);

		$rule = $this->reader->read(reference: $ruleReference, schema: 'rule', subject: 'rule', hint: $hint);
		$endpoint = $this->reader->read(
			reference: $endpointReference,
			schema: 'endpoint',
			subject: 'rule',
			hint: $hint
		);

		return $this->generateFrom(rule: $rule, endpoint: $endpoint);

	}//end generateFor()

	/**
	 * Generate the flow document for an already-read rule and endpoint.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param array $endpoint The serialised record of the endpoint that runs it.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When the rule uses a feature the flow
	 *                                      vocabulary cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFrom(array $rule, array $endpoint): array {
		$reasons = $this->refusalsFor(rule: $rule, endpoint: $endpoint);
		if ($reasons !== []) {
			throw new EntityNotMigratableException(
				subject: 'rule',
				message: $this->l10n->t(
					'The rule "%1$s" cannot be migrated to a flow yet: %2$s unsupported feature(s).',
					[$this->subject->labelOf(entity: $rule), (string)count($reasons)]
				),
				reasons: $reasons
			);
		}

		$event = (string)$this->scope->eventOf(endpoint: $endpoint);
		[$register, $schema] = $this->scope->targetPairOf(endpoint: $endpoint);
		$nodes = $this->nodesFor(rule: $rule, event: $event, register: $register, schema: $schema);

		return [
			'name' => $this->l10n->t('%1$s (generated from rule)', [$this->subject->labelOf(entity: $rule)]),
			'description' => $this->describe(rule: $rule, endpoint: $endpoint, event: $event),
			'enabled' => false,
			'trigger' => $event,
			'triggerRegister' => $register,
			'triggerSchema' => $schema,
			'nodes' => $nodes,
			'edges' => $this->edgesFor(nodes: $nodes),
		];

	}//end generateFrom()

	/**
	 * Every feature of this rule the flow vocabulary cannot express.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param array $endpoint The serialised record of the endpoint that runs it.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $rule, array $endpoint): array {
		return array_merge(
			$this->scopeRefusals(rule: $rule, endpoint: $endpoint),
			$this->timingRefusals(rule: $rule),
			$this->conditionRefusals(rule: $rule),
			$this->actionRefusals(rule: $rule)
		);

	}//end refusalsFor()

	/**
	 * Refusals about deriving an object trigger from the endpoint.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param array $endpoint The serialised record of the endpoint that runs it.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function scopeRefusals(array $rule, array $endpoint): array {
		$reasons = [];

		if ($this->subject->referenceOf(entity: $rule) === '') {
			$reasons[] = $this->l10n->t(
				'The rule carries no uuid, slug or reference, so the generated flow could not record what it '
				. 'was generated from.'
			);
		}

		if ($this->subject->referenceOf(entity: $endpoint) === '') {
			$reasons[] = $this->l10n->t(
				'The endpoint carries no uuid, slug or reference, so the rule\'s scope could not be recorded.'
			);
		}

		if ($this->scope->runsRule(rule: $rule, endpoint: $endpoint) === false) {
			$reasons[] = $this->l10n->t(
				'The endpoint "%1$s" does not list this rule, so it never runs there and the trigger would '
				. 'be derived from a scope the rule has nothing to do with.',
				[$this->subject->labelOf(entity: $endpoint)]
			);
		}

		$targetType = $this->scope->targetTypeOf(endpoint: $endpoint);
		if ($targetType !== RuleEndpointScope::SUPPORTED_TARGET_TYPE) {
			$reasons[] = $this->l10n->t(
				'The endpoint\'s targetType is "%1$s": an object trigger needs a register and a schema, and '
				. 'only a "register/schema" endpoint has them.',
				[$targetType]
			);
		}

		[$register] = $this->scope->targetPairOf(endpoint: $endpoint);
		if ($targetType === RuleEndpointScope::SUPPORTED_TARGET_TYPE && $register === '') {
			$reasons[] = $this->l10n->t(
				'The endpoint\'s targetId "%1$s" is not a "register/schema" pair, so the trigger has no '
				. 'register and schema to name.',
				[trim((string)($endpoint['targetId'] ?? ''))]
			);
		}

		if ($this->scope->eventOf(endpoint: $endpoint) === null) {
			$reasons[] = $this->l10n->t(
				'The endpoint\'s method is "%1$s": it changes no object, so no object event ever fires and '
				. 'the generated flow would never start. Object triggers exist for %2$s.',
				[
					$this->scope->methodOf(endpoint: $endpoint),
					implode(', ', $this->scope->writeMethods()),
				]
			);
		}

		return $reasons;

	}//end scopeRefusals()

	/**
	 * Refusals about WHEN in the pipeline the rule runs.
	 *
	 * @param array $rule The rule's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function timingRefusals(array $rule): array {
		$timing = trim((string)($rule['timing'] ?? 'before'));
		if ($timing === self::SUPPORTED_TIMING) {
			return [];
		}

		return [
			$this->l10n->t(
				'timing "%1$s": an object trigger fires AFTER the change is committed, so a rule that runs '
				. 'before the write — and can still change or refuse it — has nothing to fire on.',
				[$timing]
			),
		];

	}//end timingRefusals()

	/**
	 * Refusals about conditions the switch cannot evaluate.
	 *
	 * @param array $rule The rule's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function conditionRefusals(array $rule): array {
		$conditions = ($rule['conditions'] ?? null);
		if ($conditions === null || $conditions === [] || $conditions === '') {
			return [];
		}

		if (is_array($conditions) === false) {
			return [
				$this->l10n->t(
					'conditions: the rule stores a %1$s where a JsonLogic expression tree is expected, so '
					. 'there is nothing the switch could evaluate.',
					[gettype($conditions)]
				),
			];
		}

		$found = $this->conditions->problemsIn(conditions: $conditions);

		$reasons = [];
		if ($found['scopes'] !== []) {
			$reasons[] = $this->l10n->t(
				'conditions use the sub-scope operator(s) %1$s, which rebind what "var" means inside them. '
				. 'Rewriting request paths to item paths through one of those would be wrong exactly where '
				. 'it looked right, so it is refused rather than guessed.',
				[implode(', ', $found['scopes'])]
			);
		}

		if ($found['paths'] !== []) {
			$reasons[] = $this->l10n->t(
				'conditions read %1$s: an object event carries the object and nothing else — no request, no '
				. 'headers, no query — so only "body" paths have an equivalent. Dropping the rest would make '
				. 'this rule fire on every object instead of some.',
				[implode(', ', $found['paths'])]
			);
		}

		return $reasons;

	}//end conditionRefusals()

	/**
	 * Refusals about WHAT the rule does.
	 *
	 * @param array $rule The rule's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function actionRefusals(array $rule): array {
		$type = trim((string)($rule['type'] ?? ''));
		$configuration = (array)($rule['configuration'] ?? []);

		if ($type === self::TYPE_FLOW) {
			return $this->flowRuleRefusals(configuration: $configuration);
		}

		if ($type === self::TYPE_SYNCHRONIZATION) {
			return $this->synchronizationRuleRefusals(configuration: $configuration);
		}

		return [
			$this->l10n->t(
				'type "%1$s": this rule kind acts on the request/response envelope, and an object event has '
				. 'no envelope to act on — no step reproduces it. The kinds with an object-event equivalent '
				. 'are "%2$s" and "%3$s".',
				[$type, self::TYPE_SYNCHRONIZATION, self::TYPE_FLOW]
			),
		];

	}//end actionRefusals()

	/**
	 * Refusals specific to a `flow` rule.
	 *
	 * @param array $configuration The rule's configuration block.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function flowRuleRefusals(array $configuration): array {
		if (trim((string)($configuration['flow'] ?? '')) !== '') {
			return [];
		}

		return [
			$this->l10n->t(
				'configuration.flow is not set: "openregister.sub-flow" needs a flow to run.'
			),
		];

	}//end flowRuleRefusals()

	/**
	 * Refusals specific to a `synchronization` rule.
	 *
	 * @param array $configuration The rule's configuration block.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function synchronizationRuleRefusals(array $configuration): array {
		$reasons = [];

		if ($this->synchronizationOf(configuration: $configuration) === '') {
			$reasons[] = $this->l10n->t(
				'configuration.synchronization is not set: "openconnector.synchronization-run" must name a '
				. 'configured synchronization, and this step never creates one.'
			);
		}

		$block = ($configuration['synchronization'] ?? null);
		if (is_array($block) === true) {
			$unread = array_intersect(self::UNSUPPORTED_SYNC_KEYS, array_keys($block));
			if ($unread !== []) {
				$reasons[] = $this->l10n->t(
					'configuration.synchronization.%1$s: the run step reads none of these — it takes a '
					. 'synchronization and a force flag and nothing else — so the pre/post handling around '
					. 'the run would silently disappear.',
					[implode(', ', array_map('strval', $unread))]
				);
			}
		}

		if (filter_var(($configuration['isTest'] ?? false), FILTER_VALIDATE_BOOLEAN) === true) {
			$reasons[] = $this->l10n->t(
				'configuration.isTest: the run step has no test mode, so a rule that deliberately performed '
				. 'no writes would become one that does.'
			);
		}

		if ((array)($configuration['synchronizationConfig'] ?? []) !== []) {
			$reasons[] = $this->l10n->t(
				'configuration.synchronizationConfig: merging or overwriting the request body with the run '
				. 'result happens inside the endpoint pipeline and has no step.'
			);
		}

		return $reasons;

	}//end synchronizationRuleRefusals()

	/**
	 * Build the flow's nodes, in pipeline order.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param string $event The object event the trigger waits for.
	 * @param string $register The register the trigger is scoped to.
	 * @param string $schema The schema the trigger is scoped to.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function nodesFor(array $rule, string $event, string $register, string $schema): array {
		$nodes = [
			[
				'id' => 'trigger',
				'type' => 'openregister.trigger-object',
				'config' => [
					'event' => $event,
					'register' => $register,
					'schema' => $schema,
				],
			],
		];

		$gate = $this->gateFor(rule: $rule);
		if ($gate !== null) {
			$nodes[] = $gate;
		}

		$nodes[] = $this->actionNodeFor(rule: $rule);
		$nodes[] = ['id' => 'end', 'type' => 'openregister.end', 'config' => []];

		return $nodes;

	}//end nodesFor()

	/**
	 * The switch carrying the rule's conditions, or null when it has none.
	 *
	 * A rule with no conditions always fired; a switch whose only exit is the
	 * else is a pass-through that says the opposite of what it looks like, so
	 * it is left out rather than drawn.
	 *
	 * @param array $rule The rule's serialised record.
	 *
	 * @return array<string, mixed>|null The switch node.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function gateFor(array $rule): ?array {
		$conditions = ($rule['conditions'] ?? null);
		if (is_array($conditions) === false || $conditions === []) {
			return null;
		}

		return [
			'id' => 'gate',
			'type' => 'openregister.switch',
			// The switch declares no config keys at all; its branching lives on
			// the node's `exits` and the edges' `fromExit`, which is where the
			// engine's router reads them.
			'config' => [],
			'exits' => [
				[
					'id' => self::EXIT_MATCH,
					'condition' => $this->conditions->translate(logic: $conditions),
				],
				// The else is not optional: FlowDefinitionBuilder refuses a node
				// that conditions all its exits, because a token with nowhere to
				// go stops the run without reporting a failure.
				['id' => self::EXIT_OTHERWISE],
			],
		];

	}//end gateFor()

	/**
	 * The one step that stands in for the rule's action.
	 *
	 * @param array $rule The rule's serialised record.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function actionNodeFor(array $rule): array {
		$configuration = (array)($rule['configuration'] ?? []);

		if (trim((string)($rule['type'] ?? '')) === self::TYPE_FLOW) {
			return [
				'id' => 'act',
				'type' => 'openregister.sub-flow',
				'config' => [
					'flowId' => trim((string)$configuration['flow']),
					// The rule throws when the child run ends failed/stopped/
					// dead_letter, so it waits for the result.
					'wait' => true,
					// One item in, one child run — the rule ran the flow once
					// per request, not once per anything inside it.
					'fanOut' => false,
				],
			];
		}

		$config = [
			'synchronization' => $this->synchronizationOf(configuration: $configuration),
			'output' => self::KEY_SYNC_RESULT,
		];

		if (array_key_exists('force', $configuration) === true) {
			$config['force'] = filter_var($configuration['force'], FILTER_VALIDATE_BOOLEAN);
		}

		return [
			'id' => 'act',
			'type' => SynchronizationRunNode::NODE_ID,
			'config' => $config,
		];

	}//end actionNodeFor()

	/**
	 * Wire the nodes up, branching through the switch when there is one.
	 *
	 * @param array $nodes The flow's nodes, in pipeline order.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function edgesFor(array $nodes): array {
		$ids = array_column($nodes, 'id');

		if (in_array('gate', $ids, true) === false) {
			return [
				['id' => 'trigger-act', 'from' => 'trigger', 'to' => 'act'],
				['id' => 'act-end', 'from' => 'act', 'to' => 'end'],
			];
		}

		return [
			['id' => 'trigger-gate', 'from' => 'trigger', 'to' => 'gate'],
			[
				'id' => 'gate-act',
				'from' => 'gate',
				'fromExit' => self::EXIT_MATCH,
				'to' => 'act',
			],
			[
				'id' => 'gate-end',
				'from' => 'gate',
				'fromExit' => self::EXIT_OTHERWISE,
				'to' => 'end',
			],
			['id' => 'act-end', 'from' => 'act', 'to' => 'end'],
		];

	}//end edgesFor()

	/**
	 * The description that lets a human trace the flow back to its rule.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param array $endpoint The serialised record of the endpoint that runs it.
	 * @param string $event The object event the trigger waits for.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function describe(array $rule, array $endpoint, string $event): string {
		return $this->l10n->t(
			'Generated from rule "%1$s" (%2$s) as it runs on endpoint "%3$s" (%4$s), by the '
			. 'flow-native-synchronization migration. It is disabled until a human has reviewed it, and the '
			. 'rule is still attached to the endpoint — enable one or the other, never both. The endpoint\'s '
			. '%5$s became the object event "%6$s", so the flow fires on ANY change to that register and '
			. 'schema, including one made outside this endpoint: that is wider than the rule was, never '
			. 'narrower. The rule\'s conditions were rewritten from the request body onto the changed '
			. 'object; a condition on anything but the body is refused, not dropped.',
			[
				$this->subject->labelOf(entity: $rule),
				$this->subject->referenceOf(entity: $rule),
				$this->subject->labelOf(entity: $endpoint),
				$this->subject->referenceOf(entity: $endpoint),
				$this->scope->methodOf(endpoint: $endpoint),
				$event,
			]
		);

	}//end describe()

	/**
	 * The synchronization a `synchronization` rule names, in either shape.
	 *
	 * @param array $configuration The rule's configuration block.
	 *
	 * @return string The reference; empty when there is none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function synchronizationOf(array $configuration): string {
		$block = ($configuration['synchronization'] ?? null);
		if (is_array($block) === true) {
			return trim((string)($block['synchronization'] ?? ''));
		}

		return trim((string)($block ?? ''));

	}//end synchronizationOf()
}//end class
