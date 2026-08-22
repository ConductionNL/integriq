<?php

/**
 * Unit tests for RuleToFlowGenerator (flow-native-synchronization 3.3).
 *
 * Two things are being defended here.
 *
 * The first is the vocabulary: {@see testGeneratedConfigPassesTheNodesOwnValidateConfig()}
 * constructs the node that will actually run the step and hands it the config
 * the generator produced, so a key that drifts out of its declared set fails in
 * this file rather than at flow-save time on someone's instance.
 *
 * The second is the CONDITION, which is where a rule migration quietly goes
 * wrong. A rule's JsonLogic is written against the endpoint's request envelope;
 * a flow's is evaluated against the item. Everything except `body` has no
 * equivalent, and a dropped condition turns "fire on some objects" into "fire on
 * every object" while the flow still reports success. So every untranslatable
 * shape has a test proving the refusal FIRES, and the happy path proves the same
 * rule without it is not refused.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Flow\FlowOwner;
use OCA\Integriq\Flow\SynchronizationRunNode;
use OCA\Integriq\Service\MigrationEntityReader;
use OCA\Integriq\Service\MigrationSubject;
use OCA\Integriq\Service\RuleConditionTranslator;
use OCA\Integriq\Service\RuleEndpointScope;
use OCA\Integriq\Service\RuleToFlowGenerator;
use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the Rule → flow migration generator.
 *
 * @covers \OCA\Integriq\Service\RuleToFlowGenerator
 * @covers \OCA\Integriq\Service\RuleConditionTranslator
 * @covers \OCA\Integriq\Service\RuleEndpointScope
 * @covers \OCA\Integriq\Service\MigrationSubject
 */
class RuleToFlowGeneratorTest extends TestCase {

	/**
	 * The entity reader double.
	 *
	 * @var ORObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Translations, rendered so message assertions read the real sentence.
	 *
	 * @var IL10N&MockObject
	 */
	private $l10n;

	/**
	 * The generator under test.
	 *
	 * @var RuleToFlowGenerator
	 */
	private RuleToFlowGenerator $generator;

	/**
	 * Build the generator over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ORObjectService::class);
		$this->l10n = $this->translations();
		$this->generator = new RuleToFlowGenerator(
			reader: new MigrationEntityReader(objectService: $this->objectService, l10n: $this->l10n),
			conditions: new RuleConditionTranslator(),
			scope: new RuleEndpointScope(subject: new MigrationSubject()),
			subject: new MigrationSubject(),
			l10n: $this->l10n
		);

	}//end setUp()

	/**
	 * A translation double that renders its parameters.
	 *
	 * @return IL10N&MockObject The translations double.
	 */
	private function translations() {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		return $l10n;

	}//end translations()

	/**
	 * A rule the flow vocabulary can express.
	 *
	 * @param array $overrides Values replacing the migratable defaults.
	 *
	 * @return array The rule record.
	 */
	private function rule(array $overrides = []): array {
		return array_merge(
			[
				'uuid' => 'r0000000-0000-4000-8000-000000000001',
				'name' => 'Publish tender downstream',
				'type' => 'synchronization',
				'timing' => 'after',
				'action' => 'post',
				'order' => 0,
				'conditions' => ['==' => [['var' => 'body.status'], 'published']],
				'configuration' => ['synchronization' => 'tender-to-downstream'],
			],
			$overrides
		);

	}//end rule()

	/**
	 * The endpoint that gives the rule its object scope.
	 *
	 * @param array $overrides Values replacing the migratable defaults.
	 *
	 * @return array The endpoint record.
	 */
	private function endpoint(array $overrides = []): array {
		return array_merge(
			[
				'uuid' => 'e0000000-0000-4000-8000-000000000001',
				'name' => 'Create tender',
				'method' => 'POST',
				'targetType' => 'register/schema',
				'targetId' => 'spectr/tender',
				'rules' => ['r0000000-0000-4000-8000-000000000001'],
			],
			$overrides
		);

	}//end endpoint()

