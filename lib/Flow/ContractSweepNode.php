<?php

/**
 * OpenConnector Contract Sweep flow node.
 *
 * `openconnector.contract-sweep` — the "[Stale sweep]" step of the decomposed
 * synchronization flow: objects whose contracts were NOT touched by this pass
 * are gone from the source, and this step hands their cleanup to the EXISTING
 * guarded deletion, `SynchronizationService::deleteInvalidObjects()`.
 *
 * WHY NOTHING IS RE-IMPLEMENTED — ESPECIALLY NOT HERE
 * ---------------------------------------------------
 * Deletion is the one place a sync can destroy data, and the service method
 * already carries every guard that history demanded: the incremental-mode
 * refusal, the fetch-completeness gate, and the deletion-ratio guard. This
 * node re-implements NONE of them. It collects the pass's target ids, calls
 * the service once, and SURFACES the guard verdict in its summary item — a
 * sweep the guards refused reports `guarded: true` with the reason, never a
 * silent zero.
 *
 * WHY THE SWEEP GATES ON A COMPLETE PASS
 * --------------------------------------
 * A partial run must never delete objects it simply did not reach. The
 * `fetchComplete` config is either a literal boolean or a dot-path read from
 * the first item (where the fetch step reports its completeness), and an
 * ABSENT value reads as false — fail closed: an unknown completeness is not a
 * safe basis for a diff-based cleanup, and `force` deliberately does NOT
 * bypass this gate (the service refuses regardless).
 *
 * WHY ONE SUMMARY ITEM
 * --------------------
 * This is a reporting step at the end of a pass, not a fan-out: downstream
 * steps branch on the sweep's outcome (notify on a guarded sweep, log the
 * count), never on individual deleted objects — the service already logs
 * those. So exactly one item is emitted, the same summary-only shape
 * `synchronization-run` uses for a zero-object run.
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
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Flow;

use OCA\OpenConnector\Exception\FlowNodeException;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Sweeps stale objects after a complete pass, through the guarded deletion.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#1-engine-steps-each-a-thin-adapter-over-a-kept-service
 */
class ContractSweepNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.contract-sweep';

	/**
	 * The item key the summary is written under.
	 *
	 * Fixed rather than configurable: the step emits one summary item of its
	 * own making, so there is no author-supplied record whose keys it could
	 * collide with.
	 *
	 * @var string
	 */
	private const OUTPUT_KEY = 'sweep';

	/**
	 * The dot-path an unset `targetIdsPosition` reads each target uuid from.
	 *
	 * @var string
	 */
	private const DEFAULT_TARGET_IDS_POSITION = 'uuid';

	/**
	 * Constructor.
	 *
	 * @param SynchronizationService $synchronizationService The engine owning the guarded deletion.
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
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Sweep stale objects');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'After a complete pass, remove objects the source no longer has — through the guarded deletion, never around it.'
		);

	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
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
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The node's whole config vocabulary.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function configKeys(): array {
		return [
			'synchronization',
			'targetIdsPosition',
			'fetchComplete',
			'force',
			'onError',
		];
	}//end configKeys()

	/**
	 * The fields this node's configuration is edited through.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'synchronization',
				'label' => $this->l10n->t('Synchronization'),
				'type' => 'select',
				'help' => $this->l10n->t('The synchronization whose stale objects this step sweeps.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/synchronization',
			],
			[
				'key' => 'targetIdsPosition',
				'label' => $this->l10n->t('Target id path'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Dot-path to the synced target uuid on each item. Defaults to "uuid".'
				),
			],
			[
				'key' => 'fetchComplete',
				'label' => $this->l10n->t('Fetch complete'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Only sweep after a complete pass; a partial run must never delete what it did not reach. '
					. 'A literal true/false, or a dot-path read from the first item.'
				),
			],
			[
				'key' => 'force',
				'label' => $this->l10n->t('Force deletion'),
				'type' => 'boolean',
				'help' => $this->l10n->t(
					'Overrides the deletion-ratio guard that stops a sweep from deleting an implausible share of '
					. 'the synchronization\'s objects. It never bypasses the fetch-completeness gate. Leave off '
					. 'unless a large legitimate cleanup was refused and you have verified the source really '
					. 'dropped those objects.'
				),
			],
			[
				'key' => 'onError',
				'label' => $this->l10n->t('On error'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'What a failed item does to the run: stop, continue or dead_letter.'
				),
			],
		];
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
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function validateConfig(array $config): void {
		FlowConfigGuard::assertNoForbiddenFields(config: $config, l10n: $this->l10n);
		FlowNodeSupport::assertReference(config: $config, key: 'synchronization', l10n: $this->l10n);

		if (array_key_exists('targetIdsPosition', $config) === true
			&& (is_string($config['targetIdsPosition']) === false || trim($config['targetIdsPosition']) === '')
		) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "%1$s" field must be a non-empty dot-path when set.', ['targetIdsPosition'])
			);
		}

		$this->assertSweepFlags(config: $config);

		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Reject a malformed `fetchComplete` or `force` value, at flow-save time.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When either flag is unusable.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function assertSweepFlags(array $config): void {
		if (array_key_exists('fetchComplete', $config) === true
			&& is_bool($config['fetchComplete']) === false
			&& (is_string($config['fetchComplete']) === false || trim($config['fetchComplete']) === '')
		) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "fetchComplete" field must be true, false, or a non-empty dot-path.')
			);
		}

		if (array_key_exists('force', $config) === true && is_bool($config['force']) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "force" field must be true or false.')
			);
		}

	}//end assertSweepFlags()

	/**
	 * Sweep the synchronization's stale objects and emit ONE summary item.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (carries the run owner).
	 *
	 * @return array Exactly one summary item, or none for an empty input.
	 *
	 * @throws FlowNodeException On an unattributed run or a failed sweep under `stop`.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
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
				return $this->sweep(items: $items, config: $config, context: $context);
			}
		);

	}//end execute()

	/**
	 * Delegate the sweep to the guarded deletion and summarise its verdict.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array Exactly one summary item.
	 *
	 * @throws FlowNodeException When the sweep fails and the policy is `stop`.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function sweep(array $items, array $config, array $context): array {
		$reference = trim((string)$config['synchronization']);
		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);
		$targetIds = $this->collectTargetIds(items: $items, config: $config);
		$fetchComplete = $this->fetchCompleteFrom(items: $items, config: $config);
		$force = (bool)($config['force'] ?? false);

		try {
			$synchronization = $this->synchronizationService->getSynchronization(id: $reference)->jsonSerialize();

			$guardInfo = null;
			$deleted = $this->synchronizationService->deleteInvalidObjects(
				synchronization: $synchronization,
				synchronizedTargetIds: $targetIds,
				fetchComplete: $fetchComplete,
				forceDeletion: $force,
				guardInfo: $guardInfo
			);
		} catch (Throwable $exception) {
			$failure = $this->asNodeFailure(exception: $exception, reference: $reference);

			$this->logger->error(
				'[openconnector.contract-sweep] ' . $failure->getMessage(),
				[
					'file' => __FILE__,
					'line' => __LINE__,
					'step' => $stepId,
					'synchronization' => $reference,
				]
			);

			if ($onError === 'stop') {
				throw $failure;
			}

			return [
				FlowItems::item(
					json: [
						FlowNodeSupport::ERROR_KEY => [
							'step' => $stepId,
							'node' => self::NODE_ID,
							'kind' => 'sweep',
							'message' => $failure->getMessage(),
							'synchronization' => $reference,
						],
					],
					fromItemIndex: 0
				),
			];
		}//end try

		return [
			FlowItems::item(
				json: [
					self::OUTPUT_KEY => $this->summary(
						reference: $reference,
						deleted: $deleted,
						guardInfo: (array)($guardInfo ?? []),
						targetIdCount: count($targetIds),
						fetchComplete: $fetchComplete
					),
				],
				fromItemIndex: 0
			),
		];
	}//end sweep()

	/**
	 * The summary block the single emitted item carries.
	 *
	 * The guard fields come straight from `deleteInvalidObjects()`'s own
	 * `guardInfo` — surfaced, never re-derived, so the summary can never
	 * disagree with what the service actually decided.
	 *
	 * @param string $reference The authored synchronization reference.
	 * @param int $deleted How many objects the sweep deleted.
	 * @param array $guardInfo The service's guard verdict, when it gave one.
	 * @param int $targetIdCount How many target ids the pass produced.
	 * @param bool $fetchComplete The completeness verdict the sweep ran under.
	 *
	 * @return array<string, mixed> The summary block.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function summary(
		string $reference,
		int $deleted,
		array $guardInfo,
		int $targetIdCount,
		bool $fetchComplete,
	): array {
		return [
			'synchronization' => $reference,
			'swept' => $deleted,
			'guarded' => (bool)($guardInfo['guarded'] ?? false),
			'guardReason' => ($guardInfo['reason'] ?? null),
			'ratio' => ($guardInfo['ratio'] ?? null),
			'threshold' => ($guardInfo['threshold'] ?? null),
			'candidateCount' => ($guardInfo['candidateCount'] ?? null),
			'totalContracts' => ($guardInfo['totalContracts'] ?? null),
			'targetIds' => $targetIdCount,
			'fetchComplete' => $fetchComplete,
		];
	}//end summary()

	/**
	 * Collect the pass's target ids from every item.
	 *
	 * An item without one is skipped, not fatal: a page can legitimately carry
	 * summary-only or errored items, and the guards downstream — not this
	 * loop — decide whether the resulting id list is a plausible basis for
	 * deletion.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 *
	 * @return array<int, string> The deduplicated target ids.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function collectTargetIds(array $items, array $config): array {
		$position = trim((string)($config['targetIdsPosition'] ?? ''));
		if ($position === '') {
			$position = self::DEFAULT_TARGET_IDS_POSITION;
		}

		$targetIds = [];
		foreach ($items as $item) {
			$raw = FlowTemplate::lookup(path: $position, json: (array)($item['json'] ?? []));
			if (is_scalar($raw) === false) {
				continue;
			}

			$targetId = trim((string)$raw);
			if ($targetId !== '') {
				$targetIds[] = $targetId;
			}
		}

		return array_values(array_unique($targetIds));
	}//end collectTargetIds()

	/**
	 * Resolve the pass's completeness verdict, failing CLOSED.
	 *
	 * A literal boolean is taken as authored. A dot-path is read from the
	 * FIRST item — the fetch step reports run-level facts there — and a path
	 * that resolves to nothing reads as false: unknown completeness must not
	 * sweep, and the guarded skip that follows is visible in the summary.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 *
	 * @return bool Whether the pass may be treated as complete.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function fetchCompleteFrom(array $items, array $config): bool {
		$setting = ($config['fetchComplete'] ?? true);
		if (is_bool($setting) === true) {
			return $setting;
		}

		$path = trim((string)$setting);
		if ($path === '') {
			return true;
		}

		$first = (array)(($items[array_key_first($items)] ?? [])['json'] ?? []);

		return (bool)FlowTemplate::lookup(path: $path, json: $first);
	}//end fetchCompleteFrom()

	/**
	 * Normalise any failure into a raised node failure.
	 *
	 * @param Throwable $exception The underlying failure.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return FlowNodeException The node failure to raise or record.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function asNodeFailure(Throwable $exception, string $reference): FlowNodeException {
		if ($exception instanceof FlowNodeException) {
			return $exception;
		}

		return new FlowNodeException(
			message: $this->l10n->t(
				'The stale-object sweep for synchronization "%1$s" failed: %2$s',
				[$reference, $exception->getMessage()]
			),
			details: ['kind' => 'sweep', 'synchronization' => $reference],
			previous: $exception
		);
	}//end asNodeFailure()
}//end class
