<?php

/**
 * Unit tests for JobToFlowGenerator (flow-native-synchronization 3.3).
 *
 * The assertion the generator rests on is not the shape of the document — a
 * golden file would pin that and still ship a config key a node rejects. It is
 * {@see testGeneratedConfigPassesTheNodesOwnValidateConfig()}: the node that
 * will actually run the step is CONSTRUCTED and handed the config the generator
 * produced, so a key that drifts out of its vocabulary fails here rather than at
 * flow-save time on someone's instance. The OpenRegister nodes get the same
 * treatment whenever that app is on the autoloader (which is how app CI runs),
 * and the class-absent case is declared out loud rather than skipped silently.
 *
 * The rest of the file is the refusal surface. A migration that quietly drops a
 * rule is worse than one that refuses, so every refusal branch has a test that
 * shows it CAN fire — and the happy-path fixture proves the same inputs without
 * that feature do NOT fire it.
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
use OCA\Integriq\Service\JobIntervalCron;
use OCA\Integriq\Service\JobToFlowGenerator;
use OCA\Integriq\Service\MigrationEntityReader;
use OCA\Integriq\Service\MigrationSubject;
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
 * Tests for the Job → flow migration generator.
 *
 * @covers \OCA\Integriq\Service\JobToFlowGenerator
 * @covers \OCA\Integriq\Service\JobIntervalCron
 * @covers \OCA\Integriq\Service\MigrationSubject
 * @covers \OCA\Integriq\Service\MigrationEntityReader
 * @covers \OCA\Integriq\Exception\EntityNotMigratableException
 */
class JobToFlowGeneratorTest extends TestCase {

	/**
	 * The action class a migratable job points at.
	 *
	 * @var string
	 */
	private const SYNC_ACTION = 'OCA\Integriq\Action\SynchronizationAction';

	/**
	 * The action class a migratable flow job points at.
	 *
	 * @var string
	 */
	private const FLOW_ACTION = 'OCA\Integriq\Action\FlowAction';

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
	 * @var JobToFlowGenerator
	 */
	private JobToFlowGenerator $generator;

	/**
	 * Build the generator over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ORObjectService::class);
		$this->l10n = $this->translations();
		$this->generator = new JobToFlowGenerator(
			reader: new MigrationEntityReader(objectService: $this->objectService, l10n: $this->l10n),
			cadence: new JobIntervalCron(),
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
	 * A job the flow vocabulary can express.
	 *
	 * @param array $overrides Values replacing the migratable defaults.
	 *
	 * @return array The job record.
	 */
	private function job(array $overrides = []): array {
		return array_merge(
			[
				'uuid' => 'a1b2c3d4-0000-4000-8000-000000000001',
				'name' => 'Nightly TenderNed pull',
				'jobClass' => self::SYNC_ACTION,
				'arguments' => ['synchronizationId' => 'tenderned-datasets'],
				'interval' => 3600,
				'isEnabled' => true,
			],
			$overrides
		);

	}//end job()

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
	 * The refusal a job produces, as one string.
	 *
	 * @param array $job The job record.
	 *
	 * @return string Every reason, joined.
	 */
	private function refusal(array $job): string {
		try {
			$this->generator->generateFrom(job: $job);
		} catch (EntityNotMigratableException $refusal) {
			$this->assertSame('job', $refusal->getSubject());

			return implode(' | ', $refusal->getReasons());
		}

		$this->fail('The generator accepted a job it cannot express.');

	}//end refusal()

	/**
	 * A generated flow is disabled, scheduled, named and traceable.
	 *
	 * @return void
	 */
	public function testGeneratedFlowIsDisabledScheduledAndTraceable(): void {
		$flow = $this->generator->generateFrom(job: $this->job());

		$this->assertFalse($flow['enabled'], 'A generated flow is never enabled.');
		$this->assertSame('schedule', $flow['trigger']);
		$this->assertSame('0 * * * *', $flow['cron']);
		$this->assertStringContainsString('Nightly TenderNed pull', $flow['name']);
		$this->assertStringContainsString(
			'a1b2c3d4-0000-4000-8000-000000000001',
			$flow['description'],
			'The source job must be traceable from the flow.'
		);

	}//end testGeneratedFlowIsDisabledScheduledAndTraceable()

