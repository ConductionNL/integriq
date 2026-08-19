<?php

/**
 * Unit tests for SynchronizationFlowGenerator (flow-native-synchronization 3.1).
 *
 * The assertion that makes the generator trustworthy is not the shape of the
 * document — a golden file would pin that and still ship a config key a node
 * rejects. It is {@see testGeneratedConfigPassesEveryNodesOwnValidateConfig()}:
 * the five page-level nodes are CONSTRUCTED and handed the config the generator
 * produced for them, so a key that drifts out of a node's vocabulary fails here
 * rather than at flow-save time on someone's instance.
 *
 * The rest of the file is the refusal surface. A migration that quietly drops a
 * rule is worse than one that refuses, so every refusal branch has a test that
 * shows it CAN fire — and the happy-path fixture proves the same inputs without
 * that feature do NOT fire it.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\SynchronizationNotMigratableException;
use OCA\OpenConnector\Flow\ApplyMappingNode;
use OCA\OpenConnector\Flow\ContractCommitNode;
use OCA\OpenConnector\Flow\ContractMatchNode;
use OCA\OpenConnector\Flow\ContractSweepNode;
use OCA\OpenConnector\Flow\FlowOwner;
use OCA\OpenConnector\Flow\SourcePaginateNode;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenConnector\Service\SynchronizationFlowGenerator;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\Mapping as OrMapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the Synchronization → flow migration generator.
 */
class SynchronizationFlowGeneratorTest extends TestCase {

	/**
	 * The synchronization reader double.
	 *
	 * @var SynchronizationService&MockObject
	 */
	private $synchronizationService;

	/**
	 * The mapping reader double.
	 *
	 * @var MappingService&MockObject
	 */
	private $mappingService;

	/**
	 * Translations, rendered so message assertions read the real sentence.
	 *
	 * @var IL10N&MockObject
	 */
	private $l10n;

	/**
	 * The generator under test.
	 *
	 * @var SynchronizationFlowGenerator
	 */
	private SynchronizationFlowGenerator $generator;

	/**
	 * Build the generator over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->synchronizationService = $this->createMock(SynchronizationService::class);
		$this->mappingService = $this->createMock(MappingService::class);
		$this->l10n = $this->translations();

		$this->mappingService->method('getMapping')->willReturnCallback(
			fn (): OrMapping => $this->mappingDouble()
		);

		$this->generator = new SynchronizationFlowGenerator(
			synchronizationService: $this->synchronizationService,
			mappingService: $this->mappingService,
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
	 * A mapping whose output keys become the written fields.
	 *
	 * @param array $overrides Values replacing the default mapping record.
	 *
	 * @return OrMapping The hydrated mapping.
	 */
	private function mappingDouble(array $overrides = []): OrMapping {
		$mapping = new OrMapping();
		$mapping->hydrate(
			array_merge(
				[
					'name' => 'tender mapping',
					'mapping' => [
						'title' => '{{ title }}',
						'summary' => '{{ description }}',
						// A dotted key builds a nested value under `publication`,
						// so `publication` is the property that gets written.
						'publication.date' => '{{ published }}',
						'publication.source' => '{{ origin }}',
						'draft' => '{{ draft }}',
						// An empty key is not a property and is skipped.
						'' => '{{ nothing }}',
					],
					// A bare key removes the property; a dotted one removes only a
					// sub-key, so `publication` survives it.
					'unset' => ['draft', 'publication.source'],
					'passThrough' => false,
				],
				$overrides
			)
		);

		return $mapping;

	}//end mappingDouble()

	/**
	 * A synchronization the decomposed flow can express.
	 *
	 * @param array $overrides Values replacing the migratable defaults.
	 *
	 * @return array The synchronization record.
	 */
	private function synchronization(array $overrides = []): array {
		return array_merge(
			[
				'uuid' => 'e5f2b0d0-0000-4000-8000-000000000001',
				'name' => 'TenderNed datasets',
				'sourceType' => 'api',
				'sourceTargetMapping' => 'tenderned-to-tender',
				'sourceConfig' => ['idPosition' => 'publicatieId'],
				'targetType' => 'register/schema',
				'targetId' => 'spectr/tender',
				'syncMode' => 'full',
			],
			$overrides
		);

	}//end synchronization()

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
	 * A generated flow is disabled, manual, named and traceable to its source.
	 *
	 * @return void
	 */
	public function testGeneratedFlowIsDisabledNamedAndTraceable(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());

