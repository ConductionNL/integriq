<?php

/**
 * Integriq Job → Flow migration generator.
 *
 * Task 3.3 of `flow-native-synchronization` ("Jobs → trigger-schedule flows"):
 * renders an existing Job entity into a GENERATED FLOW DOCUMENT whose start node
 * is `openregister.trigger-schedule`, followed by the one step that does what
 * the job's action does.
 *
 * It writes a document and nothing else. It creates no flow, deletes no job,
 * touches no `oc_jobs` row, and never enables anything: `enabled` is `false` on
 * every document it produces, exactly like `SynchronizationFlowGenerator`.
 *
 * WHAT IT EMITS
 * -------------
 *   openregister.trigger-schedule       (cron, derived from the job's interval)
 *     → openconnector.synchronization-run   (SynchronizationAction jobs)
 *       or openregister.sub-flow            (FlowAction jobs)
 *     → openregister.end
 *
 * THE ITEM SHAPE AT EACH STEP
 * ---------------------------
 * A scheduled run is queued by `FlowScheduleService::fire()` with an EMPTY
 * subject, and `FlowItems::fromSubject()` turns any subject into exactly ONE
 * item. So the step after the trigger is handed a single item whose `json` is
 * empty — which is what makes this shape safe: `SynchronizationRunNode::execute()`
 * returns `[]` for an empty item list, so a step reached with zero items would
 * run the synchronization ZERO times while reporting success. One item means it
 * runs exactly once, which is what the job did. It then emits one item per
 * synchronised object, and `openregister.end` accepts any list, so nothing
 * downstream depends on that count. `openregister.sub-flow` with `fanOut: false`
 * likewise invokes the child flow ONCE for the whole item list — matching
 * `FlowAction`, which runs the flow once per job tick, not once per anything.
 *
 * A JOB HAS NO CRON EXPRESSION
 * ----------------------------
 * The job schema carries `interval` — a number of SECONDS — and `JobService`
 * computes `nextRun = now + interval` AFTER each run: a relative cadence that
 * drifts by however long the run took. `openregister.trigger-schedule` takes a
 * five-field cron: an ABSOLUTE wall-clock cadence. The two only coincide when
 * the interval divides the hour (or the day) evenly, so the generator translates
 * exactly those intervals and REFUSES the rest by name. Rounding 7 minutes to 5
 * or 10 would change how often the job runs, silently, which is the one thing a
 * migration generator must never do.
 *
 * KNOWN DEVIATIONS FROM THE LEGACY RUNNER (deliberate, not hidden)
 * ---------------------------------------------------------------
 *  - `allowParallelRuns` is NOT a refusal even when true. Nothing reads it:
 *    `JobService` never looks at it, and `JobTask` hard-codes
 *    `setAllowParallelRuns(false)`, so the legacy runner is already singleton.
 *    `FlowScheduleService` is singleton too (it skips a due flow whose previous
 *    run has not finished), so the generated flow behaves the same. Refusing
 *    here would block a migration to preserve behaviour that does not exist.
 *  - `timeSensitive` is likewise inert in `JobService`; the poll cadence is
 *    `JobTask`'s fixed 300s either way, and `FlowScheduleWorker` also ticks at
 *    300s. Same resolution, so no refusal.
 *  - The generated document sets `trigger: schedule` and `cron` at the TOP
 *    level as well as on the trigger node. `FlowScheduleService::scheduleOf()`
 *    reads the flow's own columns, not the node's config, so a document that
 *    only configured the node would validate, save, and never fire.
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
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Flow\SynchronizationRunNode;
use OCP\IL10N;

/**
 * Renders a Job into a disabled, reviewable trigger-schedule flow document.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#3-migration--deprecation
 */
class JobToFlowGenerator {

	/**
	 * The item key the synchronization result is written under.
	 *
	 * @var string
	 */
	public const KEY_SYNC_RESULT = 'syncResult';

	/**
	 * The job action that runs a synchronization.
	 *
	 * @var string
	 */
	private const ACTION_SYNCHRONIZATION = 'OCA\Integriq\Action\SynchronizationAction';

	/**
	 * The job action that runs a flow.
	 *
	 * @var string
	 */
	private const ACTION_FLOW = 'OCA\Integriq\Action\FlowAction';

	/**
	 * The job action that pings a source.
	 *
	 * @var string
	 */
	private const ACTION_PING = 'OCA\Integriq\Action\PingAction';

	/**
	 * The job action that emits a CloudEvent.
	 *
	 * @var string
	 */
	private const ACTION_EVENT = 'OCA\Integriq\Action\EventAction';