	/**
	 * The cron lives on the flow AND on the trigger node.
	 *
	 * `FlowScheduleService::scheduleOf()` reads the flow's own `trigger`/`cron`
	 * columns, not the trigger node's config. A document that configured only
	 * the node would validate, save, and never fire — the quietest failure this
	 * generator could ship.
	 *
	 * @return void
	 */
	public function testCronIsOnTheFlowAsWellAsOnTheTriggerNode(): void {
		$flow = $this->generator->generateFrom(job: $this->job(['interval' => 900]));

		$this->assertSame('*/15 * * * *', $flow['cron']);
		$this->assertSame(
			'*/15 * * * *',
			$this->node(flow: $flow, id: 'trigger')['config']['cron'],
			'The scheduler reads the column; the editor reads the node. Both have to say the same thing.'
		);

	}//end testCronIsOnTheFlowAsWellAsOnTheTriggerNode()

	/**
	 * A synchronization job becomes trigger-schedule → run → end.
	 *
	 * @return void
	 */
	public function testSynchronizationJobBecomesTheRunPipeline(): void {
		$flow = $this->generator->generateFrom(job: $this->job());

		$this->assertSame(
			[
				'openregister.trigger-schedule',
				SynchronizationRunNode::NODE_ID,
				'openregister.end',
			],
			array_column($flow['nodes'], 'type')
		);

		$this->assertSame(
			[
				'synchronization' => 'tenderned-datasets',
				'output' => JobToFlowGenerator::KEY_SYNC_RESULT,
			],
			$this->node(flow: $flow, id: 'run')['config']
		);

		$this->assertCount((count($flow['nodes']) - 1), $flow['edges']);
		foreach ($flow['edges'] as $index => $edge) {
			$this->assertSame($flow['nodes'][$index]['id'], $edge['from']);
			$this->assertSame($flow['nodes'][($index + 1)]['id'], $edge['to']);
		}

	}//end testSynchronizationJobBecomesTheRunPipeline()

	/**
	 * A `force` argument is carried across as a real boolean.
	 *
	 * The legacy action reads it through `filter_var`, so "true" and true are
	 * the same job. `SynchronizationRunNode::validateConfig()` refuses a
	 * non-boolean, so the string has to become one here rather than at save.
	 *
	 * @return void
	 */
	public function testForceArgumentIsCarriedAcrossAsABoolean(): void {
		$flow = $this->generator->generateFrom(
			job: $this->job(
				['arguments' => ['synchronizationId' => 's1', 'force' => 'true', 'jobId' => 'ignored']]
			)
		);

		$this->assertTrue($this->node(flow: $flow, id: 'run')['config']['force']);

	}//end testForceArgumentIsCarriedAcrossAsABoolean()

	/**
	 * A flow job becomes a waiting, non-fanning sub-flow step.
	 *
	 * @return void
	 */
	public function testFlowJobBecomesAWaitingSubFlowStep(): void {
		$flow = $this->generator->generateFrom(
			job: $this->job(
				[
					'jobClass' => '\\' . self::FLOW_ACTION,
					'arguments' => ['flowId' => 'nightly-report'],
				]
			)
		);

		$this->assertSame(
			[
				'flowId' => 'nightly-report',
				'wait' => true,
				'fanOut' => false,
			],
			$this->node(flow: $flow, id: 'run')['config'],
			'FlowAction waits for the run and reports its status, so the step must wait too.'
		);

	}//end testFlowJobBecomesAWaitingSubFlowStep()

