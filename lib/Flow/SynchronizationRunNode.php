<?php

/**
 * OpenConnector Synchronization Run flow node.
 *
 * `openconnector.synchronization-run` — runs an ALREADY-CONFIGURED
 * Synchronization as one flow step, and emits ONE ITEM PER SYNCHRONISED OBJECT.
 *
 * WHY A SECOND NODE
 * -----------------
 * `openconnector.source-call` covers "make one request and put the answer on
 * the item". It does not cover OpenConnector's other governed outbound
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
 * @package  OCA\OpenConnector\Flow
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
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Flow;

use OCA\OpenConnector\Exception\FlowNodeException;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Runs a configured Synchronization as one flow step, fanning out its objects.
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-4-synchronizationrunnode-with-bounded-fan-out-seed-data-and-a-live-end-to-end-run
 */
class SynchronizationRunNode implements IFlowNode {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.synchronization-run';

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
	 * @param SynchronizationService $synchronizationService The existing synchronisation engine.
	 * @param FlowOwner $flowOwner Fail-closed run-owner resolution.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly SynchronizationService $synchronizationService,
		private readonly FlowOwner $flowOwner,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Run a synchronization');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Run a configured synchronization and emit one item per synchronised object, with the run totals on each.'
		);

	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('openconnector', 'flow-synchronization-run.svg');
	}//end getIcon()

	/**
	 * Whether the node is offered in the given scope.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Reject a configuration the author cannot have meant, at flow-save time.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function validateConfig(array $config): void {
		FlowConfigGuard::assertNoForbiddenFields(config: $config, l10n: $this->l10n);

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

		if (array_key_exists('force', $config) === true && is_bool($config['force']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "force" field must be true or false.')
			);
		}

		if (array_key_exists('output', $config) === true) {
			FlowConfigGuard::assertOutputKeyAllowed(outputKey: (string)$config['output'], l10n: $this->l10n);
		}

		if (array_key_exists('maxItems', $config) === true) {
			$maxItems = $config['maxItems'];
			if (is_int($maxItems) === false || $maxItems < 1) {
				throw new UnexpectedValueException(
					$this->l10n->t('The "maxItems" field must be a whole number of at least 1.')
				);
			}
		}

		if (array_key_exists('onError', $config) === true) {
			$policy = strtolower(trim((string)$config['onError']));
			if (in_array($policy, FlowNodeSupport::ON_ERROR_POLICIES, true) === false) {
				throw new UnexpectedValueException(
					$this->l10n->t(
						'The "onError" field must be one of %1$s.',
						[implode(', ', FlowNodeSupport::ON_ERROR_POLICIES)]
					)
				);
			}
		}

	}//end validateConfig()

	/**
	 * Run the synchronization once per item and fan out its objects.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (carries the run owner).
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException On an unattributed run, a failed synchronisation or a ceiling breach.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function runForEachItem(array $items, array $config, array $context): array {
		$reference = trim((string)$config['synchronization']);
		$outputKey = (string)($config['output'] ?? self::DEFAULT_OUTPUT_KEY);
		$force = (bool)($config['force'] ?? false);
		$maxItems = (int)($config['maxItems'] ?? self::DEFAULT_MAX_ITEMS);
		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);

		$synchronization = $this->resolveSynchronization(reference: $reference);

		$emitted = [];
		foreach ($items as $index => $item) {
			try {
				$log = $this->runSynchronization(synchronization: $synchronization, force: $force, reference: $reference);
				$objects = $this->objectsFrom(log: $log);
				$summary = $this->summaryFrom(log: $log, reference: $reference);

				$this->assertWithinCeiling(
					count: (count($emitted) + max(count($objects), 1)),
					objectCount: count($objects),
					maxItems: $maxItems,
					stepId: $stepId,
					reference: $reference
				);

				$emitted = array_merge(
					$emitted,
					$this->itemsFor(
						objects: $objects,
						summary: $summary,
						item: $item,
						index: $index,
						outputKey: $outputKey,
						reference: $reference
					)
				);
			} catch (FlowNodeException $exception) {
				$this->logger->error(
					'[openconnector.synchronization-run] ' . $exception->getMessage(),
					[
						'file' => __FILE__,
						'line' => __LINE__,
						'step' => $stepId,
						'synchronization' => $reference,
					]
				);

				if ($onError !== 'continue') {
					throw $exception;
				}

				$emitted[] = $this->errorItem(
					item: $item,
					index: $index,
					stepId: $stepId,
					reference: $reference,
					exception: $exception
				);
			}//end try
		}//end foreach

		$this->warnOnLargeFanOut(count: count($emitted), maxItems: $maxItems, stepId: $stepId, reference: $reference);

		return $emitted;
	}//end runForEachItem()

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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
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
	 * Run the synchronisation, turning any failure into a raised node failure.
	 *
	 * @param ObjectEntity $synchronization The resolved synchronization object.
	 * @param bool $force Whether to force an update of every object.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return array The synchronisation run log.
	 *
	 * @throws FlowNodeException When the synchronisation fails.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function runSynchronization(ObjectEntity $synchronization, bool $force, string $reference): array {
		try {
			$log = $this->synchronizationService->synchronize(
				synchronization: $synchronization,
				isTest: false,
				force: $force
			);
		} catch (Throwable $exception) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The synchronization "%1$s" failed: %2$s',
					[$reference, $exception->getMessage()]
				),
				details: ['kind' => 'synchronization', 'synchronization' => $reference],
				previous: $exception
			);
		}

		return (array)($log ?? []);
	}//end runSynchronization()

	/**
	 * The per-object results a run produced.
	 *
	 * Prefers the resolved contract payloads the engine embeds
	 * (`result._embed.contracts`); falls back to the bare contract identifiers
	 * when a payload could not be resolved, so a synchronised object is never
	 * dropped from the fan-out just because its contract read failed.
	 *
	 * @param array $log The synchronisation run log.
	 *
	 * @return array<int, array<string, mixed>> One entry per synchronised object.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function objectsFrom(array $log): array {
		$result = (array)($log['result'] ?? []);
		$embedded = ($result['_embed']['contracts'] ?? null);
		$contracts = (array)($result['contracts'] ?? []);

		$objects = [];
		foreach (array_values($contracts) as $position => $contractId) {
			$payload = null;
			if (is_array($embedded) === true && array_key_exists($position, $embedded) === true) {
				$payload = $embedded[$position];
			}

			if (is_array($payload) === false) {
				$payload = [];
			}

			$objectId = ($payload['targetId'] ?? null);
			if ($objectId === null || $objectId === '') {
				$objectId = ($payload['originId'] ?? $contractId);
			}

			$objects[] = [
				'objectId' => $objectId,
				'originId' => ($payload['originId'] ?? null),
				'targetId' => ($payload['targetId'] ?? null),
				'outcome' => (string)($payload['targetLastAction'] ?? 'unchanged'),
				'contract' => $contractId,
				'object' => $payload,
			];
		}//end foreach

		return $objects;
	}//end objectsFrom()

	/**
	 * The run counts every emitted item carries.
	 *
	 * @param array $log The synchronisation run log.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return array<string, mixed> The summary.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function summaryFrom(array $log, string $reference): array {
		$result = (array)($log['result'] ?? []);
		$counts = (array)($result['objects'] ?? []);

		return [
			'synchronization' => $reference,
			'runId' => ($log['uuid'] ?? ($log['id'] ?? null)),
			'message' => ($log['message'] ?? null),
			'found' => (int)($counts['found'] ?? 0),
			'created' => (int)($counts['created'] ?? 0),
			'updated' => (int)($counts['updated'] ?? 0),
			'deleted' => (int)($counts['deleted'] ?? 0),
			'skipped' => (int)($counts['skipped'] ?? 0),
			'invalid' => (int)($counts['invalid'] ?? 0),
		];

	}//end summaryFrom()

	/**
	 * Build the output items for one synchronisation run.
	 *
	 * @param array $objects The per-object results.
	 * @param array $summary The run counts.
	 * @param array $item The input item that triggered the run.
	 * @param int $index The input item's index.
	 * @param string $outputKey The author-named output key.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return array<int, array<string, mixed>> The output items.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function itemsFor(
		array $objects,
		array $summary,
		array $item,
		int $index,
		string $outputKey,
		string $reference,
	): array {
		$binary = (array)($item['binary'] ?? []);
		$json = (array)($item['json'] ?? []);

		// A run that touched nothing still emits exactly one item, marked as a
		// summary. Emitting nothing would make "ran and found nothing"
		// indistinguishable from "never ran".
		if ($objects === []) {
			$payload = [
				'summaryOnly' => true,
				'synchronization' => $reference,
				'summary' => $summary,
			];

			return [
				[
					'json' => FlowTemplate::write(json: $json, path: $outputKey, value: $payload),
					'binary' => $binary,
					'pairedItem' => ['item' => $index],
				],
			];
		}

		$items = [];
		foreach ($objects as $object) {
			$payload = array_merge(
				$object,
				[
					'summaryOnly' => false,
					'synchronization' => $reference,
					'summary' => $summary,
				]
			);

			$items[] = [
				'json' => FlowTemplate::write(json: $json, path: $outputKey, value: $payload),
				'binary' => $binary,
				'pairedItem' => ['item' => $index],
			];
		}

		return $items;
	}//end itemsFor()

	/**
	 * Refuse a fan-out above the ceiling — loudly, and without truncating.
	 *
	 * @param int $count The number of items the step would emit.
	 * @param int $objectCount The number of objects this run synchronised.
	 * @param int $maxItems The configured ceiling.
	 * @param string $stepId The step id.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When the ceiling would be exceeded.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertWithinCeiling(
		int $count,
		int $objectCount,
		int $maxItems,
		string $stepId,
		string $reference,
	): void {
		if ($count <= $maxItems) {
			return;
		}

		throw new FlowNodeException(
			message: $this->l10n->t(
				'Step "%1$s" synchronised %2$s objects through synchronization "%3$s", which is more than its '
				. 'fan-out ceiling of %4$s items. The objects WERE synchronised and are stored; only their '
				. 'emission as flow items was refused. No truncated or sampled list is returned — raise '
				. '"maxItems" on the step if a larger fan-out is intended.',
				[$stepId, (string)$objectCount, $reference, (string)$maxItems]
			),
			details: [
				'kind' => 'ceiling',
				'step' => $stepId,
				'synchronization' => $reference,
				'objectCount' => $objectCount,
				'maxItems' => $maxItems,
				'synchronised' => true,
			]
		);

	}//end assertWithinCeiling()

	/**
	 * Log a warning when a fan-out approaches its ceiling.
	 *
	 * @param int $count The number of emitted items.
	 * @param int $maxItems The configured ceiling.
	 * @param string $stepId The step id.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function warnOnLargeFanOut(int $count, int $maxItems, string $stepId, string $reference): void {
		if ($count < self::WARNING_THRESHOLD) {
			return;
		}

		$this->logger->warning(
			sprintf(
				'[openconnector.synchronization-run] Step "%s" emitted %d items from synchronization "%s" (ceiling %d).',
				$stepId,
				$count,
				$reference,
				$maxItems
			),
			[
				'file' => __FILE__,
				'line' => __LINE__,
				'step' => $stepId,
				'synchronization' => $reference,
				'items' => $count,
				'maxItems' => $maxItems,
				'threshold' => self::WARNING_THRESHOLD,
			]
		);

	}//end warnOnLargeFanOut()

	/**
	 * Build the output item for a failed run under `onError: continue`.
	 *
	 * @param array $item The input item.
	 * @param int $index The input item's index.
	 * @param string $stepId The step id.
	 * @param string $reference The authored synchronization reference.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return array The output item carrying explicit error state.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function errorItem(
		array $item,
		int $index,
		string $stepId,
		string $reference,
		FlowNodeException $exception,
	): array {
		$json = (array)($item['json'] ?? []);
		$json[FlowNodeSupport::ERROR_KEY] = array_merge(
			$exception->getDetails(),
			[
				'step' => $stepId,
				'node' => self::NODE_ID,
				'message' => $exception->getMessage(),
				'synchronization' => $reference,
			]
		);

		return [
			'json' => $json,
			'binary' => (array)($item['binary'] ?? []),
			'pairedItem' => ['item' => $index],
		];

	}//end errorItem()
}//end class
