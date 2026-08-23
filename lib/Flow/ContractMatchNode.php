<?php

/**
 * Integriq Contract Match flow node.
 *
 * `openconnector.contract` — the "[Contract the page]" step of the decomposed
 * synchronization flow: ONE bulk contract lookup for the whole page, a hash
 * compare per item, and a stamped decision (`create` / `update` / `skip` /
 * `invalid`) on every item, in input order.
 *
 * WHY ONE LOOKUP PER PAGE
 * -----------------------
 * The database round-trip is the sync's real cost. A per-item contract read
 * costs one query per source record; this node collects the page's origin ids
 * first and issues a single `IN (originIds…)` filter through
 * `SynchronizationContractService::findAllObjects()` — the same mechanism
 * `SynchronizationService::indexContractsByOrigin()` proved out. The page is
 * the batching knob, so the IN list stays bounded and planable.
 *
 * WHY THE HASH COMPARE HAPPENS HERE
 * ---------------------------------
 * Idempotency stays contract-based, BEFORE the write path: an unchanged object
 * costs this lookup and nothing else, so a downstream filter can drop `skip`
 * decisions and a re-run of 2000 unchanged objects performs zero writes. The
 * hash is `md5(serialize(...))` over a recursively key-sorted copy — the exact
 * recipe `SynchronizationService::hashObject()` uses, so a contract written by
 * the legacy engine compares equal here.
 *
 * WHY `skip` NEEDS MORE THAN AN EQUAL HASH
 * ----------------------------------------
 * An equal origin hash on a contract whose `targetId` or `targetHash` is
 * missing means the source is unchanged but the TARGET side never completed —
 * skipping it would silently perpetuate a half-synced object. Such contracts
 * decide `update`.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
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

namespace OCA\Integriq\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCA\Integriq\Service\SynchronizationContractService;
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
 * Stamps every item in the page with a contract decision, from one bulk lookup.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#1-engine-steps-each-a-thin-adapter-over-a-kept-service
 */
class ContractMatchNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 *
	 * FROZEN on `openconnector.*` across the openconnector -> integriq app-id
	 * rename: this value is written into stored flow documents (OpenRegister
	 * objects). Renaming it makes every existing flow reference a node type
	 * nothing answers to.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.contract';

	/**
	 * The item key an unset `output` writes the decision block under.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTPUT_KEY = 'contract';

	/**
	 * The dot-path an unset `idPosition` reads the origin id from.
	 *
	 * @var string
	 */
	private const DEFAULT_ID_POSITION = 'id';

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
		return $this->l10n->t('Match contracts');
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
			'Look up the page\'s synchronization contracts in one query and stamp every item create, update or skip.'
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
		return $this->urlGenerator->imagePath('integriq', 'flow-synchronization-run.svg');
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
			'idPosition',
			'hashPosition',
			'output',
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
				'help' => $this->l10n->t('The synchronization whose contracts scope this page.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/synchronization',
			],
			[
				'key' => 'idPosition',
				'label' => $this->l10n->t('Origin id path'),
				'type' => 'text',
				'help' => $this->l10n->t('Dot-path to the origin id on each item. Defaults to "id".'),
			],
			[
				'key' => 'hashPosition',
				'label' => $this->l10n->t('Hash path'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Dot-path to the value the change hash is computed over. Leave empty to hash the whole item.'
				),
			],
			[
				'key' => 'output',
				'label' => $this->l10n->t('Output key'),
				'type' => 'text',
				'help' => $this->l10n->t('Item key the decision block is written under. Defaults to "contract".'),
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

		$this->assertOptionalPath(config: $config, key: 'idPosition');
		$this->assertOptionalPath(config: $config, key: 'hashPosition');

		if (array_key_exists('output', $config) === true) {
			FlowConfigGuard::assertOutputKeyAllowed(outputKey: (string)$config['output'], l10n: $this->l10n);
		}

		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Stamp every item with its contract decision.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (carries the run owner).
	 *
	 * @return array The output items, one per input item, in input order.
	 *
	 * @throws FlowNodeException On an unattributed run or a failed bulk lookup.
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
				return $this->matchPage(items: $items, config: $config, context: $context);
			}
		);

	}//end execute()

	/**
	 * Collect the page's origin ids, look them all up ONCE, decide per item.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException When the bulk lookup fails.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function matchPage(array $items, array $config, array $context): array {
		$reference = trim((string)$config['synchronization']);
		$idPosition = $this->pathOrDefault(config: $config, key: 'idPosition', default: self::DEFAULT_ID_POSITION);
		$hashPosition = trim((string)($config['hashPosition'] ?? ''));
		$outputKey = $this->pathOrDefault(config: $config, key: 'output', default: self::DEFAULT_OUTPUT_KEY);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);
		$indexed = array_values($items);

		// PASS 1 — read every item's origin id. An item without one is decided
		// `invalid` and is never sent to the lookup: an empty id in an IN
		// filter matches nothing and would only widen the query.
		$originIds = [];
		foreach ($indexed as $index => $item) {
			$originIds[$index] = $this->originIdOf(item: $item, idPosition: $idPosition);
		}

		$contracts = $this->lookupContracts(
			reference: $reference,
			originIds: array_values(array_unique(array_filter($originIds))),
			stepId: $stepId
		);

		// PASS 2 — decide per item, in input order.
		$outputList = [];
		foreach ($indexed as $index => $item) {
			$json = (array)($item['json'] ?? []);
			$originId = $originIds[$index];

			// An item without an origin id is decided `invalid` outright.
			$decision = ['outcome' => 'invalid'];
			if ($originId !== '') {
				$decision = $this->decisionFor(
					json: $json,
					originId: $originId,
					contract: ($contracts[$originId] ?? null),
					hashPosition: $hashPosition
				);
			}

			$outputList[] = FlowItems::item(
				json: FlowTemplate::write(json: $json, path: $outputKey, value: $decision),
				binary: (array)($item['binary'] ?? []),
				fromItemIndex: $index
			);
		}

		return $outputList;
	}//end matchPage()

	/**
	 * Read one item's origin id, as a trimmed string.
	 *
	 * A non-scalar value (an object standing where an id should be) reads as
	 * missing, so it lands in the `invalid` decision rather than serialising
	 * into a nonsense filter value.
	 *
	 * @param array $item The input item.
	 * @param string $idPosition The dot-path to the origin id.
	 *
	 * @return string The origin id, or the empty string when there is none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function originIdOf(array $item, string $idPosition): string {
		$raw = FlowTemplate::lookup(path: $idPosition, json: (array)($item['json'] ?? []));
		if (is_scalar($raw) === false) {
			return '';
		}

		return trim((string)$raw);
	}//end originIdOf()

	/**
	 * ONE bulk contract lookup for the whole page, indexed by origin id.
	 *
	 * The `originId` filter carries the page's ids as an array — the IN-style
	 * filter `SynchronizationService::indexContractsByOrigin()` already relies
	 * on. The first contract per origin id wins; the page decision does not
	 * need the duplicate-detection the engine's own indexer feeds.
	 *
	 * @param string $reference The authored synchronization reference.
	 * @param array<int, string> $originIds The page's origin ids, deduplicated.
	 * @param string $stepId The step id, for the failure message.
	 *
	 * @return array<string, array> Origin id => contract payload.
	 *
	 * @throws FlowNodeException When the lookup fails — a page decided against
	 *                           a HALF-READ contract set would stamp `create`
	 *                           on rows that have contracts.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function lookupContracts(string $reference, array $originIds, string $stepId): array {
		if ($originIds === []) {
			return [];
		}

		try {
			$matches = $this->synchronizationContractService->findAllObjects(
				['synchronizationId' => $reference, 'originId' => $originIds]
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[openconnector.contract] The bulk contract lookup failed.',
				[
					'file' => __FILE__,
					'line' => __LINE__,
					'step' => $stepId,
					'synchronization' => $reference,
				]
			);

			throw new FlowNodeException(
				message: $this->l10n->t(
					'The contract lookup for synchronization "%1$s" failed: %2$s. No decisions were stamped.',
					[$reference, $exception->getMessage()]
				),
				details: ['kind' => 'contract', 'synchronization' => $reference],
				previous: $exception
			);
		}//end try

		$index = [];
		foreach ($matches as $match) {
			$payload = $match->jsonSerialize();
			$key = (string)($payload['originId'] ?? '');
			if ($key === '' || array_key_exists($key, $index) === true) {
				continue;
			}

			$index[$key] = $payload;
		}

		return $index;
	}//end lookupContracts()

	/**
	 * Decide one item against its contract, and build the decision block.
	 *
	 * @param array $json The item's record.
	 * @param string $originId The item's origin id.
	 * @param array|null $contract The contract payload, or null when there is none.
	 * @param string $hashPosition Dot-path feeding the hash, or empty for the whole record.
	 *
	 * @return array<string, mixed> The decision block.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function decisionFor(array $json, string $originId, ?array $contract, string $hashPosition): array {
		$hashInput = $json;
		if ($hashPosition !== '') {
			$hashInput = FlowTemplate::lookup(path: $hashPosition, json: $json);
		}

		$originHash = md5(serialize($this->sortNested(value: $hashInput)));

		$decision = [
			'outcome' => 'create',
			'originId' => $originId,
			'originHash' => $originHash,
		];

		if ($contract === null) {
			return $decision;
		}

		$decision['outcome'] = 'update';
		if ($this->isUnchanged(contract: $contract, originHash: $originHash) === true) {
			$decision['outcome'] = 'skip';
		}

		$contractUuid = (string)($contract['uuid'] ?? ($contract['id'] ?? ''));
		if ($contractUuid !== '') {
			$decision['contractUuid'] = $contractUuid;
		}

		if (($contract['targetId'] ?? null) !== null && ($contract['targetId'] ?? null) !== '') {
			$decision['targetId'] = $contract['targetId'];
		}

		return $decision;
	}//end decisionFor()

	/**
	 * Whether a contract proves its object unchanged AND completed.
	 *
	 * An equal hash alone is not enough: a contract missing its targetId or
	 * targetHash never completed on the target side, and skipping it would
	 * silently perpetuate a half-synced object (class docblock).
	 *
	 * @param array $contract The contract payload.
	 * @param string $originHash The item's freshly computed origin hash.
	 *
	 * @return boolean Whether the item may be skipped.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function isUnchanged(array $contract, string $originHash): bool {
		return (($contract['originHash'] ?? null) === $originHash
			&& ($contract['targetId'] ?? null) !== null
			&& ($contract['targetId'] ?? null) !== ''
			&& ($contract['targetHash'] ?? null) !== null
			&& ($contract['targetHash'] ?? null) !== '');
	}//end isUnchanged()

	/**
	 * Recursively key-sort a value, so key order cannot influence the hash.
	 *
	 * Mirrors `SynchronizationService::sortNestedArray()` exactly — a contract
	 * hashed by the legacy engine must compare equal here — but returns the
	 * sorted copy instead of mutating by reference, which keeps the caller's
	 * record untouched.
	 *
	 * @param mixed $value The value to sort.
	 *
	 * @return mixed The sorted value; a non-array passes through unchanged.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function sortNested(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		ksort($value);
		foreach (array_keys($value) as $key) {
			$value[$key] = $this->sortNested(value: $value[$key]);
		}

		return $value;
	}//end sortNested()

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