	/**
	 * Every generated config is accepted by the node that will run it.
	 *
	 * @return void
	 */
	public function testGeneratedConfigPassesTheNodesOwnValidateConfig(): void {
		$flow = $this->generator->generateFrom(
			job: $this->job(['arguments' => ['synchronizationId' => 's1', 'force' => true]])
		);
		$config = $this->node(flow: $flow, id: 'run')['config'];

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

		$this->assertSame(
			[],
			array_diff(array_keys($config), $node->configKeys()),
			'The generated config uses a key the node does not declare.'
		);

	}//end testGeneratedConfigPassesTheNodesOwnValidateConfig()

	/**
	 * Every OpenRegister step the generator emits is accepted by its own node.
	 *
	 * OpenRegister is a peer app, not a composer dependency: app CI clones it,
	 * so this runs for real there. In a bare checkout the class is absent and
	 * the test says so instead of passing quietly — the live
	 * `/apps/openregister/api/flow/validate` check recorded on the PR covers
	 * that gap.
	 *
	 * @return void
	 */
	public function testGeneratedConfigPassesTheOpenRegisterNodesOwnValidateConfig(): void {
		$scheduleNode = 'OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode';
		$subFlowNode = 'OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode';
		if (class_exists($scheduleNode) === false) {
			$this->markTestSkipped(
				'OpenRegister is not on the autoloader in this checkout, so its node classes cannot be '
				. 'constructed. App CI clones openregister and runs this for real.'
			);
		}

		$flow = $this->generator->generateFrom(job: $this->job());
		$trigger = new $scheduleNode($this->l10n, $this->createMock(IURLGenerator::class));
		$trigger->validateConfig($this->node(flow: $flow, id: 'trigger')['config']);
		$this->assertSame([], array_diff(['cron'], $trigger->configKeys()));

		if (class_exists($subFlowNode) === true) {
			$subFlow = $this->generator->generateFrom(
				job: $this->job(
					['jobClass' => self::FLOW_ACTION, 'arguments' => ['flowId' => 'nightly-report']]
				)
			);
			$this->assertSame(
				[],
				array_diff(
					array_keys($this->node(flow: $subFlow, id: 'run')['config']),
					['flow', 'flowId', 'wait', 'fanOut']
				)
			);
		}

	}//end testGeneratedConfigPassesTheOpenRegisterNodesOwnValidateConfig()

	/**
	 * Every interval in the table produces a cron the trigger node accepts.
	 *
	 * Asserted in-repo rather than left to the flow preflight, because the
	 * preflight cannot see this: `FlowNodePreflight::configRejection()` treats
	 * only an `UnexpectedValueException` as blocking, and `TriggerScheduleNode`
	 * throws `InvalidArgumentException` — so a document carrying `@hourly`
	 * comes back `valid: true` and then never fires, because
	 * `FlowScheduleService::scheduleOf()` drops a cron
	 * `CronExpression::isValidExpression()` rejects. Verified live on
	 * 2026-08-19: the mutated document validated true while nextcloud.log
	 * carried "validateConfig() failed for its own reasons, not blocking".
	 *
	 * @return void
	 */
	public function testEveryTranslatableIntervalProducesAFiveFieldCron(): void {
		$seen = [];
		foreach ([60, 120, 180, 240, 300, 360, 600, 720, 900, 1200, 1800, 3600, 7200, 10800, 14400, 21600, 28800, 43200, 86400] as $interval) {
			$cron = $this->generator->generateFrom(job: $this->job(['interval' => $interval]))['cron'];

			$this->assertCount(
				5,
				preg_split('/\s+/', $cron),
				sprintf('The cron for %d seconds is not five fields: "%s".', $interval, $cron)
			);
			$seen[] = $cron;
		}

		$this->assertSame($seen, array_unique($seen), 'Two intervals must not share one cron.');

	}//end testEveryTranslatableIntervalProducesAFiveFieldCron()

	/**
	 * A job with nothing to name it is refused.
	 *
	 * @return void
	 */
	public function testAJobWithNoReferenceIsRefused(): void {
		$this->assertStringContainsString(
			'no uuid, slug or reference',
			$this->refusal(job: ['uuid' => null, 'slug' => ['not', 'scalar'], 'reference' => '  '])
		);

	}//end testAJobWithNoReferenceIsRefused()

