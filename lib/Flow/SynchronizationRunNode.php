<?php

/**
 * Integriq Synchronization Run flow node.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `openconnector.synchronization-run` — runs an ALREADY-CONFIGURED
 * Synchronization as one flow step, and emits ONE ITEM PER SYNCHRONISED OBJECT.
 *
 * WHY A SECOND NODE
 * -----------------
 * `openconnector.source-call` covers "make one request and put the answer on
 * the item". It does not cover Integriq's other governed outbound
 * capability: running a Synchronization — pagination across pages, mapping,
 * contract and state tracking, the SynchronizationLog. Expressing that as a
 * chain of call steps would make a flow author re-implement pagination and
 * mapping in flow nodes, which is "no code, just flows" inverted into "lots of
 * flow, reimplementing code". The synchronisation is already configured and
 * already governed; this node names one and runs it.
 *
 * WHY FULL FAN-OUT, NOT A SUMMARY
 * -------------------------------
 * A flow that has just synchronised 400 objects must be able to act on them.
 * "One summary item with counts" reads safest and is the least useful shape:
 * every downstream use — route the new ones, write a field on the changed ones,
 * notify on the failed ones — would have to re-read what this node already had
 * in hand, which is both a second round trip and a race. The counts are not
 * lost: they ride on EVERY emitted item under `<output>.summary`.
 *
 * WHY IT RAISES INSTEAD OF TRUNCATING
 * -----------------------------------
 * A shortened list is indistinguishable from a complete one at every downstream
 * step. So a run over `config.maxItems` (default 1000) raises, naming the count,
 * the ceiling, the step and the synchronization — and says explicitly that the
 * objects WERE synchronised and only their emission as flow items was refused,
 * so an author does not read a failed step as a failed sync and re-run it.
 * At 250 emitted items a warning is logged, so a run growing toward its ceiling
 * is visible before the day it starts failing.
 *
 * WHY ZERO OBJECTS STILL EMITS ONE ITEM
 * -------------------------------------
 * An empty list would make "ran and found nothing" indistinguishable from
 * "never ran", and the downstream half of the flow would silently do nothing
 * while the run reported success. The summary-only item is explicitly marked so
 * it is never mistaken for a synchronised object.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeLogActions;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;
use UnexpectedValueException;

/**
 * Runs a configured Synchronization as one flow step, fanning out its objects.
 *
 * @spec openspec/changes/integriq-flow-nodes/tasks.md#task-4-synchronizationrunnode-with-bounded-fan-out-seed-data-and-a-live-end-to-end-run
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class sat at the
 *   threshold before `configKeys()` — a one-line vocabulary declaration —
 *   tipped it over. The complexity is the count of distinct ways a run
 *   config and its fan-out can be wrong; splitting the class would move
 *   that branching, not remove it.
 */
class SynchronizationRunNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeLogActions, SynchronizationRunNodeInterface {
	use FlowNodePaletteDefaults;

	/**
	 * Palette icon: app and image path.
	 */
	private const PALETTE_ICON = ['integriq', 'flow-synchronization-run.svg'];

	use SynchronizationLogActions;

	/**
	 * The item key an unset `output` writes the per-object result under.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTPUT_KEY = 'syncResult';

	/**
	 * The default fan-out ceiling.
	 *
	 * Part of the node's contract: changing it alters behaviour for every flow
	 * that never sets `maxItems`, so it follows the breaking-change policy.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_ITEMS = 1000;

	/**
	 * The emitted-item count above which a warning is logged.
	 *
	 * Below the default ceiling on purpose — a run growing toward its limit
	 * should be visible before the day it starts failing.
	 *
	 * @var int
	 */
	public const WARNING_THRESHOLD = 250;


