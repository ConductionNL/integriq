<?php

/**
 * OpenConnector Contract Commit flow node.
 *
 * `openconnector.contract-commit` — the "[Commit contracts]" step of the
 * decomposed synchronization flow: AFTER the page's objects are written, the
 * decisions the `openconnector.contract` step stamped are upserted as
 * synchronization contracts in ONE bulk call.
 *
 * WHY COMMIT IS ITS OWN STEP, AFTER THE WRITE
 * -------------------------------------------
 * A contract records that source object X lives at target A. Writing it before
 * A exists records a mapping to nothing; writing it per object multiplies the
 * page's database round-trips. So this step runs after the bulk object write,
 * reads each item's decision block plus the written object's uuid, and hands
 * the whole page to `SynchronizationContractService::persistBulk()` — one
 * upsert per page, which is the same round-trip budget the design gives the
 * lookup and the save.
 *
 * WHY `skip` AND `invalid` PASS THROUGH UNTOUCHED
 * -----------------------------------------------
 * A skipped item's contract is already correct — the hash compare proved it —
 * and an invalid item has no origin id to contract. Re-upserting either would
 * turn "zero writes for an unchanged page" into a write per item, which is the
 * property the contract step exists to protect.
 *
 * WHY AN UPDATE WITHOUT A CONTRACT UUID IS A FAILURE
 * --------------------------------------------------
 * An `update` decision names the contract it updates. Committing one without
 * that uuid would CREATE a second contract for the same origin — the exact
 * duplicate-per-rerun defect the engine's history warns about — so it follows
 * the step's `onError` policy instead. `dead_letter` is treated like
 * `continue` here (the item carries explicit `__error` state); the dead-letter
 * capture itself is engine-side wiring.
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

use DateTime;
use OCA\OpenConnector\Exception\FlowNodeException;
use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use UnexpectedValueException;

/**
 * Bulk-upserts the page's synchronization contracts after the write step.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#1-engine-steps-each-a-thin-adapter-over-a-kept-service
 */
class ContractCommitNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.contract-commit';

	/**
	 * The dot-path an unset `contractPosition` reads the decision block from.
	 *
	 * Matches the `openconnector.contract` node's default output key, so the
	 * two steps compose without configuration.
	 *
	 * @var string
	 */
	private const DEFAULT_CONTRACT_POSITION = 'contract';

	/**
	 * The dot-path an unset `targetIdPosition` reads the written uuid from.
	 *
	 * After a bulk object write the written object carries its uuid at the top
	 * of the item's record.
	 *
	 * @var string
	 */
	private const DEFAULT_TARGET_ID_POSITION = 'uuid';

	/**
	 * The decision outcomes this step commits.
	 *
	 * @var array<int, string>
	 */
	private const COMMITTABLE_OUTCOMES = [
		'create',
		'update',
	];

	/**
	 * Constructor.
	 *
	 * @param SynchronizationContractService $synchronizationContractService The contract store.
	 * @param FlowOwner $flowOwner Fail-closed run-owner resolution.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly SynchronizationContractService $synchronizationContractService,
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
		return $this->l10n->t('Commit contracts');
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
			'Upsert the page\'s synchronization contracts in one bulk call, after the objects are written.'
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
			'contractPosition',
			'targetIdPosition',
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
				'help' => $this->l10n->t('The synchronization the committed contracts belong to.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/synchronization',
			],
			[
				'key' => 'contractPosition',
				'label' => $this->l10n->t('Decision path'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Dot-path to the decision block the contract step wrote. Defaults to "contract".'
				),
			],
			[
				'key' => 'targetIdPosition',
				'label' => $this->l10n->t('Target id path'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Dot-path to the written object\'s uuid on each item. Defaults to "uuid".'
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

		$this->assertOptionalPath(config: $config, key: 'contractPosition');
		$this->assertOptionalPath(config: $config, key: 'targetIdPosition');

		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Commit the page's contracts in one bulk upsert.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (carries the run owner).
	 *
	 * @return array The output items, one per input item, in input order.
	 *
	 * @throws FlowNodeException On an unattributed run, an uncommittable update
	 *                           under `onError: stop`, or a failed bulk upsert.
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
				return $this->commitPage(items: $items, config: $config, context: $context);
			}
		);

	}//end execute()

	/**
	 * Build every committable payload, persist ONCE, then stamp the items.
	 *
	 * An empty batch — a page of skips — makes no service call at all: the
	 * items pass through and the run's write count stays zero.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException When a payload cannot be built under `stop`,
	 *                           or when the bulk upsert itself fails.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function commitPage(array $items, array $config, array $context): array {
		$reference = trim((string)$config['synchronization']);
		$contractPosition = $this->pathOrDefault(config: $config, key: 'contractPosition', default: self::DEFAULT_CONTRACT_POSITION);
		$targetIdPosition = $this->pathOrDefault(config: $config, key: 'targetIdPosition', default: self::DEFAULT_TARGET_ID_POSITION);
		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);
		$now = (new DateTime())->format(DateTime::ATOM);
		$indexed = array_values($items);

		// PASS 1 — build the payloads. Nothing is persisted until the whole
		// page has been read, so the upsert stays ONE call.
		$payloads = [];
		$plans = [];
		foreach ($indexed as $index => $item) {
			$json = (array)($item['json'] ?? []);
			$decision = FlowTemplate::lookup(path: $contractPosition, json: $json);
			$outcome = '';
			if (is_array($decision) === true) {
				$outcome = (string)($decision['outcome'] ?? '');
			}

			if (in_array($outcome, self::COMMITTABLE_OUTCOMES, true) === false) {
				// `skip`, `invalid`, or no decision at all: pass through.
				$plans[$index] = null;
				continue;
			}

			try {
				$payload = $this->contractPayload(
					decision: $decision,
					json: $json,
					reference: $reference,
					targetIdPosition: $targetIdPosition,
					outcome: $outcome,
					now: $now
				);
			} catch (FlowNodeException $failure) {
				$this->logger->error(
					'[openconnector.contract-commit] ' . $failure->getMessage(),
					[
						'file' => __FILE__,
						'line' => __LINE__,
						'step' => $stepId,
						'synchronization' => $reference,
						'item' => $index,
					]
				);

				// `dead_letter` is treated like `continue` here; the capture is
				// engine-side wiring (see class docblock).
				if ($onError === 'stop') {
					throw $failure;
				}

				$plans[$index] = $failure;
				continue;
			}//end try

			$plans[$index] = (string)$payload['uuid'];
			$payloads[] = $payload;
		}//end foreach

		$this->persistPage(payloads: $payloads, reference: $reference);

		return $this->stampItems(
			indexed: $indexed,
			plans: $plans,
			contractPosition: $contractPosition,
			stepId: $stepId,
			reference: $reference
		);
	}//end commitPage()

	/**
	 * Build one contract payload from an item's decision block.
	 *
	 * `persistBulk()` sets the OpenRegister row id from the uuid itself, so
	 * the uuid is the whole identity: a fresh v4 for `create` (the same
	 * generator `SynchronizationService` uses), the decision's own
	 * `contractUuid` for `update`.
	 *
	 * @param array $decision The decision block the contract step wrote.
	 * @param array $json The item's record.
	 * @param string $reference The authored synchronization reference.
	 * @param string $targetIdPosition Dot-path to the written object's uuid.
	 * @param string $outcome The decision outcome (`create` or `update`).
	 * @param string $now The commit timestamp, ISO 8601.
	 *
	 * @return array<string, mixed> The contract payload.
	 *
	 * @throws FlowNodeException When an `update` decision names no contract uuid.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function contractPayload(
		array $decision,
		array $json,
		string $reference,
		string $targetIdPosition,
		string $outcome,
		string $now,
	): array {
		$uuid = (string)Uuid::v4();
		$action = 'created';

		if ($outcome === 'update') {
			$uuid = trim((string)($decision['contractUuid'] ?? ''));
			if ($uuid === '') {
				throw new FlowNodeException(
					message: $this->l10n->t(
						'An "update" decision carries no contract uuid; committing it would create a duplicate '
						. 'contract for origin "%1$s" instead of updating the existing one.',
						[(string)($decision['originId'] ?? '')]
					),
					details: [
						'kind' => 'contract',
						'outcome' => $outcome,
						'originId' => ($decision['originId'] ?? null),
					]
				);
			}

			$action = 'updated';
		}

		$targetId = trim((string)($decision['targetId'] ?? ''));
		if ($targetId === '') {
			$raw = FlowTemplate::lookup(path: $targetIdPosition, json: $json);
			if (is_scalar($raw) === true) {
				$targetId = trim((string)$raw);
			}
		}

		return [
			'uuid' => $uuid,
			'synchronizationId' => $reference,
			'originId' => ($decision['originId'] ?? null),
			'originHash' => ($decision['originHash'] ?? null),
			'targetId' => $this->nullIfEmpty(value: $targetId),
			'targetLastAction' => $action,
			'sourceLastChecked' => $now,
			'sourceLastSynced' => $now,
			'targetLastSynced' => $now,
		];
	}//end contractPayload()

	/**
	 * Persist the page's payloads in ONE bulk call, or raise.
	 *
	 * @param array<int, array> $payloads The contract payloads.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When the bulk upsert fails — a page whose
	 *                           contracts half-persisted must never report
	 *                           itself committed.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function persistPage(array $payloads, string $reference): void {
		if ($payloads === []) {
			return;
		}

		try {
			$this->synchronizationContractService->persistBulk($payloads);
		} catch (Throwable $exception) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The bulk contract upsert for synchronization "%1$s" failed: %2$s',
					[$reference, $exception->getMessage()]
				),
				details: ['kind' => 'contract', 'synchronization' => $reference],
				previous: $exception
			);
		}

	}//end persistPage()

	/**
	 * Build the output items, stamping each committed decision block.
	 *
	 * @param array $indexed The input items, re-indexed from zero.
	 * @param array<int, string|FlowNodeException|null> $plans Per item: the
	 *        committed contract uuid, the failure to record, or null for a
	 *        pass-through.
	 * @param string $contractPosition Dot-path to the decision block.
	 * @param string $stepId The step id, for item-borne error state.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function stampItems(
		array $indexed,
		array $plans,
		string $contractPosition,
		string $stepId,
		string $reference,
	): array {
		$outputList = [];
		foreach ($indexed as $index => $item) {
			$json = (array)($item['json'] ?? []);
			$plan = ($plans[$index] ?? null);

			if (is_string($plan) === true) {
				$decision = (array)FlowTemplate::lookup(path: $contractPosition, json: $json);
				$decision['committed'] = true;
				$decision['contractUuid'] = $plan;
				$json = FlowTemplate::write(json: $json, path: $contractPosition, value: $decision);
			} elseif ($plan instanceof FlowNodeException) {
				$json[FlowNodeSupport::ERROR_KEY] = [
					'step' => $stepId,
					'node' => self::NODE_ID,
					'kind' => 'contract',
					'message' => $plan->getMessage(),
					'synchronization' => $reference,
				];
			}

			$outputList[] = FlowItems::item(
				json: $json,
				binary: (array)($item['binary'] ?? []),
				fromItemIndex: $index
			);
		}//end foreach

		return $outputList;
	}//end stampItems()

	/**
	 * Null for an empty string, the value otherwise.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null The value, or null when empty.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()

	/**
	 * Read an optional dot-path config value, falling back to its default.
	 *
	 * @param array $config The step's authored configuration.
	 * @param string $key The config key.
	 * @param string $default The default path.
	 *
	 * @return string The path to use.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function pathOrDefault(array $config, string $key, string $default): string {
		$value = trim((string)($config[$key] ?? ''));
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end pathOrDefault()

	/**
	 * Reject an optional config key that is set but not a usable dot-path.
	 *
	 * @param array $config The step's authored configuration.
	 * @param string $key The config key.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is set but unusable.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function assertOptionalPath(array $config, string $key): void {
		if (array_key_exists($key, $config) === false) {
			return;
		}

		if (is_string($config[$key]) === false || trim($config[$key]) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('The "%1$s" field must be a non-empty dot-path when set.', [$key])
			);
		}

	}//end assertOptionalPath()
}//end class