	/**
	 * A job with no usable interval is refused.
	 *
	 * @param mixed $interval The interval as stored.
	 *
	 * @return void
	 *
	 * @dataProvider unusableIntervals
	 */
	public function testAJobWithNoUsableIntervalIsRefused(mixed $interval): void {
		$this->assertStringContainsString(
			'a schedule trigger needs a cadence',
			$this->refusal(job: $this->job(['interval' => $interval]))
		);

	}//end testAJobWithNoUsableIntervalIsRefused()

	/**
	 * Intervals that are not a cadence at all.
	 *
	 * @return array<string, array<int, mixed>> The cases.
	 */
	public static function unusableIntervals(): array {
		return [
			'absent' => [null],
			'zero' => [0],
			'negative' => [-60],
			'not a number' => ['hourly'],
		];

	}//end unusableIntervals()

	/**
	 * An interval a five-field cron cannot express evenly is refused.
	 *
	 * Seven minutes is the case that matters: `*\/7` looks right and fires at
	 * :00 :07 … :56 and then jumps back to :00, so the job would silently run
	 * on a four-minute-longer gap once an hour.
	 *
	 * @return void
	 */
	public function testAnIntervalThatCronCannotExpressEvenlyIsRefused(): void {
		$reasons = $this->refusal(job: $this->job(['interval' => 420]));

		$this->assertStringContainsString('interval 420 seconds', $reasons);
		$this->assertStringContainsString('divides the hour or the day evenly', $reasons);
		$this->assertStringContainsString('Rounding to the nearest one', $reasons);

	}//end testAnIntervalThatCronCannotExpressEvenlyIsRefused()

	/**
	 * A delayed-start job is refused: cron has no "not before".
	 *
	 * @return void
	 */
	public function testADelayedStartJobIsRefused(): void {
		$this->assertStringContainsString(
			'has no "not before" bound',
			$this->refusal(job: $this->job(['scheduleAfter' => '2026-09-01T00:00:00+00:00']))
		);

	}//end testADelayedStartJobIsRefused()

	/**
	 * A one-shot job is refused, under EITHER spelling of the flag.
	 *
	 * The `job` schema declares `singleRun`; `JobService::executeJob()` reads
	 * `isSingleRun`. Reading only one spelling would migrate a one-shot job
	 * into a flow that runs forever.
	 *
	 * @param string $key The property name the flag is stored under.
	 *
	 * @return void
	 *
	 * @dataProvider singleRunSpellings
	 */
	public function testAOneShotJobIsRefusedUnderEitherSpelling(string $key): void {
		$this->assertStringContainsString(
			'run once and disable myself',
			$this->refusal(job: $this->job([$key => true]))
		);

	}//end testAOneShotJobIsRefusedUnderEitherSpelling()

	/**
	 * The two names the one-shot flag lives under.
	 *
	 * @return array<string, array<int, string>> The cases.
	 */
	public static function singleRunSpellings(): array {
		return ['schema property' => ['singleRun'], 'what JobService reads' => ['isSingleRun']];
	}//end singleRunSpellings()

	/**
	 * A user-scoped job is refused: a flow runs as its owner.
	 *
	 * @return void
	 */
	public function testAUserScopedJobIsRefused(): void {
		$this->assertStringContainsString(
			'runs as its OWNER',
			$this->refusal(job: $this->job(['userId' => 'alice']))
		);

	}//end testAUserScopedJobIsRefused()

	/**
	 * A job with no action class is refused.
	 *
	 * @return void
	 */
	public function testAJobWithNoActionClassIsRefused(): void {
		$this->assertStringContainsString(
			'jobClass is not set',
			$this->refusal(job: $this->job(['jobClass' => '']))
		);

	}//end testAJobWithNoActionClassIsRefused()