	/**
	 * The generated node whose id is asked for.
	 *
	 * @param array $flow The flow document.
	 * @param string $id The node id.
	 *
	 * @return array The node.
	 */
	private function node(array $flow, string $id): array {
		foreach ($flow['nodes'] as $node) {
			if ($node['id'] === $id) {
				return $node;
			}
		}

		$this->fail(sprintf('The generated flow has no node "%s".', $id));

	}//end node()

	/**
	 * The refusal a rule/endpoint pair produces, as one string.
	 *
	 * @param array $rule The rule record.
	 * @param array|null $endpoint The endpoint record; the migratable default when null.
	 *
	 * @return string Every reason, joined.
	 */
	private function refusal(array $rule, ?array $endpoint = null): string {
		try {
			$this->generator->generateFrom(rule: $rule, endpoint: ($endpoint ?? $this->endpoint()));
		} catch (EntityNotMigratableException $refusal) {
			$this->assertSame('rule', $refusal->getSubject());

			return implode(' | ', $refusal->getReasons());
		}

		$this->fail('The generator accepted a rule it cannot express.');

	}//end refusal()

	/**
	 * A generated flow is disabled, object-triggered, named and traceable.
	 *
	 * @return void
	 */
	public function testGeneratedFlowIsDisabledObjectTriggeredAndTraceable(): void {
		$flow = $this->generator->generateFrom(rule: $this->rule(), endpoint: $this->endpoint());

		$this->assertFalse($flow['enabled'], 'A generated flow is never enabled.');
		$this->assertSame('object.created', $flow['trigger']);
		$this->assertSame('spectr', $flow['triggerRegister']);
		$this->assertSame('tender', $flow['triggerSchema']);
		$this->assertStringContainsString('Publish tender downstream', $flow['name']);
		$this->assertStringContainsString('r0000000-0000-4000-8000-000000000001', $flow['description']);
		$this->assertStringContainsString('e0000000-0000-4000-8000-000000000001', $flow['description']);

	}//end testGeneratedFlowIsDisabledObjectTriggeredAndTraceable()

	/**
	 * The trigger's scope lives on the flow AND on the trigger node.
	 *
	 * `FlowTriggerDerivation` reads the node; the flow's own columns are what
	 * a listing and the dispatch path read. A document that set only one of
	 * them is a flow the two halves of the engine disagree about.
	 *
	 * @return void
	 */
	public function testTriggerScopeIsOnTheFlowAsWellAsOnTheNode(): void {
		$flow = $this->generator->generateFrom(rule: $this->rule(), endpoint: $this->endpoint());

		$this->assertSame(
			['event' => 'object.created', 'register' => 'spectr', 'schema' => 'tender'],
			$this->node(flow: $flow, id: 'trigger')['config']
		);

	}//end testTriggerScopeIsOnTheFlowAsWellAsOnTheNode()

	/**
	 * Each write method maps to the object event it actually produces.
	 *
	 * @param string $method The endpoint's HTTP method.
	 * @param string $event The object event.
	 *
	 * @return void
	 *
	 * @dataProvider writeMethods
	 */
	public function testEachWriteMethodMapsToItsObjectEvent(string $method, string $event): void {
		$flow = $this->generator->generateFrom(
			rule: $this->rule(),
			endpoint: $this->endpoint(['method' => $method])
		);

		$this->assertSame($event, $flow['trigger']);

	}//end testEachWriteMethodMapsToItsObjectEvent()