	/**
	 * The job arguments each supported action's node actually consumes.
	 *
	 * `jobId` is on every list: `JobService::scheduleJob()` injects it into the
	 * arguments it hands the background job, so a stored copy names the job
	 * itself — which the generated flow already records in its description.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CONSUMED_ARGUMENTS = [
		self::ACTION_SYNCHRONIZATION => ['jobId', 'synchronizationId', 'force'],
		self::ACTION_FLOW => ['jobId', 'flowId'],
	];

	/**
	 * Constructor.
	 *
	 * @param MigrationEntityReader $reader Reads the Job entity out of OpenRegister.
	 * @param JobIntervalCron $cadence Translates the job's interval into a cron expression.
	 * @param MigrationSubject $subject Reads the job's identity for names and traceability.
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(
		private readonly MigrationEntityReader $reader,
		private readonly JobIntervalCron $cadence,
		private readonly MigrationSubject $subject,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * Generate the flow document for a job named by reference.
	 *
	 * @param string $reference The job's uuid, slug or reference.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When the job cannot be read, or uses a
	 *                                      feature the flow vocabulary cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFor(string $reference): array {
		return $this->generateFrom(
			job: $this->reader->read(reference: $reference, schema: 'job', subject: 'job')
		);

	}//end generateFor()

	/**
	 * Generate the flow document for an already-read job.
	 *
	 * Nothing is persisted: the caller decides what to do with the document, and
	 * the document itself is disabled.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When the job uses a feature the flow
	 *                                      vocabulary cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFrom(array $job): array {
		$reasons = $this->refusalsFor(job: $job);
		if ($reasons !== []) {
			throw new EntityNotMigratableException(
				subject: 'job',
				message: $this->l10n->t(
					'The job "%1$s" cannot be migrated to a flow yet: %2$s unsupported feature(s).',
					[$this->subject->labelOf(entity: $job), (string)count($reasons)]
				),
				reasons: $reasons
			);
		}

		$reference = $this->subject->referenceOf(entity: $job);
		$cron = (string)$this->cadence->cronFor(seconds: $this->intervalOf(job: $job));
		$nodes = $this->nodesFor(job: $job, cron: $cron);

		return [
			'name' => $this->l10n->t('%1$s (generated from job)', [$this->subject->labelOf(entity: $job)]),
			'description' => $this->describe(job: $job, reference: $reference, cron: $cron),
			'enabled' => false,
			'trigger' => 'schedule',
			'cron' => $cron,
			'nodes' => $nodes,
			'edges' => $this->edgesFor(nodes: $nodes),
		];

	}//end generateFrom()

	/**
	 * Every feature of this job the flow vocabulary cannot express.
	 *
	 * An empty list means the job is migratable. A non-empty one is the refusal:
	 * emitting a flow anyway would replace a scheduled action with silence.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $job): array {
		return array_merge(
			$this->identityRefusals(job: $job),
			$this->scheduleRefusals(job: $job),
			$this->actionRefusals(job: $job)
		);

	}//end refusalsFor()

	/**
	 * Refusals about naming the job at all.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function identityRefusals(array $job): array {
		if ($this->subject->referenceOf(entity: $job) !== '') {
			return [];
		}

		return [
			$this->l10n->t(
				'The job carries no uuid, slug or reference, so the generated flow could not record what it '
				. 'was generated from and nobody could trace it back.'
			),
		];

	}//end identityRefusals()

	/**
	 * Refusals about WHEN the job runs.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function scheduleRefusals(array $job): array {
		$reasons = [];
		$interval = $this->intervalOf(job: $job);

		if ($interval <= 0) {
			$reasons[] = $this->l10n->t(
				'interval "%1$s": a schedule trigger needs a cadence, and a job with no positive interval '
				. 'has none to translate.',
				[(string)($job['interval'] ?? '')]
			);
		}

		if ($interval > 0 && $this->cadence->cronFor(seconds: $interval) === null) {
			$reasons[] = $this->l10n->t(
				'interval %1$s seconds: a five-field cron is absolute wall-clock time, so only an interval '
				. 'that divides the hour or the day evenly is the same cadence. The expressible intervals '
				. 'are: %2$s. Rounding to the nearest one would change how often this job runs.',
				[
					(string)$interval,
					implode(', ', array_map('strval', $this->cadence->expressibleIntervals())),
				]
			);
		}

		if (trim((string)($job['scheduleAfter'] ?? '')) !== '') {
			$reasons[] = $this->l10n->t(
				'scheduleAfter "%1$s": a cron expression has no "not before" bound, so a delayed-start job '
				. 'would begin firing on the next tick instead.',
				[trim((string)$job['scheduleAfter'])]
			);
		}

		if ($this->isSingleRun(job: $job) === true) {
			$reasons[] = $this->l10n->t(
				'singleRun: a schedule trigger fires for as long as the flow is enabled; there is no '
				. '"run once and disable myself" in the cron vocabulary.'
			);
		}

		if (trim((string)($job['userId'] ?? '')) !== '') {
			$reasons[] = $this->l10n->t(
				'userId "%1$s": a scheduled flow runs as its OWNER — the account that creates it — and a '
				. 'generated document cannot set an owner, so the run identity would silently change.',
				[trim((string)$job['userId'])]
			);
		}

		return $reasons;
	}//end scheduleRefusals()

	/**
	 * Refusals about WHAT the job does.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function actionRefusals(array $job): array {
		$jobClass = $this->jobClassOf(job: $job);

		if ($jobClass === '') {
			return [
				$this->l10n->t('jobClass is not set, so there is no action for a flow step to stand in for.'),
			];
		}

		if ($jobClass === self::ACTION_PING) {
			return [
				$this->l10n->t(
					'jobClass "%1$s": a ping calls the Source\'s own location with no path, and '
					. '"openconnector.source-call" refuses an empty "endpoint" — it only calls a path '
					. 'RELATIVE to the source, so there is no step that reproduces a bare ping.',
					[$jobClass]
				),
			];
		}

		if ($jobClass === self::ACTION_EVENT) {
			return [
				$this->l10n->t(
					'jobClass "%1$s": no flow node emits a CloudEvent onto the event bus, so a generated '
					. 'flow would run green and deliver nothing.',
					[$jobClass]
				),
			];
		}

		if (isset(self::CONSUMED_ARGUMENTS[$jobClass]) === false) {
			return [
				$this->l10n->t(
					'jobClass "%1$s": no flow node stands in for this action. Only "%2$s" and "%3$s" have '
					. 'decomposed equivalents.',
					[$jobClass, self::ACTION_SYNCHRONIZATION, self::ACTION_FLOW]
				),
			];
		}

		return array_merge(
			$this->targetRefusals(job: $job, jobClass: $jobClass),
			$this->argumentRefusals(job: $job, jobClass: $jobClass)
		);

	}//end actionRefusals()

	/**
	 * Refusals about the thing the action is pointed at.
	 *
	 * @param array $job The job's serialised record.
	 * @param string $jobClass The normalised job action class.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function targetRefusals(array $job, string $jobClass): array {
		$arguments = $this->argumentsOf(job: $job);

		if ($jobClass === self::ACTION_SYNCHRONIZATION
			&& trim((string)($arguments['synchronizationId'] ?? '')) === ''
		) {
			return [
				$this->l10n->t(
					'arguments.synchronizationId is not set: "openconnector.synchronization-run" must name a '
					. 'configured synchronization, and this step never creates one.'
				),
			];
		}

		if ($jobClass === self::ACTION_FLOW && trim((string)($arguments['flowId'] ?? '')) === '') {
			return [
				$this->l10n->t(
					'arguments.flowId is not set: "openregister.sub-flow" needs a flow to run.'
				),
			];
		}

		return [];
	}//end targetRefusals()

	/**
	 * Refusals about arguments no generated step would read.
	 *
	 * @param array $job The job's serialised record.
	 * @param string $jobClass The normalised job action class.
	 *
	 * @return array<int, string> The refusal reasons.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function argumentRefusals(array $job, string $jobClass): array {
		$unread = array_diff(
			array_keys($this->argumentsOf(job: $job)),
			self::CONSUMED_ARGUMENTS[$jobClass]
		);
		if ($unread === []) {
			return [];
		}

		return [
			$this->l10n->t(
				'arguments %1$s: nothing in the generated flow reads them, and an argument the action used '
				. 'but the step ignores is exactly a flow that does less while reporting success.',
				[implode(', ', array_map('strval', $unread))]
			),
		];

	}//end argumentRefusals()

	/**
	 * Build the flow's nodes, in pipeline order.
	 *
	 * @param array $job The job's serialised record.
	 * @param string $cron The cron expression derived from the job's interval.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function nodesFor(array $job, string $cron): array {
		return [
			[
				'id' => 'trigger',
				'type' => 'openregister.trigger-schedule',
				'config' => ['cron' => $cron],
			],
			$this->actionNodeFor(job: $job),
			['id' => 'end', 'type' => 'openregister.end', 'config' => []],
		];

	}//end nodesFor()

	/**
	 * The one step that stands in for the job's action.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array<string, mixed> The node.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function actionNodeFor(array $job): array {
		$arguments = $this->argumentsOf(job: $job);

		if ($this->jobClassOf(job: $job) === self::ACTION_FLOW) {
			return [
				'id' => 'run',
				'type' => 'openregister.sub-flow',
				'config' => [
					'flowId' => trim((string)$arguments['flowId']),
					// The job action waits for the run and reports its status,
					// so the step has to wait too — "start it and carry on"
					// would turn a failure into a success.
					'wait' => true,
					// One child run for the whole (single) item, matching a job
					// tick that runs the flow once rather than once per object.
					'fanOut' => false,
				],
			];
		}

		$config = [
			'synchronization' => trim((string)$arguments['synchronizationId']),
			'output' => self::KEY_SYNC_RESULT,
		];

		if (array_key_exists('force', $arguments) === true) {
			$config['force'] = filter_var($arguments['force'], FILTER_VALIDATE_BOOLEAN);
		}

		return [
			'id' => 'run',
			'type' => SynchronizationRunNode::NODE_ID,
			'config' => $config,
		];

	}//end actionNodeFor()

	/**
	 * Chain the nodes together, one edge per consecutive pair.
	 *
	 * @param array $nodes The flow's nodes, in pipeline order.
	 *
	 * @return array<int, array<string, string>> The edges.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function edgesFor(array $nodes): array {
		$ids = array_map(static fn (array $node): string => (string)$node['id'], array_values($nodes));

		$edges = [];
		$total = count($ids);
		for ($index = 0; $index < ($total - 1); $index++) {
			$from = $ids[$index];
			$to = $ids[($index + 1)];
			$edges[] = [
				'id' => $from . '-' . $to,
				'from' => $from,
				'to' => $to,
			];
		}

		return $edges;
	}//end edgesFor()

	/**
	 * The description that lets a human trace the flow back to its job.
	 *
	 * @param array $job The job's serialised record.
	 * @param string $reference The job's uuid, slug or reference.
	 * @param string $cron The cron expression derived from the job's interval.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function describe(array $job, string $reference, string $cron): string {
		return $this->l10n->t(
			'Generated from job "%1$s" (%2$s) by the flow-native-synchronization migration. It is disabled '
			. 'until a human has reviewed it, and the job it was generated from is untouched — enable one or '
			. 'the other, never both. The job\'s %3$s-second interval became the cron "%4$s": the job '
			. 'measured its next run from the END of the previous one, this cron measures from the clock, so '
			. 'a long run no longer pushes the next one back. "allowParallelRuns" and "timeSensitive" are not '
			. 'carried across because nothing reads them: JobTask is already singleton and already polls '
			. 'every 300 seconds, which is also the flow schedule worker\'s tick.',
			[$this->subject->labelOf(entity: $job), $reference, (string)$this->intervalOf(job: $job), $cron]
		);

	}//end describe()

	/**
	 * The job's interval in whole seconds.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return integer The interval; 0 or less when there is none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function intervalOf(array $job): int {
		return $this->cadence->secondsOf(interval: ($job['interval'] ?? null));
	}//end intervalOf()

	/**
	 * Whether the job disables itself after one successful run.
	 *
	 * BOTH spellings are read on purpose. The `job` schema declares the property
	 * as `singleRun`, while `JobService::executeJob()` reads `isSingleRun` — so
	 * on today's data the flag never actually fires. A generator that trusted
	 * one spelling would migrate a one-shot job into a flow that runs forever,
	 * which is the failure this whole class exists to prevent.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return boolean Whether this is a one-shot job.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function isSingleRun(array $job): bool {
		foreach (['singleRun', 'isSingleRun'] as $key) {
			if (filter_var(($job[$key] ?? false), FILTER_VALIDATE_BOOLEAN) === true) {
				return true;
			}
		}

		return false;
	}//end isSingleRun()

	/**
	 * The job's action class, normalised to a leading-backslash-free name.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return string The class name; empty when there is none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function jobClassOf(array $job): string {
		return ltrim(trim((string)($job['jobClass'] ?? '')), '\\');
	}//end jobClassOf()

	/**
	 * The job's arguments, always as an array.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array<string, mixed> The arguments.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function argumentsOf(array $job): array {
		$arguments = ($job['arguments'] ?? []);
		if (is_array($arguments) === false) {
			return [];
		}

		return $arguments;
	}//end argumentsOf()
}//end class