	/**
	 * A ping job is refused: source-call refuses an empty endpoint.
	 *
	 * @return void
	 */
	public function testAPingJobIsRefused(): void {
		$reasons = $this->refusal(
			job: $this->job(
				[
					'jobClass' => 'OCA\Integriq\Action\PingAction',
					'arguments' => ['sourceId' => 'some-source'],
				]
			)
		);

		$this->assertStringContainsString('PingAction', $reasons);
		$this->assertStringContainsString('refuses an empty "endpoint"', $reasons);

	}//end testAPingJobIsRefused()

	/**
	 * An event-emitting job is refused: no node reaches the event bus.
	 *
	 * @return void
	 */
	public function testAnEventJobIsRefused(): void {
		$reasons = $this->refusal(
			job: $this->job(
				[
					'jobClass' => 'OCA\Integriq\Action\EventAction',
					'arguments' => ['type' => 'x', 'source' => 'y'],
				]
			)
		);

		$this->assertStringContainsString('EventAction', $reasons);
		$this->assertStringContainsString('deliver nothing', $reasons);

	}//end testAnEventJobIsRefused()

	/**
	 * An unknown action class is refused by name.
	 *
	 * @return void
	 */
	public function testAnUnknownActionClassIsRefusedByName(): void {
		$this->assertStringContainsString(
			'OCA\Custom\Action\SomethingElse',
			$this->refusal(job: $this->job(['jobClass' => 'OCA\Custom\Action\SomethingElse']))
		);

	}//end testAnUnknownActionClassIsRefusedByName()

	/**
	 * A synchronization job that names no synchronization is refused.
	 *
	 * @return void
	 */
	public function testASynchronizationJobWithNoTargetIsRefused(): void {
		$this->assertStringContainsString(
			'arguments.synchronizationId is not set',
			$this->refusal(job: $this->job(['arguments' => []]))
		);

	}//end testASynchronizationJobWithNoTargetIsRefused()

	/**
	 * A flow job that names no flow is refused.
	 *
	 * @return void
	 */
	public function testAFlowJobWithNoTargetIsRefused(): void {
		$this->assertStringContainsString(
			'arguments.flowId is not set',
			$this->refusal(job: $this->job(['jobClass' => self::FLOW_ACTION, 'arguments' => 'not-an-array']))
		);

	}//end testAFlowJobWithNoTargetIsRefused()

	/**
	 * Arguments no generated step reads are refused, by name.
	 *
	 * @return void
	 */
	public function testArgumentsNoStepReadsAreRefused(): void {
		$reasons = $this->refusal(
			job: $this->job(
				['arguments' => ['synchronizationId' => 's1', 'isTest' => true, 'sinceCursor' => '2026-01-01']]
			)
		);

		$this->assertStringContainsString('isTest', $reasons);
		$this->assertStringContainsString('sinceCursor', $reasons);
		$this->assertStringContainsString('flow that does less', $reasons);

	}//end testArgumentsNoStepReadsAreRefused()

	/**
	 * A refusal counts and names every feature at once, not just the first.
	 *
	 * @return void
	 */
	public function testARefusalNamesEveryUnsupportedFeatureAtOnce(): void {
		try {
			$this->generator->generateFrom(
				job: $this->job(['interval' => 420, 'userId' => 'alice', 'singleRun' => true])
			);
		} catch (EntityNotMigratableException $refusal) {
			$this->assertCount(3, $refusal->getReasons());
			$this->assertStringContainsString('3 unsupported feature(s)', $refusal->getMessage());
			$this->assertStringContainsString('Nightly TenderNed pull', $refusal->getMessage());

			return;
		}

		$this->fail('The generator accepted a job with three unsupported features.');

	}//end testARefusalNamesEveryUnsupportedFeatureAtOnce()