	/**
	 * The write methods and the events they produce.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function writeMethods(): array {
		return [
			'create' => ['POST', 'object.created'],
			'replace' => ['PUT', 'object.updated'],
			'patch' => ['patch', 'object.updated'],
			'delete' => ['DELETE', 'object.deleted'],
		];

	}//end writeMethods()

	/**
	 * The conditions become a switch whose match exit reaches the action.
	 *
	 * The switch sits IMMEDIATELY after the trigger on purpose:
	 * `FlowTokenRouter::takenExits()` reads `$items[0]` and routes the whole
	 * token, so the decision is only per-object while there is exactly one item
	 * — which is true between the trigger and the first step that fans out.
	 *
	 * @return void
	 */
	public function testConditionsBecomeASwitchWiredThroughItsExits(): void {
		$flow = $this->generator->generateFrom(rule: $this->rule(), endpoint: $this->endpoint());

		$this->assertSame(
			[
				'openregister.trigger-object',
				'openregister.switch',
				SynchronizationRunNode::NODE_ID,
				'openregister.end',
			],
			array_column($flow['nodes'], 'type')
		);

		$gate = $this->node(flow: $flow, id: 'gate');
		$this->assertSame([], $gate['config'], 'The switch declares no config keys at all.');
		$this->assertSame(
			[
				['id' => 'match', 'condition' => ['==' => [['var' => 'json.status'], 'published']]],
				['id' => 'otherwise'],
			],
			$gate['exits'],
			'The else is not optional: a node that conditions every exit is refused by the builder.'
		);

		$this->assertSame(
			[
				['id' => 'trigger-gate', 'from' => 'trigger', 'to' => 'gate'],
				['id' => 'gate-act', 'from' => 'gate', 'fromExit' => 'match', 'to' => 'act'],
				['id' => 'gate-end', 'from' => 'gate', 'fromExit' => 'otherwise', 'to' => 'end'],
				['id' => 'act-end', 'from' => 'act', 'to' => 'end'],
			],
			$flow['edges']
		);

	}//end testConditionsBecomeASwitchWiredThroughItsExits()

	/**
	 * A rule with no conditions gets no switch at all.
	 *
	 * A switch whose only exit is the else is a pass-through that looks like a
	 * decision, which is worse than not drawing one.
	 *
	 * @param mixed $conditions The conditions as stored.
	 *
	 * @return void
	 *
	 * @dataProvider absentConditions
	 */
	public function testARuleWithNoConditionsGetsNoSwitch(mixed $conditions): void {
		$flow = $this->generator->generateFrom(
			rule: $this->rule(['conditions' => $conditions]),
			endpoint: $this->endpoint()
		);

		$this->assertSame(
			['openregister.trigger-object', SynchronizationRunNode::NODE_ID, 'openregister.end'],
			array_column($flow['nodes'], 'type')
		);
		$this->assertSame(
			[
				['id' => 'trigger-act', 'from' => 'trigger', 'to' => 'act'],
				['id' => 'act-end', 'from' => 'act', 'to' => 'end'],
			],
			$flow['edges']
		);

	}//end testARuleWithNoConditionsGetsNoSwitch()

	/**
	 * The ways "this rule has no conditions" is stored.
	 *
	 * @return array<string, array<int, mixed>> The cases.
	 */
	public static function absentConditions(): array {
		return ['null' => [null], 'empty array' => [[]], 'empty string' => ['']];

	}//end absentConditions()

	/**
	 * A `var` written with a default keeps the default and moves the path.
	 *
	 * @return void
	 */
	public function testAVarWithADefaultKeepsTheDefault(): void {
		$flow = $this->generator->generateFrom(
			rule: $this->rule(
				[
					'conditions' => [
						'and' => [
							['!=' => [['var' => ['body.owner.id', 'unassigned']], 'unassigned']],
							['>' => [['var' => 'body.amount'], 1000]],
							['==' => [['var' => 'body'], null]],
						],
					],
				]
			),
			endpoint: $this->endpoint()
		);

		$this->assertSame(
			[
				'and' => [
					['!=' => [['var' => ['json.owner.id', 'unassigned']], 'unassigned']],
					['>' => [['var' => 'json.amount'], 1000]],
					['==' => [['var' => 'json'], null]],
				],
			],
			$this->node(flow: $flow, id: 'gate')['exits'][0]['condition']
		);

	}//end testAVarWithADefaultKeepsTheDefault()