		$this->assertFalse($flow['enabled'], 'A generated flow is never enabled.');
		$this->assertSame('manual', $flow['trigger']);
		$this->assertStringContainsString('TenderNed datasets', $flow['name']);
		$this->assertStringContainsString(
			'e5f2b0d0-0000-4000-8000-000000000001',
			$flow['description'],
			'The source synchronization must be traceable from the flow.'
		);

	}//end testGeneratedFlowIsDisabledNamedAndTraceable()

	/**
	 * The pipeline is the decomposed one, chained end to end.
	 *
	 * @return void
	 */
	public function testPipelineIsTheDecomposedChain(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());

		$this->assertSame(
			[
				'openregister.trigger-manual',
				SourcePaginateNode::NODE_ID,
				'openregister.explode',
				ApplyMappingNode::NODE_ID,
				ContractMatchNode::NODE_ID,
				'openregister.set-fields',
				'openregister.object-write',
				ContractCommitNode::NODE_ID,
				ContractSweepNode::NODE_ID,
				'openregister.end',
			],
			array_column($flow['nodes'], 'type')
		);

		$this->assertCount((count($flow['nodes']) - 1), $flow['edges']);
		foreach ($flow['edges'] as $index => $edge) {
			$this->assertSame($flow['nodes'][$index]['id'], $edge['from']);
			$this->assertSame($flow['nodes'][($index + 1)]['id'], $edge['to']);
		}

	}//end testPipelineIsTheDecomposedChain()

	/**
	 * Every generated config is accepted by the node that will run it.
	 *
	 * The assertion the whole generator rests on: a golden document would not
	 * catch a key a node rejects, so the nodes are built and asked themselves.
	 *
	 * @return void
	 */
	public function testGeneratedConfigPassesEveryNodesOwnValidateConfig(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());

		foreach ($this->nodesUnderTest() as $nodeId => $node) {
			$config = $this->node(flow: $flow, id: $this->stepIdFor(nodeId: $nodeId))['config'];

			$node->validateConfig(config: $config);

			$this->assertSame(
				[],
				array_diff(array_keys($config), $node->configKeys()),
				sprintf('The generated config for "%s" uses a key the node does not declare.', $nodeId)
			);
		}

	}//end testGeneratedConfigPassesEveryNodesOwnValidateConfig()

	/**
	 * The OpenConnector page nodes, constructed over doubles.
	 *
	 * @return array<string, object> Node id => node.
	 */
	private function nodesUnderTest(): array {
		$flowOwner = new FlowOwner(
			userManager: $this->createMock(IUserManager::class),
			userSession: $this->createMock(IUserSession::class),
			l10n: $this->l10n
		);
		$urls = $this->createMock(IURLGenerator::class);
		$logger = $this->createMock(LoggerInterface::class);
		$sync = $this->createMock(SynchronizationService::class);
		$contracts = $this->createMock(SynchronizationContractService::class);

		return [
			SourcePaginateNode::NODE_ID => new SourcePaginateNode(
				synchronizationService: $sync,
				flowOwner: $flowOwner,
				l10n: $this->l10n,
				urlGenerator: $urls,
				logger: $logger
			),
			ApplyMappingNode::NODE_ID => new ApplyMappingNode(
				mappingService: $this->createMock(MappingService::class),
				flowOwner: $flowOwner,
				l10n: $this->l10n,
				urlGenerator: $urls,
				logger: $logger
			),
			ContractMatchNode::NODE_ID => new ContractMatchNode(
				synchronizationContractService: $contracts,
				flowOwner: $flowOwner,
				l10n: $this->l10n,
				urlGenerator: $urls,
				logger: $logger
			),
			ContractCommitNode::NODE_ID => new ContractCommitNode(
				synchronizationContractService: $contracts,
				flowOwner: $flowOwner,
				l10n: $this->l10n,
				urlGenerator: $urls,
				logger: $logger
			),
			ContractSweepNode::NODE_ID => new ContractSweepNode(
				synchronizationService: $sync,
				flowOwner: $flowOwner,
				l10n: $this->l10n,
				urlGenerator: $urls,
				logger: $logger
			),
		];

	}//end nodesUnderTest()

	/**
	 * The generated step id carrying the given node type.
	 *
	 * @param string $nodeId The node type id.
	 *
	 * @return string The step id.
	 */
	private function stepIdFor(string $nodeId): string {
		return [
			SourcePaginateNode::NODE_ID => 'fetch',
			ApplyMappingNode::NODE_ID => 'map',
			ContractMatchNode::NODE_ID => 'contract',
			ContractCommitNode::NODE_ID => 'commit',
			ContractSweepNode::NODE_ID => 'sweep',
		][$nodeId];

	}//end stepIdFor()

	/**
	 * The write step names the target register and schema, and every mapped field.
	 *
	 * @return void
	 */
	public function testWriteStepEnumeratesTheMappingsOutputProperties(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());
		$config = $this->node(flow: $flow, id: 'write')['config'];

		$this->assertSame('spectr', $config['register']);
		$this->assertSame('tender', $config['schema']);
		$this->assertSame('upsert', $config['operation']);
		$this->assertTrue($config['replace']);
		$this->assertSame(
			[['property' => '@self.uuid', 'value' => '{{targetUuid}}']],
			$config['match'],
			'A create decision has no targetId, so the match must resolve to an empty uuid, not be absent.'
		);

		// `draft` was unset by the mapping; `publication.source` was a dotted
		// unset and removes only a sub-key, so `publication` survives.
		$this->assertSame(
			[
				'title' => '{{target.title}}',
				'summary' => '{{target.summary}}',
				'publication' => '{{target.publication}}',
			],
			$config['fields']
		);

	}//end testWriteStepEnumeratesTheMappingsOutputProperties()

	/**
	 * The always-resolvable uuid is computed, not templated.
	 *
	 * @return void
	 */
	public function testTargetUuidIsComputedWithAnEmptyDefault(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());

		$this->assertSame(
			['compute' => ['targetUuid' => ['var' => ['json.contract.targetId', '']]]],
			$this->node(flow: $flow, id: 'target-uuid')['config']
		);

	}//end testTargetUuidIsComputedWithAnEmptyDefault()

	/**
	 * The commit step hashes the MAPPED object, and the three keys agree.
	 *
	 * `map` writes its result under `target`, `write` reads `{{target.…}}`, and
	 * `commit` hashes `target`. If those ever disagree the flow still validates
	 * and still runs — it just writes empty objects, or hashes something that
	 * changes every pass — so the agreement is asserted here rather than left
	 * to a reader comparing three node configs by eye.
	 *
	 * `written` is asserted NOT to be the hash source on purpose: it carries
	 * server-assigned `@self` fields including `updated`, so hashing it would
	 * produce a fresh hash every run and `skip` would stay unreachable.
	 *
	 * @return void
	 */
	public function testCommitStepHashesTheMappedObjectNotTheWrittenOne(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());

		$mapOutput = $this->node(flow: $flow, id: 'map')['config']['output'];
		$commit = $this->node(flow: $flow, id: 'commit')['config'];

		$this->assertSame('target', $mapOutput);
		$this->assertSame($mapOutput, $commit['targetHashPosition']);
		$this->assertSame('written.uuid', $commit['targetIdPosition']);
		$this->assertNotSame('written', $commit['targetHashPosition']);

		foreach ($this->node(flow: $flow, id: 'write')['config']['fields'] as $template) {
			$this->assertStringStartsWith('{{' . $mapOutput . '.', (string)$template);
		}

	}//end testCommitStepHashesTheMappedObjectNotTheWrittenOne()

	/**
	 * The contract step reads the origin id where the legacy engine reads it.
	 *
	 * @return void
	 */
	public function testContractStepUsesTheConfiguredIdPosition(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());

		$this->assertSame(
			'source.publicatieId',
			$this->node(flow: $flow, id: 'contract')['config']['idPosition']
		);

	}//end testContractStepUsesTheConfiguredIdPosition()

	/**
	 * An unset `idPosition` falls back to the legacy default.
	 *
	 * @return void
	 */
	public function testIdPositionFallsBackToTheLegacyDefault(): void {
		$flow = $this->generator->generateFrom(
			synchronization: $this->synchronization(overrides: ['sourceConfig' => []])
		);

		$this->assertSame(
			'source.id',
			$this->node(flow: $flow, id: 'contract')['config']['idPosition']
		);

	}//end testIdPositionFallsBackToTheLegacyDefault()

	/**
	 * The sweep never forces, and gates on the fetch's own completeness verdict.
	 *
	 * @return void
	 */
	public function testSweepGatesOnFetchCompletenessAndNeverForces(): void {
		$flow = $this->generator->generateFrom(synchronization: $this->synchronization());
		$config = $this->node(flow: $flow, id: 'sweep')['config'];

		$this->assertSame('page.fetchInfo.complete', $config['fetchComplete']);
		$this->assertArrayNotHasKey('force', $config, 'A generator never overrides the deletion-ratio guard.');

	}//end testSweepGatesOnFetchCompletenessAndNeverForces()

	/**
	 * The refusal cases, one per unsupported feature.
	 *
	 * @return array<string, array{0: array, 1: string}> Overrides and the expected fragment.
	 */
	public static function refusalProvider(): array {
		return [
			'no reference at all' => [
				['uuid' => '', 'slug' => '', 'reference' => '', 'id' => ''],
				'no uuid, slug or reference',
			],
			'a non-scalar reference' => [
				['uuid' => ['not', 'a', 'reference'], 'slug' => '', 'reference' => '', 'id' => ''],
				'no uuid, slug or reference',
			],
			'a source kind with no fetch step' => [
				['sourceType' => 'nextcloud-table'],
				'sourceType "nextcloud-table"',
			],
			'a target kind the write step cannot write' => [
				['targetType' => 'api'],
				'targetType "api"',
			],
			'a targetId that is not a pair' => [
				['targetId' => 'spectr'],
				'is not a "register/schema" pair',
			],
			'a targetId with an empty half' => [
				['targetId' => 'spectr/'],
				'is not a "register/schema" pair',
			],
			'an incremental sync mode' => [
				['syncMode' => 'incremental'],
				'syncMode "incremental"',
			],
			'a hash computed through a mapping' => [
				['sourceHashMapping' => 'tender-hash'],
				'sourceHashMapping',
			],
			'a reverse mapping' => [
				['targetSourceMapping' => 'tender-to-tenderned'],
				'targetSourceMapping',
			],
			'per-pass conditions' => [
				['conditions' => [['==' => ['status', 'open']]]],
				'conditions',
			],
			'rules applied during the sync' => [
				['actions' => ['enrich-tender']],
				'actions',
			],
			'chained synchronizations' => [
				['followUps' => ['tender-documents']],
				'followUps',
			],
			'nested sub-objects' => [
				['sourceConfig' => ['subObjects' => ['lots' => []]]],
				'sourceConfig.subObjects',
			],
			'an approval gate' => [
				['sourceConfig' => ['requiresApproval' => true]],
				'sourceConfig.requiresApproval',
			],
			'origin-id rewriting' => [
				['sourceConfig' => ['originIdsToReplace' => ['a' => 'b']]],
				'sourceConfig.originIdsToReplace',
			],
			'target-id rewriting before rules' => [
				['sourceConfig' => ['idsToReplaceWithTargetIdsBeforeRules' => ['a' => 'b']]],
				'sourceConfig.idsToReplaceWithTargetIdsBeforeRules',
			],
			'no mapping to enumerate' => [
				['sourceTargetMapping' => ''],
				'sourceTargetMapping is not set',
			],
		];

	}//end refusalProvider()

	/**
	 * Every unsupported feature is refused by name, and nothing is generated.
	 *
	 * @param array $overrides The synchronization values under test.
	 * @param string $fragment The sentence fragment the refusal must name.
	 *
	 * @dataProvider refusalProvider
	 *
	 * @return void
	 */
	public function testUnsupportedFeaturesAreRefusedByName(array $overrides, string $fragment): void {
		$synchronization = $this->synchronization(overrides: $overrides);

		$reasons = $this->generator->refusalsFor(synchronization: $synchronization);
		$this->assertNotSame([], $reasons);
		$this->assertStringContainsString($fragment, implode("\n", $reasons));

		$this->expectException(SynchronizationNotMigratableException::class);
		$this->generator->generateFrom(synchronization: $synchronization);

	}//end testUnsupportedFeaturesAreRefusedByName()

	/**
	 * A migratable synchronization produces no refusals at all.
	 *
	 * The positive control for the whole refusal surface: without it, a guard
	 * that fired on everything would look exactly like one that works.
	 *
	 * @return void
	 */
	public function testAMigratableSynchronizationIsNotRefused(): void {
		$this->assertSame([], $this->generator->refusalsFor(synchronization: $this->synchronization()));

	}//end testAMigratableSynchronizationIsNotRefused()

	/**
	 * A mapping that passes unmapped fields through cannot be enumerated.
	 *
	 * @return void
	 */
	public function testAPassThroughMappingIsRefused(): void {
		$this->mappingService = $this->createMock(MappingService::class);
		$this->mappingService->method('getMapping')->willReturn(
			$this->mappingDouble(overrides: ['passThrough' => true])
		);
		$this->generator = new SynchronizationFlowGenerator(
			synchronizationService: $this->synchronizationService,
			mappingService: $this->mappingService,
			l10n: $this->l10n
		);

		$reasons = $this->generator->refusalsFor(synchronization: $this->synchronization());

		$this->assertStringContainsString('passes unmapped fields through', implode("\n", $reasons));

	}//end testAPassThroughMappingIsRefused()

	/**
	 * A mapping whose every output key is unset leaves nothing to write.
	 *
	 * @return void
	 */
	public function testAMappingWithNoRemainingFieldsIsRefused(): void {
		$this->mappingService = $this->createMock(MappingService::class);
		$this->mappingService->method('getMapping')->willReturn(
			$this->mappingDouble(overrides: ['mapping' => ['title' => '{{ t }}'], 'unset' => ['title']])
		);
		$this->generator = new SynchronizationFlowGenerator(
			synchronizationService: $this->synchronizationService,
			mappingService: $this->mappingService,
			l10n: $this->l10n
		);

		$reasons = $this->generator->refusalsFor(synchronization: $this->synchronization());

		$this->assertStringContainsString('no output field left to write', implode("\n", $reasons));

	}//end testAMappingWithNoRemainingFieldsIsRefused()

	/**
	 * A mapping that cannot be read is refused, naming the underlying failure.
	 *
	 * @return void
	 */
	public function testAnUnreadableMappingIsRefused(): void {
		$this->mappingService = $this->createMock(MappingService::class);
		$this->mappingService->method('getMapping')->willThrowException(
			new RuntimeException('mapping tenderned-to-tender does not exist')
		);
		$this->generator = new SynchronizationFlowGenerator(
			synchronizationService: $this->synchronizationService,
			mappingService: $this->mappingService,
			l10n: $this->l10n
		);

		$reasons = $this->generator->refusalsFor(synchronization: $this->synchronization());

		$this->assertStringContainsString('does not exist', implode("\n", $reasons));

	}//end testAnUnreadableMappingIsRefused()

	/**
	 * The refusal carries its reasons as data, not only in the sentence.
	 *
	 * @return void
	 */
	public function testTheRefusalCarriesItsReasons(): void {
		try {
			$this->generator->generateFrom(
				synchronization: $this->synchronization(overrides: ['sourceType' => 'database'])
			);
			$this->fail('An unsupported source type must be refused.');
		} catch (SynchronizationNotMigratableException $refusal) {
			$this->assertNotSame([], $refusal->getReasons());
			$this->assertStringContainsString('1 unsupported feature', $refusal->getMessage());
		}

	}//end testTheRefusalCarriesItsReasons()

	/**
	 * A synchronization without a name is still labelled, by its reference.
	 *
	 * @return void
	 */
	public function testAnUnnamedSynchronizationIsLabelledByItsReference(): void {
		$flow = $this->generator->generateFrom(
			synchronization: $this->synchronization(overrides: ['name' => ''])
		);

		$this->assertStringContainsString('e5f2b0d0-0000-4000-8000-000000000001', $flow['name']);

	}//end testAnUnnamedSynchronizationIsLabelledByItsReference()

	/**
	 * Naming a synchronization by reference reads it and generates its flow.
	 *
	 * @return void
	 */
	public function testGenerateForReadsTheSynchronization(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('e5f2b0d0-0000-4000-8000-000000000001');
		$entity->setObject($this->synchronization());

		$this->synchronizationService->expects($this->once())
			->method('getSynchronization')
			->with('tenderned-datasets')
			->willReturn($entity);

		$flow = $this->generator->generateFor(reference: ' tenderned-datasets ');

		$this->assertFalse($flow['enabled']);

	}//end testGenerateForReadsTheSynchronization()

	/**
	 * An empty reference is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceIsRefused(): void {
		$this->synchronizationService->expects($this->never())->method('getSynchronization');

		$this->expectException(SynchronizationNotMigratableException::class);
		$this->expectExceptionMessage('A synchronization reference is required.');

		$this->generator->generateFor(reference: '   ');

	}//end testAnEmptyReferenceIsRefused()

	/**
	 * A synchronization that cannot be read is refused, not guessed at.
	 *
	 * @return void
	 */
	public function testAnUnreadableSynchronizationIsRefused(): void {
		$this->synchronizationService->method('getSynchronization')
			->willThrowException(new RuntimeException('no such synchronization'));

		try {
			$this->generator->generateFor(reference: 'ghost');
			$this->fail('An unreadable synchronization must be refused.');
		} catch (SynchronizationNotMigratableException $refusal) {
			$this->assertSame(['no such synchronization'], $refusal->getReasons());
			$this->assertInstanceOf(RuntimeException::class, $refusal->getPrevious());
		}

	}//end testAnUnreadableSynchronizationIsRefused()
}//end class