	/**
	 * Constructor.
	 *
	 * @param SynchronizationServiceInterface $synchronizationService The existing synchronisation engine.
	 * @param FlowOwner $flowOwner Fail-closed run-owner resolution.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly SynchronizationServiceInterface $synchronizationService,
		private readonly FlowOwner $flowOwner,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Run a synchronization');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Run a configured synchronization and emit one item per synchronised object, with the run totals on each.'
		);

	}//end getDescription()

	/**
	 * The config vocabulary of a synchronization run.
	 *
	 * Only `synchronization` is required (validateConfig below enforces
	 * that). Naming the vocabulary is what lets the preflight refuse a key
	 * this node would silently ignore — and what lets the flow editor's step
	 * dialog render one field per option instead of a JSON box.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configKeys(): array {
		return ['synchronization', 'force', 'output', 'maxItems', 'onError'];
	}//end configKeys()

	/**
	 * The fields this node is edited through.
	 *
	 * `synchronization` is a picker fed by the app's OWN synchronizations
	 * listing, so an author chooses one by name instead of pasting a uuid
	 * into a JSON pane.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configForm(): array {
		return (new SynchronizationRunConfigForm(l10n: $this->l10n))->fields();
	}//end configForm()

	/**
	 * Reject a configuration the author cannot have meant, at flow-save time.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function validateConfig(array $config): void {
		FlowConfigGuard::assertNoForbiddenFields(config: $config, l10n: $this->l10n);

		$this->assertSynchronizationReference(config: $config);
		$this->assertForceIsBoolean(config: $config);

		if (array_key_exists('output', $config) === true) {
			FlowConfigGuard::assertOutputKeyAllowed(outputKey: (string)$config['output'], l10n: $this->l10n);
		}

		$this->assertMaxItemsIsWholeNumber(config: $config);
		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Reject an inline or empty "synchronization" reference.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the reference is inline or empty.
	 */
	private function assertSynchronizationReference(array $config): void {
		$synchronization = ($config['synchronization'] ?? null);
		if (is_array($synchronization) === true) {
			throw new UnexpectedValueException(
				$this->l10n->t(
					'The "synchronization" field must reference an existing synchronization; an inline definition '
					. 'is not accepted and this step never creates one.'
				)
			);
		}

		if (trim((string)($synchronization ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('The "synchronization" field must name a configured synchronization (its uuid, slug or reference).')
			);
		}

	}//end assertSynchronizationReference()

	/**
	 * Reject a "force" value that is not a boolean.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When "force" is present and not a boolean.
	 */
	private function assertForceIsBoolean(array $config): void {
		if (array_key_exists('force', $config) === true && is_bool($config['force']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "force" field must be true or false.')
			);
		}

	}//end assertForceIsBoolean()

	/**
	 * Reject a "maxItems" value that is not a whole number of at least one.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When "maxItems" is present and malformed.
	 */
	private function assertMaxItemsIsWholeNumber(array $config): void {
		if (array_key_exists('maxItems', $config) === false) {
			return;
		}

		$maxItems = $config['maxItems'];
		if (is_int($maxItems) === false || $maxItems < 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "maxItems" field must be a whole number of at least 1.')
			);
		}

	}//end assertMaxItemsIsWholeNumber()

	/**
	 * Run the synchronization once per item and fan out its objects.
	 *
	 * @param FlowNodeCall $call The input items, the step's configuration and the run context.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException On an unattributed run, a failed synchronisation or a ceiling breach.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;

		if ($items === []) {
			return [];
		}

		$this->validateConfig(config: $config);

		$owner = $this->flowOwner->resolve(context: $context, nodeId: self::NODE_ID);

		return $this->flowOwner->runAs(
			user: $owner,
			callback: function () use ($items, $config, $context) {
				return $this->runForEachItem(items: $items, config: $config, context: $context);
			}
		);

	}//end execute()

	/**
	 * Run the synchronization for each input item, in the owner's context.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException On a failed synchronisation or a ceiling breach.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function runForEachItem(array $items, array $config, array $context): array {
		$run = $this->startRun(config: $config, context: $context);

		foreach ($items as $index => $item) {
			$run->item  = $item;
			$run->index = $index;
			try {
				$this->emitSynchronisedObjects(run: $run);
			} catch (FlowNodeException $exception) {
				$this->recordFailure(run: $run, exception: $exception);
			}
		}

		$this->warnOnLargeFanOut(run: $run);

		return $run->emitted;
	}//end runForEachItem()

	/**
	 * Read the step's settings once and resolve the synchronization it names.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return SynchronizationStepRun The run, ready for its first item.
	 *
	 * @throws FlowNodeException When the reference resolves to nothing.
	 */
	private function startRun(array $config, array $context): SynchronizationStepRun {
		$run = new SynchronizationStepRun();
		$run->reference = trim((string)$config['synchronization']);
		$run->outputKey = (string)($config['output'] ?? self::DEFAULT_OUTPUT_KEY);
		$run->force = (bool)($config['force'] ?? false);
		$run->maxItems = (int)($config['maxItems'] ?? self::DEFAULT_MAX_ITEMS);
		$run->onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$run->stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);