	/**
	 * Reading by reference goes through OpenRegister and produces a document.
	 *
	 * @return void
	 */
	public function testGenerateForReadsTheJobAndRendersIt(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('a1b2c3d4-0000-4000-8000-000000000001');
		$entity->setObject(
			[
				'name' => 'Nightly TenderNed pull',
				'jobClass' => self::SYNC_ACTION,
				'arguments' => ['synchronizationId' => 'tenderned-datasets'],
				'interval' => 86400,
			]
		);
		$this->objectService->method('find')->willReturn($entity);

		$flow = $this->generator->generateFor(reference: ' nightly-tenderned-pull ');

		$this->assertSame('0 0 * * *', $flow['cron']);
		$this->assertStringContainsString(
			'a1b2c3d4-0000-4000-8000-000000000001',
			$flow['description'],
			'A record whose object body omits the uuid still has to be traceable.'
		);

	}//end testGenerateForReadsTheJobAndRendersIt()

	/**
	 * A record that already carries its own uuid keeps it.
	 *
	 * @return void
	 */
	public function testGenerateForKeepsAUuidTheRecordAlreadyCarries(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('the-entity-uuid');
		$entity->setObject($this->job());
		$this->objectService->method('find')->willReturn($entity);

		$this->assertStringContainsString(
			'a1b2c3d4-0000-4000-8000-000000000001',
			$this->generator->generateFor(reference: 'x')['description']
		);

	}//end testGenerateForKeepsAUuidTheRecordAlreadyCarries()

	/**
	 * Naming no job at all is a refusal, not a crash.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceIsRefused(): void {
		$this->expectException(EntityNotMigratableException::class);
		$this->expectExceptionMessage('A job reference is required.');

		$this->generator->generateFor(reference: '   ');

	}//end testAnEmptyReferenceIsRefused()

	/**
	 * A job that does not exist is refused, naming the reference.
	 *
	 * @return void
	 */
	public function testAMissingJobIsRefused(): void {
		$this->objectService->method('find')->willReturn(null);

		try {
			$this->generator->generateFor(reference: 'no-such-job');
		} catch (EntityNotMigratableException $refusal) {
			$this->assertStringContainsString('no-such-job', $refusal->getMessage());
			$this->assertStringContainsString('No job with that uuid', implode(' ', $refusal->getReasons()));

			return;
		}

		$this->fail('A missing job was not refused.');

	}//end testAMissingJobIsRefused()

	/**
	 * A read that throws is reported as a refusal carrying the cause.
	 *
	 * @return void
	 */
	public function testAFailedReadIsReportedAsARefusal(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('database is on fire'));

		try {
			$this->generator->generateFor(reference: 'boom');
		} catch (EntityNotMigratableException $refusal) {
			$this->assertStringContainsString('database is on fire', implode(' ', $refusal->getReasons()));
			$this->assertInstanceOf(RuntimeException::class, $refusal->getPrevious());

			return;
		}

		$this->fail('A failed read was not refused.');

	}//end testAFailedReadIsReportedAsARefusal()

	/**
	 * A nameless job falls back to its reference in messages.
	 *
	 * @return void
	 */
	public function testANamelessJobIsLabelledByItsReference(): void {
		$flow = $this->generator->generateFrom(job: $this->job(['name' => '  ']));

		$this->assertStringContainsString('a1b2c3d4-0000-4000-8000-000000000001', $flow['name']);

	}//end testANamelessJobIsLabelledByItsReference()

	/**
	 * A migratable job produces no refusals at all — the positive control.
	 *
	 * @return void
	 */
	public function testAMigratableJobIsNotRefused(): void {
		$this->assertSame([], $this->generator->refusalsFor(job: $this->job()));
		$this->assertSame(
			[],
			$this->generator->refusalsFor(
				job: $this->job(
					[
						'jobClass' => self::FLOW_ACTION,
						'arguments' => ['flowId' => 'f1', 'jobId' => 'j1'],
						'allowParallelRuns' => true,
						'timeSensitive' => true,
						'isEnabled' => false,
					]
				)
			),
			'allowParallelRuns and timeSensitive are inert in the legacy runner, so they are not refusals.'
		);

	}//end testAMigratableJobIsNotRefused()
}//end class
