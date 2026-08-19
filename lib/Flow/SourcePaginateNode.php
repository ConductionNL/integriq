<?php

/**
 * OpenConnector Source Paginate flow node.
 *
 * `openconnector.source-paginate` — the "[Fetch a page]" step of the decomposed
 * synchronization flow. It emits ONE FLOW ITEM PER PAGE, each carrying the
 * page's objects as an array, so every downstream step (`apply-mapping`,
 * `contract`, `object-write`, `contract-commit`) pays the engine's fixed
 * per-item overhead once per page rather than once per object. That is the
 * single most important decision in the design, and this node is where it
 * starts.
 *
 * WHY IT NAMES A SYNCHRONIZATION AND NOT A BARE SOURCE
 * ----------------------------------------------------
 * A node taking `source` + `endpoint` + `query` would look more decomposed and
 * would be strictly worse, for two measured reasons.
 *
 * First, the seam it delegates to —
 * {@see SynchronizationService::getAllObjectsFromApi()} — is keyed on a
 * synchronization array, and ENDS by calling `persistSynchronization()` for
 * every non-test run. Handing it an ad-hoc array synthesised from a flow
 * document would therefore SAVE that array as a brand-new Synchronization
 * object on every single execution: a flow that quietly manufactures
 * reviewable-grade configuration, which is the exact anti-pattern
 * `synchronization-run` and `apply-mapping` refuse in their own docblocks
 * ("this step never creates one"). Naming an existing synchronization makes
 * that same write what it has always been — the page cursor being reset on the
 * object it belongs to.
 *
 * Second, the four sibling page nodes (`apply-mapping` excepted, which is
 * keyed on a mapping) all take `synchronization`: `contract`,
 * `contract-commit` and `contract-sweep` need the SAME synchronization's
 * contract configuration that produced the page. A fetch step keyed on a bare
 * source would make one flow name two different things for one sync, and
 * nothing would check that they agreed.
 *
 * WHY THERE IS NO `concurrency` KNOB IN v1
 * ----------------------------------------
 * The design puts bounded-concurrent page fetches here, and they ARE bounded
 * and concurrent — but not by this node. `getAllObjectsFromApi()` fetches the
 * whole result set internally: it predicts the last page from the previous
 * run's contracts, dispatches the remaining pages through its own prefetch
 * pool, buffers the CallLogs, and returns one flat list. There is no per-page
 * fetch left here for `FlowConcurrency` to bound.
 *
 * So this node declares no `concurrency` key. A config key the node accepts
 * and never reads is worse than a missing feature: it reads as a working
 * control, an operator tunes it, and nothing changes — which is precisely the
 * defect `configKeys()` exists to make impossible. v2 is the honest place for
 * it: a node that fetches page N itself, over `CallService::callAsync()` and
 * `FlowConcurrency::map()` the way `source-call` already does, gains a real
 * bound AND the resumable page cursor the design asks for. That is a different
 * node body, not a config key.
 *
 * WHY IT RAISES INSTEAD OF TRUNCATING
 * -----------------------------------
 * A shortened page list is indistinguishable from a complete one at every
 * downstream step — and the step after this one may DELETE what it did not
 * see. So a fetch that would emit more than `maxPages` items raises, naming
 * the counts, rather than returning a prefix.
 *
 * WHY AN EMPTY SOURCE STILL EMITS ONE ITEM
 * ----------------------------------------
 * Emitting nothing would make "fetched, and the source is empty" identical to
 * "never fetched", and the stale sweep downstream distinguishes exactly those
 * two cases before deleting anything. The empty page is emitted with `count`
 * 0 and the same `fetchInfo` block every other page carries.
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
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;
use UnexpectedValueException;

/**
 * Fetches a synchronization's source and emits one flow item per page.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#1-engine-steps-each-a-thin-adapter-over-a-kept-service
 */
class SourcePaginateNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.source-paginate';

	/**
	 * How many objects one emitted page carries by default.
	 *
	 * The design's batching knob, and the only number an operator should ever
	 * need to tune: it decides the size of the single contract SELECT, the
	 * single bulk write and the single contract upsert each downstream step
	 * performs.
	 *
	 * @var int
	 */
	public const DEFAULT_PAGE_SIZE = 100;

	/**
	 * The default ceiling on emitted page items.
	 *
	 * Part of the node's contract: changing it alters behaviour for every flow
	 * that never sets `maxPages`, so it follows the breaking-change policy.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_PAGES = 1000;

	/**
	 * Constructor.
	 *
	 * @param SynchronizationService $synchronizationService The existing fetch engine.
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
		return $this->l10n->t('Fetch pages from a source');
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
			'Fetch a synchronization\'s source and emit one item per page, each carrying that page\'s objects.'
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
	 * Every key here is read by `execute()`. There is deliberately no
	 * `concurrency`, `endpoint`, `query`, `method` or `headers`: the first has
	 * nothing to bound in v1 and the rest belong to the synchronization's own
	 * `sourceConfig`, which is versioned and reviewable where a flow document
	 * is not (see the class docblock).
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function configKeys(): array {
		return [
			'synchronization',
			'pageSize',
			'maxPages',
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
				'help' => $this->l10n->t('The synchronization whose source this step fetches.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/synchronization',
			],
			[
				'key' => 'pageSize',
				'label' => $this->l10n->t('Page size'),
				'type' => 'number',
				'help' => $this->l10n->t(
					'How many objects one emitted page carries. This is the batching knob for every '
					. 'downstream step. Defaults to %1$s.',
					[(string)self::DEFAULT_PAGE_SIZE]
				),
			],
			[
				'key' => 'maxPages',
				'label' => $this->l10n->t('Maximum pages'),
				'type' => 'number',
				'help' => $this->l10n->t(
					'Ceiling on the number of page items this step may emit. A fetch above it fails '
					. 'rather than returning a shortened list. Defaults to %1$s.',
					[(string)self::DEFAULT_MAX_PAGES]
				),
			],
			[
				'key' => 'output',
				'label' => $this->l10n->t('Output key'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Item key the page is written under. Leave empty to make the page the item itself.'
				),
			],
			[
				'key' => 'onError',
				'label' => $this->l10n->t('On error'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'What a failed fetch does to the run: stop, continue or dead_letter.'
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

		$this->assertWholeNumber(config: $config, key: 'pageSize');
		$this->assertWholeNumber(config: $config, key: 'maxPages');

		if (array_key_exists('output', $config) === true) {
			FlowConfigGuard::assertOutputKeyAllowed(outputKey: (string)$config['output'], l10n: $this->l10n);
		}

		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Fetch the source and fan out its pages, in the run owner's context.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (carries the run owner).
	 *
	 * @return array One output item per fetched page, per input item.
	 *
	 * @throws FlowNodeException On a failure the `onError` policy does not absorb.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function execute(array $items, array $config, array $context): array {
		// An empty branch fetches nothing and produces no items — the filter
		// contract, not a failure, so it short-circuits before the owner is
		// resolved: there is nothing to attribute.
		if ($items === []) {
			return [];
		}

		$this->validateConfig(config: $config);

		$owner = $this->flowOwner->resolve(context: $context, nodeId: self::NODE_ID);

		return $this->flowOwner->runAs(
			user: $owner,
			callback: function () use ($items, $config, $context) {
				return $this->paginateForEachItem(items: $items, config: $config, context: $context);
			}
		);

	}//end execute()

	/**
	 * Fetch once per input item and emit that fetch's pages.
	 *
	 * A `FlowSuspension` raised by the rate-limit path is deliberately NOT
	 * caught here: only `FlowNodeException` is. A suspension is neither a
	 * failure nor a completion, and absorbing it into an `onError: continue`
	 * item would read as "this source had nothing" rather than "this source
	 * would not let us look" — the precise misreading that let nine shards
	 * starve while the run reported success.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException When a fetch fails and the policy is `stop`.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function paginateForEachItem(array $items, array $config, array $context): array {
		$reference = trim((string)$config['synchronization']);
		$pageSize = (int)($config['pageSize'] ?? self::DEFAULT_PAGE_SIZE);
		$maxPages = (int)($config['maxPages'] ?? self::DEFAULT_MAX_PAGES);
		$outputKey = trim((string)($config['output'] ?? ''));
		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);

		$emitted = [];
		foreach (array_values($items) as $index => $item) {
			try {
				[$objects, $fetchInfo] = $this->fetch(reference: $reference);
				$pages = array_chunk($objects, $pageSize);

				$this->assertWithinCeiling(
					pageCount: count($pages),
					maxPages: $maxPages,
					stepId: $stepId,
					reference: $reference
				);

				$emitted = array_merge(
					$emitted,
					$this->itemsFor(
						pages: $pages,
						fetchInfo: $fetchInfo,
						item: $item,
						index: $index,
						outputKey: $outputKey,
						reference: $reference,
						pageSize: $pageSize
					)
				);
			} catch (FlowNodeException $exception) {
				$this->logger->error(
					'[openconnector.source-paginate] ' . $exception->getMessage(),
					[
						'file' => __FILE__,
						'line' => __LINE__,
						'step' => $stepId,
						'synchronization' => $reference,
					]
				);

				// `dead_letter` is treated like `continue`: the item carries
				// explicit error state and the dead-letter capture itself is
				// engine-side wiring.
				if ($onError === 'stop') {
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

		return $emitted;
	}//end paginateForEachItem()

	/**
	 * Fetch every object the source holds, through the existing engine.
	 *
	 * The whole point of the node: `getAllObjectsFromApi()` already speaks every
	 * pagination dialect the fleet meets (`next` links, page counters, cursors,
	 * total-count headers, and servers that advertise none of them), already
	 * resolves brokered credentials through `CallService`, already writes the
	 * CallLogs and already reports whether the pass was COMPLETE. Re-deriving
	 * any of that here would be a second implementation to keep in step with
	 * the first, and the completeness verdict is what the stale sweep refuses
	 * to delete without.
	 *
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return array{0: array<int, mixed>, 1: array<string, mixed>} Every object
	 *                                                              the source returned, and the engine's own
	 *                                                              completeness verdict for that pass.
	 *
	 * @throws FlowNodeException When the synchronization or the fetch fails.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function fetch(string $reference): array {
		$info = null;

		try {
			$synchronization = $this->synchronizationService->getSynchronization(id: $reference)->jsonSerialize();

			$objects = $this->synchronizationService->getAllObjectsFromApi(
				synchronization: $synchronization,
				isTest: false,
				data: null,
				fetchInfo: $info,
				resolvedSource: null
			);
		} catch (TooManyRequestsHttpException $rateLimited) {
			// Not a failure and not something to retry immediately. Caught
			// ahead of the generic handler so it can never become a node
			// failure that an `onError: continue` policy skips past.
			throw $this->suspendUntilTheLimitLifts(exception: $rateLimited, reference: $reference);
		} catch (Throwable $exception) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The source of synchronization "%1$s" could not be fetched: %2$s',
					[$reference, $exception->getMessage()]
				),
				details: ['kind' => 'fetch', 'synchronization' => $reference],
				previous: $exception
			);
		}//end try

		$verdict = (array)($info ?? []);

		return [
			array_values((array)$objects),
			[
				'complete' => (bool)($verdict['complete'] ?? true),
				'pagesFetched' => (int)($verdict['pagesFetched'] ?? 0),
				'failureReason' => ($verdict['failureReason'] ?? null),
			],
		];
	}//end fetch()

	/**
	 * Turn a rate limit into a pause rather than the end of the run.
	 *
	 * The mechanism is `synchronization-run`'s, unchanged and shared rather
	 * than reproduced: same `FlowSuspension`, same 60s–3600s bounds, same
	 * source-supplied reset ({@see FlowRateLimit}). The design moves rate-limit
	 * suspension INTO the fetch step, and a second implementation of it here
	 * would be two clamps that drift.
	 *
	 * @param TooManyRequestsHttpException $exception The refusal, carrying the rate-limit headers.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return FlowSuspension The suspension to throw.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-a-rate-limited-synchronization-suspends-the-run-instead-of-ending-it
	 */
	private function suspendUntilTheLimitLifts(
		TooManyRequestsHttpException $exception,
		string $reference,
	): FlowSuspension {
		$suspension = FlowRateLimit::suspensionFor(exception: $exception, subject: $reference);

		$this->logger->info(
			'[openconnector.source-paginate] Rate limited; suspending until the limit lifts.',
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
	 * Build one output item per page.
	 *
	 * A fetch that returned nothing still produces exactly one item, so
	 * "fetched, and the source is empty" stays distinguishable from "never
	 * fetched" — the distinction the stale sweep downstream turns into a
	 * decision about deleting objects.
	 *
	 * @param array $pages The page chunks.
	 * @param array $fetchInfo The engine's completeness verdict.
	 * @param array $item The input item that triggered the fetch.
	 * @param int $index The input item's index.
	 * @param string $outputKey The author-named output key, or empty.
	 * @param string $reference The authored synchronization reference.
	 * @param int $pageSize The configured page size.
	 *
	 * @return array<int, array<string, mixed>> The output items.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function itemsFor(
		array $pages,
		array $fetchInfo,
		array $item,
		int $index,
		string $outputKey,
		string $reference,
		int $pageSize,
	): array {
		if ($pages === []) {
			$pages = [[]];
		}

		$binary = (array)($item['binary'] ?? []);
		$json = (array)($item['json'] ?? []);
		$total = count($pages);

		$items = [];
		foreach ($pages as $position => $results) {
			$payload = [
				'page' => ($position + 1),
				'pages' => $total,
				'results' => $results,
				'count' => count($results),
				'pageSize' => $pageSize,
				'synchronization' => $reference,
				'fetchInfo' => $fetchInfo,
			];

			$items[] = FlowItems::item(
				json: $this->place(json: $json, payload: $payload, outputKey: $outputKey),
				binary: $binary,
				fromItemIndex: $index
			);
		}

		return $items;
	}//end itemsFor()

	/**
	 * Place a page onto its item's record.
	 *
	 * No output key means the page REPLACES the record — the shape the
	 * downstream page steps read directly; a key routes it beside whatever the
	 * trigger put on the item.
	 *
	 * @param array $json The item's record.
	 * @param array $payload The page payload.
	 * @param string $outputKey The author-named output key, or empty.
	 *
	 * @return array The resulting record.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function place(array $json, array $payload, string $outputKey): array {
		if ($outputKey === '') {
			return $payload;
		}

		return FlowTemplate::write(json: $json, path: $outputKey, value: $payload);
	}//end place()

	/**
	 * Refuse a page fan-out above the ceiling — loudly, and without truncating.
	 *
	 * @param int $pageCount The number of page items the step would emit.
	 * @param int $maxPages The configured ceiling.
	 * @param string $stepId The step id.
	 * @param string $reference The authored synchronization reference.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When the ceiling would be exceeded.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function assertWithinCeiling(int $pageCount, int $maxPages, string $stepId, string $reference): void {
		if ($pageCount <= $maxPages) {
			return;
		}

		throw new FlowNodeException(
			message: $this->l10n->t(
				'Step "%1$s" fetched %2$s pages from synchronization "%3$s", which is more than its ceiling of '
				. '%4$s pages. No truncated list is returned — a shortened page list is indistinguishable from a '
				. 'complete one downstream, and the stale sweep deletes what it did not see. Raise "maxPages" or '
				. '"pageSize" on the step if a larger fetch is intended.',
				[$stepId, (string)$pageCount, $reference, (string)$maxPages]
			),
			details: [
				'kind' => 'ceiling',
				'step' => $stepId,
				'synchronization' => $reference,
				'pageCount' => $pageCount,
				'maxPages' => $maxPages,
			]
		);

	}//end assertWithinCeiling()

	/**
	 * Build the output item for a failed fetch under `onError: continue`.
	 *
	 * The page payload is deliberately NOT written: a failed item must not be
	 * shaped like a page, or a downstream step reads an empty `results` array
	 * as an empty source.
	 *
	 * @param array $item The input item.
	 * @param int $index The input item's index.
	 * @param string $stepId The step id.
	 * @param string $reference The authored synchronization reference.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return array The output item carrying explicit error state.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
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

		return FlowItems::item(
			json: $json,
			binary: (array)($item['binary'] ?? []),
			fromItemIndex: $index
		);

	}//end errorItem()

	/**
	 * Reject a numeric field that is not a whole number of at least one.
	 *
	 * @param array $config The step's authored configuration.
	 * @param string $key The config key to check.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is not a positive integer.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function assertWholeNumber(array $config, string $key): void {
		if (array_key_exists($key, $config) === false) {
			return;
		}

		$value = $config[$key];
		if (is_int($value) === false || $value < 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "%1$s" field must be a whole number of at least 1.', [$key])
			);
		}

	}//end assertWholeNumber()
}//end class