		$run->synchronization = $this->resolveSynchronization(reference: $run->reference);

		return $run;
	}//end startRun()

	/**
	 * Run the synchronization for the item in hand and append its output items.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException On a failed synchronisation or a ceiling breach.
	 */
	private function emitSynchronisedObjects(SynchronizationStepRun $run): void {
		$log = $this->runSynchronization(run: $run);
		$reader = new SynchronizationLogReader();
		$objects = $reader->objectsFrom(log: $log);
		$run->summary = $reader->summaryFrom(log: $log, reference: $run->reference);

		$this->assertWithinCeiling(run: $run, objectCount: count($objects));

		$run->emitted = array_merge($run->emitted, $this->itemsFor(run: $run, objects: $objects));
	}//end emitSynchronisedObjects()

	/**
	 * Log a failed item, then rethrow it or record it on the item under `onError: continue`.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException Unless the policy is `continue`.
	 */
	private function recordFailure(SynchronizationStepRun $run, FlowNodeException $exception): void {
		$this->logger->error(
			'[openconnector.synchronization-run] ' . $exception->getMessage(),
			[
				'file' => __FILE__,
				'line' => __LINE__,
				'step' => $run->stepId,
				'synchronization' => $run->reference,
			]
		);

		if ($run->onError !== 'continue') {
			throw $exception;
		}

		$run->emitted[] = $this->errorItem(run: $run, exception: $exception);
	}//end recordFailure()
	/**
	 * Resolve the Synchronization named by the step, or refuse.
	 *
	 * Never creates one: a step names an already-configured synchronisation or
	 * it fails.
	 *
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return ObjectEntity The resolved synchronization object.
	 *
	 * @throws FlowNodeException When the reference resolves to nothing.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function resolveSynchronization(string $reference): ObjectEntity {
		try {
			return $this->synchronizationService->getSynchronization(id: $reference);
		} catch (Throwable $exception) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The synchronization "%1$s" named by this step could not be resolved: %2$s. '
					. 'No synchronization is created and none is started.',
					[$reference, $exception->getMessage()]
				),
				details: ['kind' => 'synchronization', 'synchronization' => $reference],
				previous: $exception
			);
		}

	}//end resolveSynchronization()

	/**
	 * Turn a rate limit into a pause rather than the end of the run.
	 *
	 * WHAT WENT WRONG BEFORE, precisely — because the obvious diagnosis is the
	 * wrong one. `checkRateLimit()` throws BEFORE the first request of a
	 * synchronisation, so a shard that is refused never starts, and never has a
	 * page to resume from. The engine's per-synchronisation `currentPage` is
	 * therefore not what was missing.
	 *
	 * What was missing is that the run ENDED. Measured 2026-08-13 on a
	 * twelve-shard publiccode crawl: the first three shards spent the whole
	 * `code_search` budget (10 requests a minute), the remaining nine were
	 * refused at entry, and the run finished reporting success. Re-running did
	 * not catch up — the completed shards ran again from the start, spent the
	 * budget again, and the same nine starved. Three runs 65 s apart each
	 * returned the same 641 repositories. Waiting longer would never have
	 * helped; the crawl was not slow, it was looping.
	 *
	 * Suspending fixes that at the root, and does so using machinery already
	 * present: the engine does not advance the marking for a suspended step, so
	 * on resume THIS node runs again while the shards that already completed do
	 * not. Starvation stops being possible, rather than becoming less likely.
	 *
	 * The reset time comes from the source's own `X-RateLimit-Reset`, which
	 * `CallService::sourceRateLimit()` already reads and stores. Waking earlier
	 * than that would be refused again; waking on a fixed backoff would usually
	 * wake far too late. A source that gives no usable reset gets a short
	 * default, because suspending with no `resumeAt` at all would leave the run
	 * waiting for a signal nothing sends.
	 *
	 * The bounds and the reset arithmetic themselves live in
	 * {@see FlowRateLimit} — `source-paginate` inherits the identical failure
	 * mode the moment it makes the fetch, and two copies of a clamp are two
	 * clamps. This method keeps what is this node's own: the log line.
	 *
	 * @param TooManyRequestsHttpException $exception The refusal, carrying the rate-limit headers.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return FlowSuspension The suspension to throw.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md#requirement-a-rate-limited-synchronization-suspends-the-run-instead-of-ending-it
	 */
	private function suspendUntilTheLimitLifts(
		TooManyRequestsHttpException $exception,
		string $reference,
	): FlowSuspension {
		$suspension = FlowRateLimit::suspensionFor(exception: $exception, subject: $reference);

		$this->logger->info(
			'[openconnector.synchronization-run] Rate limited; suspending until the limit lifts.',
			[
				'file' => __FILE__,
				'line' => __LINE__,
				'synchronization' => $reference,
				'resumeAt' => $suspension->getResumeAt()?->format('c'),
			]
		);

		return $suspension;
	}//end suspendUntilTheLimitLifts()

	/**
	 * Run the synchronisation once and return its log.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 *
	 * @return array The synchronisation log.
	 *
	 * @throws FlowSuspension When the source rate-limits the run.
	 * @throws FlowNodeException When the synchronisation fails.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function runSynchronization(SynchronizationStepRun $run): array {
		try {
			$log = $this->synchronizationService->synchronize(
				synchronization: $run->synchronization,
				isTest: false,
				force: $run->force
			);
		} catch (TooManyRequestsHttpException $rateLimited) {
			// Not a failure, and not something to retry immediately. Caught
			// ahead of the generic handler so it never becomes a node failure:
			// an `onError: continue` policy would otherwise skip past a rate
			// limit, which reads as "this shard found nothing" rather than
			// "this shard was not allowed to look".
			throw $this->suspendUntilTheLimitLifts(exception: $rateLimited, reference: $run->reference);
		} catch (Throwable $exception) {
			throw $this->synchronizationFailure(reference: $run->reference, exception: $exception);
		}

		return (array)($log ?? []);
	}//end runSynchronization()

	/**
	 * The node failure for a synchronisation that threw.
	 *
	 * @param string $reference The authored synchronization reference.
	 * @param Throwable $exception What the synchronisation threw.
	 *
	 * @return FlowNodeException
	 */
	private function synchronizationFailure(string $reference, Throwable $exception): FlowNodeException {
		return new FlowNodeException(
			message: $this->l10n->t(
				'The synchronization "%1$s" failed: %2$s',
				[$reference, $exception->getMessage()]
			),
			details: ['kind' => 'synchronization', 'synchronization' => $reference],
			previous: $exception
		);
	}//end synchronizationFailure()

	/**
	 * Build the output items for one synchronisation run.
	 *
	 * A run that touched nothing still emits exactly one item, marked as a
	 * summary. Emitting nothing would make "ran and found nothing"
	 * indistinguishable from "never ran".
	 *
	 * @param SynchronizationStepRun $run The step run, holding the item in hand and its summary.
	 * @param array $objects The per-object results.
	 *
	 * @return array<int, array<string, mixed>> The output items.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function itemsFor(SynchronizationStepRun $run, array $objects): array {
		if ($objects === []) {
			$payload = [
				'summaryOnly' => true,
				'synchronization' => $run->reference,
				'summary' => $run->summary,
			];

			return [$this->outputItem(run: $run, payload: $payload)];
		}

		$items = [];
		foreach ($objects as $object) {
			$items[] = $this->outputItem(run: $run, payload: $this->objectPayload(run: $run, object: $object));
		}

		return $items;
	}//end itemsFor()

	/**
	 * The payload of one synchronised object: its result plus the run summary.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 * @param array $object The per-object result.
	 *
	 * @return array<string, mixed>
	 */
	private function objectPayload(SynchronizationStepRun $run, array $object): array {
		return array_merge(
			$object,
			[
				'summaryOnly' => false,
				'synchronization' => $run->reference,
				'summary' => $run->summary,
			]
		);
	}//end objectPayload()

	/**
	 * One output item: the payload written under the output key of the item in hand.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 * @param array $payload The payload to write.
	 *
	 * @return array<string, mixed>
	 */
	private function outputItem(SynchronizationStepRun $run, array $payload): array {
		return [
			'json' => FlowTemplate::write(json: (array)($run->item['json'] ?? []), path: $run->outputKey, value: $payload),
			'binary' => (array)($run->item['binary'] ?? []),
			'pairedItem' => ['item' => $run->index],
		];
	}//end outputItem()

	/**
	 * Refuse a fan-out above the ceiling — loudly, and without truncating.
	 *
	 * The count checked is what would have been emitted after this item: the
	 * items so far plus this run's objects, or one summary item for none.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 * @param int $objectCount The number of objects this run synchronised.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When the ceiling would be exceeded.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertWithinCeiling(SynchronizationStepRun $run, int $objectCount): void {
		if ((count($run->emitted) + max($objectCount, 1)) <= $run->maxItems) {
			return;
		}

		throw new FlowNodeException(
			message: $this->ceilingMessage(run: $run, objectCount: $objectCount),
			details: [
				'kind' => 'ceiling',
				'step' => $run->stepId,
				'synchronization' => $run->reference,
				'objectCount' => $objectCount,
				'maxItems' => $run->maxItems,
				'synchronised' => true,
			]
		);

	}//end assertWithinCeiling()

	/**
	 * The ceiling refusal, saying the objects were synchronised.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 * @param int $objectCount The number of objects this run synchronised.
	 *
	 * @return string
	 */
	private function ceilingMessage(SynchronizationStepRun $run, int $objectCount): string {
		return $this->l10n->t(
			'Step "%1$s" synchronised %2$s objects through synchronization "%3$s", which is more than its '
			. 'fan-out ceiling of %4$s items. The objects WERE synchronised and are stored; only their '
			. 'emission as flow items was refused. No truncated or sampled list is returned — raise '
			. '"maxItems" on the step if a larger fan-out is intended.',
			[$run->stepId, (string)$objectCount, $run->reference, (string)$run->maxItems]
		);
	}//end ceilingMessage()

	/**
	 * Log a warning when a fan-out is large enough to be heading for its ceiling.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function warnOnLargeFanOut(SynchronizationStepRun $run): void {
		$count = count($run->emitted);
		if ($count < self::WARNING_THRESHOLD) {
			return;
		}

		$this->logger->warning(
			sprintf(
				'[openconnector.synchronization-run] Step "%s" emitted %d items from synchronization "%s" (ceiling %d).',
				$run->stepId,
				$count,
				$run->reference,
				$run->maxItems
			),
			$this->fanOutLogContext(run: $run)
		);

	}//end warnOnLargeFanOut()

	/**
	 * The log context of the large fan-out warning.
	 *
	 * @param SynchronizationStepRun $run The step run.
	 *
	 * @return array<string, mixed>
	 */
	private function fanOutLogContext(SynchronizationStepRun $run): array {
		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'step' => $run->stepId,
			'synchronization' => $run->reference,
			'items' => count($run->emitted),
			'maxItems' => $run->maxItems,
			'threshold' => self::WARNING_THRESHOLD,
		];
	}//end fanOutLogContext()

	/**
	 * Build the output item for a failed run under `onError: continue`.
	 *
	 * @param SynchronizationStepRun $run The step run, holding the failed item.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return array The output item carrying explicit error state.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function errorItem(SynchronizationStepRun $run, FlowNodeException $exception): array {
		$json = (array)($run->item['json'] ?? []);
		$json[FlowNodeSupport::ERROR_KEY] = array_merge(
			$exception->getDetails(),
			[
				'step' => $run->stepId,
				'node' => self::NODE_ID,
				'message' => $exception->getMessage(),
				'synchronization' => $run->reference,
			]
		);

		return [
			'json' => $json,
			'binary' => (array)($run->item['binary'] ?? []),
			'pairedItem' => ['item' => $run->index],
		];

	}//end errorItem()
}//end class
