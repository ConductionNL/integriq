<?php

/**
 * Unit tests for FlowStepsToGraphTranslator.
 *
 * The core semantics under test: step `order` becomes the node id verbatim so
 * branch targets survive translation; a duplicate `order` is refused exactly
 * as `FlowRunnerService::run()` refuses it, never silently collapsed; and
 * every legacy step type maps onto a node that actually exists.
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
use OCA\Integriq\Service\FlowStepsToGraphTranslator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure steps-to-graph translation.
 */
class FlowStepsToGraphTranslatorTest extends TestCase {

	/**
	 * The translator under test.
	 *
	 * @var FlowStepsToGraphTranslator
	 */
	private FlowStepsToGraphTranslator $translator;

	/**
	 * Build the translator with a pass-through l10n double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$this->translator = new FlowStepsToGraphTranslator(l10n: $l10n);

	}//end setUp()

	/**
	 * Index the produced nodes by id.
	 *
	 * @param array $graph The translation result.
	 *
	 * @return array<string, array> Nodes by id.
	 */
	private function nodesById(array $graph): array {
		$byId = [];
		foreach ($graph['nodes'] as $node) {
			$byId[(string)$node['id']] = $node;
		}

		return $byId;

	}//end nodesById()

	/**
	 * A linear flow chains trigger, one node per step in order, and end.
	 *
	 * @return void
	 */
	public function testLinearFlowChainsInOrder(): void {
		$graph = $this->translator->translate(
			flow: [
				'name' => 'linear',
				'steps' => [
					['order' => 20, 'type' => 'mapping', 'configRef' => 'map-1', 'onError' => 'stop'],
					['order' => 10, 'type' => 'call', 'configRef' => 'src-1', 'config' => ['endpoint' => '/x', 'method' => 'POST'], 'onError' => 'continue'],
				],
			]
		);

		$byId = $this->nodesById(graph: $graph);
		$this->assertArrayHasKey('trigger', $byId);
		$this->assertArrayHasKey('10', $byId);
		$this->assertArrayHasKey('20', $byId);
		$this->assertArrayHasKey('end', $byId);

		$this->assertSame('openconnector.source-call', $byId['10']['type']);
		$this->assertSame('src-1', $byId['10']['config']['source']);
		$this->assertSame('/x', $byId['10']['config']['endpoint']);
		$this->assertSame('POST', $byId['10']['config']['method']);
		$this->assertSame('continue', $byId['10']['onError']);

		$this->assertSame('openconnector.apply-mapping', $byId['20']['type']);
		$this->assertSame('map-1', $byId['20']['config']['mapping']);

		$pairs = array_map(static fn (array $edge): string => $edge['from'] . '>' . $edge['to'], $graph['edges']);
		$this->assertSame(['trigger>10', '10>20', '20>end'], $pairs, 'Execution order is `order` ascending, not array position');

	}//end testLinearFlowChainsInOrder()

	/**
	 * Branch targets survive translation: the branch node's edges point at
	 * the nodes whose ids are the referenced orders, conditions riding on
	 * the edges.
	 *
	 * @return void
	 */
	public function testBranchTargetsSurviveTranslation(): void {
		$condition = ['==' => [['var' => 'syncOutputAmended.status'], 'ok']];

		$graph = $this->translator->translate(
			flow: [
				'name' => 'branched',
				'steps' => [
					['order' => 10, 'type' => 'call', 'configRef' => 'src-1', 'config' => ['endpoint' => '/x'], 'onError' => 'stop'],
					[
						'order' => 30,
						'type' => 'branch',
						'onError' => 'stop',
						'branches' => [
							['condition' => $condition, 'nextStepOrder' => 40],
						],
						'defaultNextStepOrder' => 50,
					],
					['order' => 40, 'type' => 'mapping', 'configRef' => 'map-1', 'onError' => 'stop'],
					['order' => 50, 'type' => 'event', 'config' => ['type' => 't', 'source' => 's'], 'onError' => 'stop'],
				],
			]
		);

		$byId = $this->nodesById(graph: $graph);
		$this->assertSame('openregister.switch', $byId['30']['type']);

		$fromBranch = array_values(
			array_filter($graph['edges'], static fn (array $edge): bool => $edge['from'] === '30')
		);
		$this->assertCount(2, $fromBranch);

		$conditioned = array_values(array_filter($fromBranch, static fn (array $edge): bool => isset($edge['condition'])));
		$this->assertCount(1, $conditioned);
		$this->assertSame('40', $conditioned[0]['to'], 'The conditioned edge points at the node whose id is order 40');
		$this->assertSame($condition, $conditioned[0]['condition'], 'The JsonLogic condition rides the edge verbatim');

		$default = array_values(array_filter($fromBranch, static fn (array $edge): bool => isset($edge['condition']) === false));
		$this->assertSame('50', $default[0]['to'], 'The default edge points at the node whose id is order 50');

	}//end testBranchTargetsSurviveTranslation()

	/**
	 * A flow with duplicate step orders is refused, not silently collapsed
	 * into a graph missing one of the two nodes.
	 *
	 * @return void
	 */
	public function testDuplicateOrdersAreRefused(): void {
		try {
			$this->translator->translate(
				flow: [
					'name' => 'dupes',
					'steps' => [
						['order' => 20, 'type' => 'mapping', 'configRef' => 'a', 'onError' => 'stop'],
						['order' => 20, 'type' => 'mapping', 'configRef' => 'b', 'onError' => 'stop'],
					],
				]
			);
			$this->fail('Expected an EntityNotMigratableException');
		} catch (EntityNotMigratableException $refusal) {
			$this->assertSame('flow', $refusal->getSubject());
			$this->assertNotSame([], $refusal->getReasons());
			$this->assertStringContainsString('Duplicate step order 20', implode(' ', $refusal->getReasons()));
		}

	}//end testDuplicateOrdersAreRefused()

