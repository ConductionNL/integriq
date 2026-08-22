<?php

/**
 * OpenConnector Fetch File flow node.
 *
 * `openconnector.fetch-file` — runs one configured `fetch_file` Rule against
 * every item in a page, through the SAME `SynchronizationService` code path the
 * legacy engine uses.
 *
 * WHY THIS NODE EXISTS, WITH THE NUMBER THAT JUSTIFIES IT
 * ------------------------------------------------------
 * `SynchronizationFlowGenerator` refused any synchronization declaring
 * `actions`, because no generated step evaluated them. Measured across all 240
 * synchronizations and all 61 rules on the dev instance: EVERY ONE of the 74
 * synchronizations blocked that way references exactly ONE rule, and all 74 of
 * those rules are `fetch_file` / `after`. Instance-wide the rule types are
 * `fetch_file/after` 59, `authentication/before` 1, `(empty)/before` 1.
 *
 * So the thing standing between this change and its last third was never a
 * general rule engine, and never the per-item branching the engine cannot do.
 * It was one step.
 *
 * WHY IT NAMES A RULE INSTEAD OF INLINING THE CONFIG
 * --------------------------------------------------
 * The step carries a rule REFERENCE, exactly as `apply-mapping` carries a
 * mapping reference. Inlining `configuration.fetch_file` into the flow document
 * would fork the configuration: the same fetch would exist twice, and an
 * operator fixing an endpoint on the Rule would silently not fix the flow. A
 * reference also means a HALF-MIGRATED instance stays coherent — the legacy
 * synchronization and the generated flow act on the same entity, so editing it
 * keeps affecting both.
 *
 * WHY IT RUNS AFTER THE WRITE
 * ---------------------------
 * A `fetch_file` rule is an `after` rule: it attaches files to an object that
 * must already exist, and it needs that object's id. In the decomposed pipeline
 * the id is put on the item by the `syncedId` set-fields step that follows
 * `object-write`, which is why `objectIdPath` defaults to it.
 *
 * ⚠️ THE FETCH IS FIRE-AND-FORGET, AND THAT IS INHERITED, NOT INTRODUCED.
 * `processFetchFileRule()` returns as soon as the fetch is STARTED — inline for
 * a `sync` source, or via a QueuedJob for an `async` one — and writes
 * placeholder values into the record. So a green step means "the fetch was
 * dispatched", NOT "the files are present". Anything downstream reasoning about
 * a missing file — a deletion sweep above all — must keep treating "not fetched
 * yet" and "gone from the source" as different facts. This node deliberately
 * does not paper over that with a wait: doing so here would change the
 * behaviour of a migrated synchronization relative to the one it replaced,
 * which is the one thing a migration must not do.
 *
 * @category Service
 * @package  OCA\OpenConnector\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenConnector.app
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
use OCA\OpenRegister\Service\Flow\IFlowNodeLogActions;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Runs one `fetch_file` rule over a page of items.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
class FetchFileNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeLogActions {

	use SynchronizationLogActions;

	/**
	 * The step type.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.fetch-file';

	/**
	 * Where the written object's id sits when the step does not say otherwise.
	 *
	 * The `syncedId` set-fields step after `object-write` puts it there, so the
	 * default is the pipeline the generator emits rather than a guess.
	 *
	 * @var string
	 */
	private const DEFAULT_OBJECT_ID_PATH = 'syncedId';

	/**
	 * Constructor.
	 *
	 * @param SynchronizationService $synchronizations The engine that owns the fetch.
	 * @param FlowOwner              $flowOwner        Resolves whose permissions the step runs with.
	 * @param IL10N                  $l10n             Translations.
	 * @param IURLGenerator          $urlGenerator     For the palette icon and log links.
	 * @param LoggerInterface        $logger           Diagnostics.
	 */
	public function __construct(
		private readonly SynchronizationService $synchronizations,
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
	 * The palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Fetch files');

	}//end getDisplayName()

	/**
	 * What the step does, in the palette.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Runs a configured fetch-file rule against every record in the page, attaching its files to the '
			. 'object that was just written. The fetch is started, not awaited.'
		);

	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string The icon.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getIcon(): string {
		return 'paperclip';

	}//end getIcon()

	/**
	 * Whether the node is offered in the given scope.
	 *
	 * Answered with Nextcloud's own constants and false for anything else — an
	 * unrecognised scope is not a reason to offer a node that makes outbound
	 * calls and writes files.
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
	 * The config vocabulary of a fetch-file step.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function configKeys(): array {
		return ['rule', 'objectIdPath', 'synchronization', 'onError'];

	}//end configKeys()

	/**
	 * The fields this node is edited through.
	 *
	 * `rule` is a picker over the app's own rules rather than a uuid box, for
	 * the same reason every other reference in these nodes is.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'rule',
				'label' => $this->l10n->t('Fetch-file rule'),
				'type' => 'select',
				'help' => $this->l10n->t('The configured rule describing which files to fetch, and from where.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/rule',
			],
			[
				'key' => 'objectIdPath',
				'label' => $this->l10n->t('Object id path'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Dot-path to the written object\'s id on each record. Defaults to "syncedId", which the step '
					. 'after the write sets.'
				),
			],
			[
				'key' => 'synchronization',
				'label' => $this->l10n->t('Synchronization'),
				'type' => 'select',
				'help' => $this->l10n->t('Recorded so the run log can link back. It does not change what is fetched.'),
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/synchronization',
			],
		];

	}//end configForm()

	/**
	 * Refuse a configuration this node cannot honour.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the rule reference is missing.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['rule'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('A fetch-file step needs a rule to run: set "rule" to a configured fetch-file rule.')
			);
		}

	}//end validateConfig()

	/**
	 * Run the rule over every item in the page.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function execute(array $items, array $config, array $context): array {
		// An empty page fetches nothing and produces no items — the filter
		// contract, not a failure.
		if ($items === []) {
			return [];
		}

		$this->validateConfig(config: $config);

		$owner = $this->flowOwner->resolve(context: $context, nodeId: self::NODE_ID);

		return $this->flowOwner->runAs(
			user: $owner,
			callback: function () use ($items, $config, $context) {
				return $this->fetchForEachItem(items: $items, config: $config, context: $context);
			}
		);

	}//end execute()

	/**
	 * Run the rule once per item, serially and in input order.
	 *
	 * Serial on purpose. The fetch itself is already fire-and-forget — the
	 * service dispatches and returns — so a concurrency pool here would
	 * parallelise the DISPATCH and buy nothing, while reordering failures.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function fetchForEachItem(array $items, array $config, array $context): array {
		$rule = trim((string)$config['rule']);
		$idPath = trim((string)($config['objectIdPath'] ?? ''));
		if ($idPath === '') {
			$idPath = self::DEFAULT_OBJECT_ID_PATH;
		}

		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);

		$outputList = [];
		foreach (array_values($items) as $index => $item) {
			$json = (array)($item['json'] ?? []);

			try {
				$json = $this->fetchOne(json: $json, rule: $rule, idPath: $idPath);
			} catch (Throwable $exception) {
				$failure = $this->asNodeFailure(exception: $exception, rule: $rule);

				$this->logger->error(
					'[openconnector.fetch-file] ' . $failure->getMessage(),
					[
						'file' => __FILE__,
						'line' => __LINE__,
						'step' => $stepId,
						'rule' => $rule,
						'item' => $index,
					]
				);

				if ($onError === 'stop') {
					throw $failure;
				}

				$json[FlowNodeSupport::ERROR_KEY] = [
					'step' => $stepId,
					'node' => self::NODE_ID,
					'kind' => 'fetch-file',
					'message' => $failure->getMessage(),
					'rule' => $rule,
				];
			}//end try

			$outputList[] = FlowItems::item(
				json: $json,
				binary: (array)($item['binary'] ?? []),
				fromItemIndex: $index
			);
		}//end foreach

		return $outputList;

	}//end fetchForEachItem()

	/**
	 * Run the rule for one record.
	 *
	 * A record with NO object id is skipped rather than fetched. An `after`
	 * rule attaches files to an object; with nothing to attach them to the
	 * service would fetch into the void and report success. That happens
	 * legitimately — a skipped item never reaches `object-write` — so it is not
	 * an error, but it must not look like work either.
	 *
	 * @param array  $json   The record.
	 * @param string $rule   The rule reference.
	 * @param string $idPath Dot-path to the written object's id.
	 *
	 * @return array The record, with placeholders applied when configured.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function fetchOne(array $json, string $rule, string $idPath): array {
		$raw = FlowTemplate::lookup(path: $idPath, json: $json);
		$objectId = '';
		if (is_scalar($raw) === true) {
			$objectId = trim((string)$raw);
		}

		if ($objectId === '') {
			return $json;
		}

		return $this->synchronizations->runFetchFileRule(
			ruleId: $rule,
			data: $json,
			objectId: $objectId
		);

	}//end fetchOne()

	/**
	 * Wrap a failure so the run log names the rule that produced it.
	 *
	 * @param Throwable $exception The original failure.
	 * @param string    $rule      The rule reference.
	 *
	 * @return FlowNodeException The wrapped failure.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function asNodeFailure(Throwable $exception, string $rule): FlowNodeException {
		if ($exception instanceof FlowNodeException) {
			return $exception;
		}

		return new FlowNodeException(
			message: $this->l10n->t(
				'Fetch-file rule "%1$s" failed: %2$s',
				[$rule, $exception->getMessage()]
			),
			// Structured detail a downstream step can branch on, rather than
			// leaving it to be parsed back out of a translated sentence.
			details: ['kind' => 'fetch-file', 'rule' => $rule],
			previous: $exception
		);

	}//end asNodeFailure()
}//end class