	/**
	 * A synchronization rule becomes a run step; a flow rule a sub-flow step.
	 *
	 * @return void
	 */
	public function testTheActionStepMatchesTheRuleKind(): void {
		$syncFlow = $this->generator->generateFrom(
			rule: $this->rule(
				['configuration' => ['synchronization' => ['synchronization' => 's1'], 'force' => 'yes']]
			),
			endpoint: $this->endpoint()
		);

		$this->assertSame(
			['synchronization' => 's1', 'output' => RuleToFlowGenerator::KEY_SYNC_RESULT, 'force' => true],
			$this->node(flow: $syncFlow, id: 'act')['config']
		);

		$flowRule = $this->generator->generateFrom(
			rule: $this->rule(['type' => 'flow', 'configuration' => ['flow' => 'downstream-flow']]),
			endpoint: $this->endpoint()
		);

		$this->assertSame(
			['flowId' => 'downstream-flow', 'wait' => true, 'fanOut' => false],
			$this->node(flow: $flowRule, id: 'act')['config'],
			'The rule throws when the child run fails, so the step has to wait for it.'
		);

	}//end testTheActionStepMatchesTheRuleKind()

	/**
	 * Every generated config is accepted by the node that will run it.
	 *
	 * @return void
	 */
	public function testGeneratedConfigPassesTheNodesOwnValidateConfig(): void {
		$flow = $this->generator->generateFrom(rule: $this->rule(), endpoint: $this->endpoint());
		$config = $this->node(flow: $flow, id: 'act')['config'];

		$node = new SynchronizationRunNode(
			synchronizationService: $this->createMock(SynchronizationService::class),
			flowOwner: new FlowOwner(
				userManager: $this->createMock(IUserManager::class),
				userSession: $this->createMock(IUserSession::class),
				l10n: $this->l10n
			),
			l10n: $this->l10n,
			urlGenerator: $this->createMock(IURLGenerator::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$node->validateConfig(config: $config);

		$this->assertSame([], array_diff(array_keys($config), $node->configKeys()));

	}//end testGeneratedConfigPassesTheNodesOwnValidateConfig()

	/**
	 * Every OpenRegister step the generator emits is accepted by its own node.
	 *
	 * OpenRegister is a peer app, not a composer dependency: app CI clones it,
	 * so this runs for real there. In a bare checkout the class is absent and
	 * the test says so instead of passing quietly.
	 *
	 * @return void
	 */
	public function testGeneratedConfigPassesTheOpenRegisterNodesOwnValidateConfig(): void {
		$triggerClass = 'OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode';
		$switchClass = 'OCA\OpenRegister\Service\Flow\Nodes\SwitchNode';
		if (class_exists($triggerClass) === false) {
			$this->markTestSkipped(
				'OpenRegister is not on the autoloader in this checkout, so its node classes cannot be '
				. 'constructed. App CI clones openregister and runs this for real.'
			);
		}

		$flow = $this->generator->generateFrom(rule: $this->rule(), endpoint: $this->endpoint());
		$urls = $this->createMock(IURLGenerator::class);

		$trigger = new $triggerClass($this->l10n, $urls);
		$trigger->validateConfig($this->node(flow: $flow, id: 'trigger')['config']);
		$this->assertSame(
			[],
			array_diff(array_keys($this->node(flow: $flow, id: 'trigger')['config']), $trigger->configKeys())
		);

		$switch = new $switchClass($this->l10n, $urls);
		$switch->validateConfig($this->node(flow: $flow, id: 'gate')['config']);
		$this->assertSame([], $switch->configKeys(), 'A switch that grew a config key would need new wiring.');

	}//end testGeneratedConfigPassesTheOpenRegisterNodesOwnValidateConfig()

	/**
	 * The object trigger is COMPLETE, and its event is one the engine fires.
	 *
	 * Asserted in-repo rather than left to the flow preflight, because the
	 * preflight cannot see this: `FlowNodePreflight::configRejection()` treats
	 * only an `UnexpectedValueException` as blocking, and `TriggerObjectNode`
	 * throws `InvalidArgumentException` — so a trigger with no register comes
	 * back `valid: true` and is then DROPPED by
	 * `FlowTriggerDerivation::objectTriggersOf()`, leaving a flow that saves
	 * clean and subscribes to nothing. Verified live on 2026-08-19: the
	 * mutated document validated true while nextcloud.log carried
	 * "validateConfig() failed for its own reasons, not blocking".
	 *
	 * @return void
	 */
	public function testTheObjectTriggerIsCompleteAndUsesAKnownEvent(): void {
		$config = $this->node(
			flow: $this->generator->generateFrom(rule: $this->rule(), endpoint: $this->endpoint()),
			id: 'trigger'
		)['config'];

		$this->assertSame(['event', 'register', 'schema'], array_keys($config));
		foreach ($config as $key => $value) {
			$this->assertNotSame('', trim((string)$value), sprintf('"%s" must not be empty.', $key));
		}

		$this->assertContains(
			$config['event'],
			['object.created', 'object.updated', 'object.deleted'],
			'The engine fires exactly these three; anything else never starts the flow.'
		);

	}//end testTheObjectTriggerIsCompleteAndUsesAKnownEvent()

	/**
	 * A rule or endpoint with nothing to name it is refused.
	 *
	 * @return void
	 */
	public function testEntitiesWithNoReferenceAreRefused(): void {
		$this->assertStringContainsString(
			'The rule carries no uuid, slug or reference',
			$this->refusal(rule: $this->rule(['uuid' => null, 'slug' => ['not', 'scalar'], 'reference' => ' ']))
		);

		$this->assertStringContainsString(
			'The endpoint carries no uuid, slug or reference',
			$this->refusal(rule: $this->rule(), endpoint: $this->endpoint(['uuid' => '']))
		);

	}//end testEntitiesWithNoReferenceAreRefused()

	/**
	 * A rule the endpoint does not list is refused.
	 *
	 * @return void
	 */
	public function testARuleTheEndpointDoesNotListIsRefused(): void {
		$this->assertStringContainsString(
			'does not list this rule',
			$this->refusal(rule: $this->rule(), endpoint: $this->endpoint(['rules' => ['someone-else', []]]))
		);

	}//end testARuleTheEndpointDoesNotListIsRefused()

	/**
	 * An endpoint that is not object-backed is refused.
	 *
	 * @return void
	 */
	public function testANonObjectEndpointIsRefused(): void {
		$this->assertStringContainsString(
			'only a "register/schema" endpoint has them',
			$this->refusal(rule: $this->rule(), endpoint: $this->endpoint(['targetType' => 'api']))
		);

	}//end testANonObjectEndpointIsRefused()

	/**
	 * An endpoint whose target is not a register/schema pair is refused.
	 *
	 * @return void
	 */
	public function testAnUnusableTargetPairIsRefused(): void {
		$this->assertStringContainsString(
			'is not a "register/schema" pair',
			$this->refusal(rule: $this->rule(), endpoint: $this->endpoint(['targetId' => 'spectr']))
		);

	}//end testAnUnusableTargetPairIsRefused()

	/**
	 * A read endpoint is refused: no object changes, so nothing ever fires.
	 *
	 * @return void
	 */
	public function testAReadEndpointIsRefused(): void {
		$reasons = $this->refusal(rule: $this->rule(), endpoint: $this->endpoint(['method' => 'GET']));

		$this->assertStringContainsString('it changes no object', $reasons);
		$this->assertStringContainsString('never start', $reasons);

	}//end testAReadEndpointIsRefused()

	/**
	 * A before-timing rule is refused: the trigger fires after the write.
	 *
	 * @return void
	 */
	public function testABeforeTimingRuleIsRefused(): void {
		$this->assertStringContainsString(
			'fires AFTER the change is committed',
			$this->refusal(rule: $this->rule(['timing' => 'before']))
		);

		$this->assertStringContainsString(
			'timing "before"',
			$this->refusal(rule: $this->rule(['timing' => null])),
			'An unset timing defaults to "before" in the pipeline, so it defaults to refused here.'
		);

	}//end testABeforeTimingRuleIsRefused()

	/**
	 * Conditions stored as something other than a tree are refused.
	 *
	 * @return void
	 */
	public function testConditionsThatAreNotATreeAreRefused(): void {
		$this->assertStringContainsString(
			'the rule stores a string',
			$this->refusal(rule: $this->rule(['conditions' => '{"==":[1,1]}']))
		);

	}//end testConditionsThatAreNotATreeAreRefused()

	/**
	 * Conditions reading anything but the body are refused, by path.
	 *
	 * @return void
	 */
	public function testConditionsOnTheRequestEnvelopeAreRefused(): void {
		$reasons = $this->refusal(
			rule: $this->rule(
				[
					'conditions' => [
						'and' => [
							['==' => [['var' => 'headers.x-tenant'], 'gemeente']],
							['!=' => [['var' => ['parameters.mode', 'live']], 'dry']],
							['==' => [['var' => 'body.status'], 'published']],
						],
					],
				]
			)
		);

		$this->assertStringContainsString('"headers.x-tenant"', $reasons);
		$this->assertStringContainsString('"parameters.mode"', $reasons);
		$this->assertStringNotContainsString('"body.status"', $reasons, 'A body path IS translatable.');
		$this->assertStringContainsString('fire on every object instead of some', $reasons);

	}//end testConditionsOnTheRequestEnvelopeAreRefused()

	/**
	 * A path that merely starts with the word "body" is not a body path.
	 *
	 * @return void
	 */
	public function testAPathThatOnlyLooksLikeTheBodyIsRefused(): void {
		$this->assertStringContainsString(
			'"bodyguard.name"',
			$this->refusal(rule: $this->rule(['conditions' => ['==' => [['var' => 'bodyguard.name'], 'x']]]))
		);

	}//end testAPathThatOnlyLooksLikeTheBodyIsRefused()

	/**
	 * Sub-scope operators are refused: they rebind what `var` means.
	 *
	 * @param string $operator The scope-rebinding operator.
	 *
	 * @return void
	 *
	 * @dataProvider scopeOperators
	 */
	public function testSubScopeOperatorsAreRefused(string $operator): void {
		$reasons = $this->refusal(
			rule: $this->rule(
				['conditions' => [$operator => [['var' => 'body.lines'], ['>' => [['var' => 'amount'], 0]]]]]
			)
		);

		$this->assertStringContainsString($operator, $reasons);
		$this->assertStringContainsString('rebind what "var" means', $reasons);

	}//end testSubScopeOperatorsAreRefused()

	/**
	 * The JsonLogic operators that open a new `var` scope.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function scopeOperators(): array {
		return [
			'map' => ['map'],
			'filter' => ['filter'],
			'reduce' => ['reduce'],
			'all' => ['all'],
			'some' => ['some'],
			'none' => ['none'],
		];

	}//end scopeOperators()

	/**
	 * A rule kind with no object-event equivalent is refused, by name.
	 *
	 * @param string $type The rule type.
	 *
	 * @return void
	 *
	 * @dataProvider envelopeOnlyRuleTypes
	 */
	public function testEnvelopeOnlyRuleKindsAreRefused(string $type): void {
		$reasons = $this->refusal(rule: $this->rule(['type' => $type, 'configuration' => []]));

		$this->assertStringContainsString(sprintf('type "%s"', $type), $reasons);
		$this->assertStringContainsString('no envelope to act on', $reasons);

	}//end testEnvelopeOnlyRuleKindsAreRefused()

	/**
	 * Rule kinds that only make sense inside the endpoint pipeline.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function envelopeOnlyRuleTypes(): array {
		return [
			'mapping' => ['mapping'],
			'error' => ['error'],
			'save_object' => ['save_object'],
			'authentication' => ['authentication'],
			'javascript' => ['javascript'],
			'approval' => ['approval'],
			'download' => ['download'],
			'locking' => ['locking'],
			'webhook_signature' => ['webhook_signature'],
			'unset' => [''],
		];

	}//end envelopeOnlyRuleTypes()

	/**
	 * A flow rule that names no flow is refused.
	 *
	 * @return void
	 */
	public function testAFlowRuleWithNoFlowIsRefused(): void {
		$this->assertStringContainsString(
			'configuration.flow is not set',
			$this->refusal(rule: $this->rule(['type' => 'flow', 'configuration' => []]))
		);

	}//end testAFlowRuleWithNoFlowIsRefused()

	/**
	 * A synchronization rule that names no synchronization is refused.
	 *
	 * @return void
	 */
	public function testASynchronizationRuleWithNoTargetIsRefused(): void {
		$this->assertStringContainsString(
			'configuration.synchronization is not set',
			$this->refusal(rule: $this->rule(['configuration' => []]))
		);

	}//end testASynchronizationRuleWithNoTargetIsRefused()

	/**
	 * Sync-rule configuration the run step cannot read is refused, by key.
	 *
	 * @return void
	 */
	public function testSyncRuleConfigurationTheStepCannotReadIsRefused(): void {
		$reasons = $this->refusal(
			rule: $this->rule(
				[
					'configuration' => [
						'synchronization' => [
							'synchronization' => 's1',
							'objectIdPath' => 'body.id',
							'preDelay' => 2,
							'postDelay' => 1,
							'retainResponse' => true,
						],
						'isTest' => true,
						'synchronizationConfig' => ['mergeResultToKey' => 'result'],
					],
				]
			)
		);

		$this->assertStringContainsString('objectIdPath, preDelay, postDelay, retainResponse', $reasons);
		$this->assertStringContainsString('configuration.isTest', $reasons);
		$this->assertStringContainsString('configuration.synchronizationConfig', $reasons);

	}//end testSyncRuleConfigurationTheStepCannotReadIsRefused()

	/**
	 * A refusal counts and names every feature at once, not just the first.
	 *
	 * @return void
	 */
	public function testARefusalNamesEveryUnsupportedFeatureAtOnce(): void {
		try {
			$this->generator->generateFrom(
				rule: $this->rule(['timing' => 'before', 'type' => 'mapping', 'configuration' => []]),
				endpoint: $this->endpoint(['method' => 'GET'])
			);
		} catch (EntityNotMigratableException $refusal) {
			$this->assertCount(3, $refusal->getReasons());
			$this->assertStringContainsString('3 unsupported feature(s)', $refusal->getMessage());
			$this->assertStringContainsString('Publish tender downstream', $refusal->getMessage());

			return;
		}

		$this->fail('The generator accepted a rule with three unsupported features.');

	}//end testARefusalNamesEveryUnsupportedFeatureAtOnce()

	/**
	 * Reading by reference goes through OpenRegister and produces a document.
	 *
	 * @return void
	 */
	public function testGenerateForReadsBothEntitiesAndRendersTheFlow(): void {
		$rule = new ObjectEntity();
		$rule->setUuid('rule-uuid');
		$rule->setObject($this->rule(['uuid' => null, 'slug' => 'publish-tender']));

		$endpoint = new ObjectEntity();
		$endpoint->setUuid('endpoint-uuid');
		$endpoint->setObject($this->endpoint(['uuid' => null, 'rules' => ['publish-tender']]));

		$this->objectService->method('find')->willReturnCallback(
			static function (string $id) use ($rule, $endpoint): ObjectEntity {
				if ($id === 'publish-tender') {
					return $rule;
				}

				return $endpoint;
			}
		);

		$flow = $this->generator->generateFor(
			ruleReference: ' publish-tender ',
			endpointReference: 'create-tender'
		);

		$this->assertSame('object.created', $flow['trigger']);
		$this->assertStringContainsString(
			'rule-uuid',
			$flow['description'],
			'A record whose object body omits the uuid still has to be traceable.'
		);
		$this->assertStringContainsString('endpoint-uuid', $flow['description']);

	}//end testGenerateForReadsBothEntitiesAndRendersTheFlow()

	/**
	 * Naming nothing is a refusal that says WHY the endpoint is required too.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceIsRefusedAndExplainsWhy(): void {
		try {
			$this->generator->generateFor(ruleReference: '   ', endpointReference: 'e1');
		} catch (EntityNotMigratableException $refusal) {
			$this->assertStringContainsString(
				'carries no register or schema of its own',
				implode(' ', $refusal->getReasons())
			);

			return;
		}

		$this->fail('A missing endpoint reference was not refused.');

	}//end testAnEmptyReferenceIsRefusedAndExplainsWhy()

	/**
	 * An entity that does not exist is refused, naming the reference.
	 *
	 * @return void
	 */
	public function testAMissingEntityIsRefused(): void {
		$this->objectService->method('find')->willReturn(null);

		try {
			$this->generator->generateFor(ruleReference: 'no-such-rule', endpointReference: 'e1');
		} catch (EntityNotMigratableException $refusal) {
			$this->assertStringContainsString('no-such-rule', $refusal->getMessage());
			$this->assertStringContainsString('No rule with that uuid', implode(' ', $refusal->getReasons()));

			return;
		}

		$this->fail('A missing rule was not refused.');

	}//end testAMissingEntityIsRefused()

	/**
	 * A read that throws is reported as a refusal carrying the cause.
	 *
	 * @return void
	 */
	public function testAFailedReadIsReportedAsARefusal(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('database is on fire'));

		try {
			$this->generator->generateFor(ruleReference: 'boom', endpointReference: 'e1');
		} catch (EntityNotMigratableException $refusal) {
			$this->assertStringContainsString('database is on fire', implode(' ', $refusal->getReasons()));
			$this->assertInstanceOf(RuntimeException::class, $refusal->getPrevious());

			return;
		}

		$this->fail('A failed read was not refused.');

	}//end testAFailedReadIsReportedAsARefusal()

	/**
	 * A nameless rule falls back to its reference in messages.
	 *
	 * @return void
	 */
	public function testANamelessRuleIsLabelledByItsReference(): void {
		$flow = $this->generator->generateFrom(
			rule: $this->rule(['name' => ' ']),
			endpoint: $this->endpoint()
		);

		$this->assertStringContainsString('r0000000-0000-4000-8000-000000000001', $flow['name']);

	}//end testANamelessRuleIsLabelledByItsReference()

	/**
	 * A migratable rule produces no refusals at all — the positive control.
	 *
	 * @return void
	 */
	public function testAMigratableRuleIsNotRefused(): void {
		$this->assertSame(
			[],
			$this->generator->refusalsFor(rule: $this->rule(), endpoint: $this->endpoint())
		);

		$this->assertSame(
			[],
			$this->generator->refusalsFor(
				rule: $this->rule(
					[
						'type' => 'flow',
						'conditions' => null,
						'configuration' => ['flow' => 'f1'],
					]
				),
				endpoint: $this->endpoint()
			)
		);

	}//end testAMigratableRuleIsNotRefused()
}//end class