	/**
	 * A branch targeting a step order that does not exist is refused.
	 *
	 * @return void
	 */
	public function testDanglingBranchTargetIsRefused(): void {
		$this->expectException(EntityNotMigratableException::class);

		$this->translator->translate(
			flow: [
				'name' => 'dangling',
				'steps' => [
					[
						'order' => 10,
						'type' => 'branch',
						'onError' => 'stop',
						'branches' => [
							['condition' => ['var' => 'x'], 'nextStepOrder' => 99],
						],
					],
				],
			]
		);

	}//end testDanglingBranchTargetIsRefused()

	/**
	 * The features the graph cannot express faithfully are refused with one
	 * sentence each: step-level conditions, raw requestConfig, isTest runs,
	 * audience-less approvals and unknown types.
	 *
	 * @return void
	 */
	public function testUnsupportedFeaturesAreRefusedWithReasons(): void {
		try {
			$this->translator->translate(
				flow: [
					'name' => 'unsupported',
					'steps' => [
						['order' => 10, 'type' => 'call', 'configRef' => 'src', 'config' => ['requestConfig' => ['verify' => false]], 'onError' => 'stop'],
						['order' => 20, 'type' => 'synchronization', 'configRef' => 'sync', 'config' => ['isTest' => true], 'onError' => 'stop'],
						['order' => 30, 'type' => 'approval', 'config' => [], 'onError' => 'stop'],
						['order' => 40, 'type' => 'mapping', 'configRef' => 'map', 'condition' => ['var' => 'x'], 'onError' => 'stop'],
						['order' => 50, 'type' => 'telegram', 'onError' => 'stop'],
					],
				]
			);
			$this->fail('Expected an EntityNotMigratableException');
		} catch (EntityNotMigratableException $refusal) {
			$joined = implode(' ', $refusal->getReasons());
			$this->assertStringContainsString('requestConfig', $joined);
			$this->assertStringContainsString('isTest', $joined);
			$this->assertStringContainsString('approverGroup', $joined);
			$this->assertStringContainsString('condition', $joined);
			$this->assertStringContainsString('telegram', $joined);
			$this->assertCount(5, $refusal->getReasons(), 'Every unsupported feature is named, not just the first');
		}

	}//end testUnsupportedFeaturesAreRefusedWithReasons()

	/**
	 * The approval step maps its runner-era config onto the
	 * approval-request node: onReject `skip` routes, anything else fails.
	 *
	 * @return void
	 */
	public function testApprovalConfigMapsOntoTheNode(): void {
		$graph = $this->translator->translate(
			flow: [
				'name' => 'approvals',
				'steps' => [
					[
						'order' => 10,
						'type' => 'approval',
						'config' => ['approverGroup' => 'stewards', 'onReject' => 'skip', 'ttlSeconds' => 3600],
						'onError' => 'stop',
					],
					[
						'order' => 20,
						'type' => 'approval',
						'config' => ['approverGroup' => 'stewards', 'question' => 'Ship it?'],
						'onError' => 'stop',
					],
				],
			]
		);

		$byId = $this->nodesById(graph: $graph);

		$this->assertSame('openconnector.approval-request', $byId['10']['type']);
		$this->assertFalse($byId['10']['config']['failOnReject'], 'onReject skip routes the rejection onward');
		$this->assertSame(3600, $byId['10']['config']['ttlSeconds']);
		$this->assertNotSame('', $byId['10']['config']['question'], 'A question is synthesised when the step has none');

		$this->assertTrue($byId['20']['config']['failOnReject'], 'The runner default (error) fails on rejection');
		$this->assertSame('Ship it?', $byId['20']['config']['question']);

	}//end testApprovalConfigMapsOntoTheNode()

	/**
	 * Every legacy step type resolves to a node type that exists, so a
	 * migrated flow has no undispatchable step.
	 *
	 * @return void
	 */
	public function testEveryLegacyTypeHasANode(): void {
		$graph = $this->translator->translate(
			flow: [
				'name' => 'all-types',
				'steps' => [
					['order' => 10, 'type' => 'call', 'configRef' => 'src', 'config' => ['endpoint' => '/x'], 'onError' => 'stop'],
					['order' => 20, 'type' => 'mapping', 'configRef' => 'map', 'onError' => 'stop'],
					['order' => 30, 'type' => 'synchronization', 'configRef' => 'sync', 'config' => ['force' => true], 'onError' => 'stop'],
					['order' => 40, 'type' => 'event', 'config' => ['type' => 't', 'source' => 's', 'subject' => 'subj'], 'onError' => 'stop'],
					['order' => 50, 'type' => 'approval', 'config' => ['approverGroup' => 'g'], 'onError' => 'stop'],
					['order' => 60, 'type' => 'branch', 'branches' => [], 'defaultNextStepOrder' => 10, 'onError' => 'stop'],
				],
			]
		);

		$types = array_map(static fn (array $node): string => (string)$node['type'], $this->nodesById(graph: $graph));

		$this->assertSame('openconnector.source-call', $types['10']);
		$this->assertSame('openconnector.apply-mapping', $types['20']);
		$this->assertSame('openconnector.synchronization-run', $types['30']);
		$this->assertSame('openconnector.event-emit', $types['40']);
		$this->assertSame('openconnector.approval-request', $types['50']);
		$this->assertSame('openregister.switch', $types['60']);

		$byId = $this->nodesById(graph: $graph);
		$this->assertTrue($byId['30']['config']['force']);
		$this->assertSame('subj', $byId['40']['config']['subject']);

	}//end testEveryLegacyTypeHasANode()
}//end class
