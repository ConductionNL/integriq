<?php

/**
 * Integriq Source Call flow node.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `openconnector.source-call` — one governed outbound HTTP request per flow
 * item, made through a CONFIGURED SOURCE and the existing `CallService`.
 *
 * WHY A SOURCE AND NOT A URL
 * --------------------------
 * A node that took a URL would be trivially more convenient and would destroy
 * every property that makes this worth building: no administrator-controlled
 * host list, no rate limiting, no circuit breaker, no credential broker, no
 * CallLog — and a flow document, editable by every flow author, that can be
 * edited into an SSRF primitive. So the node names a Source and a path
 * relative to it, and both the literal and the RENDERED endpoint are checked
 * for containment ({@see FlowConfigGuard::assertEndpointContained()}).
 *
 * WHY NOTHING IS RE-IMPLEMENTED
 * -----------------------------
 * Governance is inherited by DELEGATION, per ADR-011. `CallService::call()`
 * already enforces `isEnabled`, the location guard, rate limiting and the
 * circuit breaker; it already resolves brokered credentials and already writes
 * the CallLog. This node resolves a Source, renders the request from the item,
 * calls, and maps the response. It owns no HTTP client, no retry loop and no
 * authentication path.
 *
 * WHY A FAILURE IS NEVER AN EMPTY SUCCESS
 * ---------------------------------------
 * A non-2xx status, a refused precondition and a transport failure are all
 * failures, and by default they RAISE, so `FlowEngine` applies the step's
 * `onError` policy. The fleet's other contributed node, `HermiqAgentNode`,
 * catches `Throwable` and substitutes an empty string — making a failed turn
 * indistinguishable from an empty answer while the run reports success.
 * OpenRegister's own `IFlowNode` docblock warns against exactly that. It is not
 * reproduced here, and `SourceCallNodeTest` asserts it is not.
 *
 * An author who genuinely wants to branch on a status opts it in through
 * `acceptStatuses`, which puts the intent in the flow document instead of
 * burying it in a downstream conditional.
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

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use OCA\Integriq\Exception\ResponseDecodeException;
use OCA\Integriq\Kernel\FlowNodeIds;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\ResponseDecoderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeLogActions;
use OCA\OpenRegister\Service\ObjectService as OpenRegisterObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Calls a configured Source once per flow item.
 *
 * @spec openspec/changes/integriq-flow-nodes/tasks.md#task-2-sourcecallnode-source-targeting-per-item-execution-response-mapping
 */
class SourceCallNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeLogActions {
	use FlowNodePaletteDefaults;

	/**
	 * Palette icon: app and image path.
	 */
	private const PALETTE_ICON = ['integriq', 'flow-source-call.svg'];

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
	public const NODE_ID = FlowNodeIds::SOURCE_CALL;

	/**
	 * The OpenRegister register Sources live in.
	 *
	 * FROZEN: this is the OpenRegister register SLUG, not this app's id. It
	 * stays `openconnector` across the app-id rename — OpenRegister matches
	 * registers by slug, so renaming it would resolve to a fresh, empty
	 * register and orphan every stored Source.
	 *
	 * @var string
	 */
	private const SOURCE_REGISTER = 'integriq';

	/**
	 * The OpenRegister schema Sources live in.
	 *
	 * @var string
	 */
	private const SOURCE_SCHEMA = 'source';

	/**
	 * The item key an unset `output` writes the response under.
	 *
	 * @var string
	 */
	private const DEFAULT_OUTPUT_KEY = 'response';

	/**
	 * Constructor.
	 *
	 * @param CallServiceInterface $callService The governed outbound call engine.
	 * @param FlowConcurrency $concurrency Bounded, ordered per-item dispatch.
	 * @param OpenRegisterObjectService $objectService Resolves the Source object.
	 * @param FlowOwner $flowOwner Fail-closed run-owner resolution.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 * @param ResponseDecoderInterface $decoder Reads the response body in the step's `decode` mode.
	 */
	public function __construct(
		private readonly CallServiceInterface $callService,
		private readonly FlowConcurrency $concurrency,
		private readonly OpenRegisterObjectService $objectService,
		private readonly FlowOwner $flowOwner,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly ResponseDecoderInterface $decoder,
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
		return $this->l10n->t('Call a source');
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
			'Make one governed API call per item through a configured source, and put the response on the item.'
		);

	}//end getDescription()

	/**
	 * The links a call this node made earns in the run log.
	 *
	 * An operator reading a failed call wants the Source it went to and the
	 * call log it wrote — both of which Integriq owns and OpenRegister
	 * could not know about.
	 *
	 * Read from the step's recorded OUTPUT, which is where `outcomeOf()` puts
	 * `sourceId` and `callLog`, and resolved to a URL NOW rather than stored.
	 * An href written into a log months ago points wherever this app's routes
	 * used to be.
	 *
	 * Only the FIRST item is inspected. A step that called a source per item
	 * wrote one entry per call into its own item, and a log row is one step:
	 * offering fifty links on one row would bury the two that matter, and the
	 * per-item detail is in the payload right beside it.
	 *
	 * An entry with neither id yields NO links, deliberately — not a link to
	 * the Sources list. A link that goes somewhere unrelated is followed once,
	 * and after that none of them are trusted.
	 *
	 * @param array<string, mixed> $entry One entry from a run's log.
	 *
	 * @return array<int, array{label: string, href: string}> The links.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-node-contributes-its-own-run-log-links-req-016
	 */
	public function logActions(array $entry): array {
		$items = (array)($entry['output']['items'] ?? []);
		$json = (array)(($items[0] ?? [])['json'] ?? []);

		// The SPA is a HASH router (`createWebHashHistory`), so a deep link is
		// a fragment on the app root — not a server route. `/sources/{id}` has
		// no route entry at all, and `linkToRoute()` on a name that does not
		// exist throws, which would take out the whole log rather than one
		// link.
		$root = $this->urlGenerator->linkToRoute('integriq.ui.dashboard', ['path' => '']);

		$actions = [];
		$sourceId = trim((string)($json['sourceId'] ?? ''));
		if ($sourceId !== '') {
			$actions[] = [
				'label' => $this->l10n->t('Open the source'),
				'href' => rtrim($root, '/') . '#/sources/' . rawurlencode($sourceId),
			];
		}

		$callLog = trim((string)($json['callLog'] ?? ''));
		if ($callLog !== '') {
			$actions[] = [
				'label' => $this->l10n->t('Open the call log'),
				'href' => rtrim($root, '/') . '#/sources/logs?call=' . rawurlencode($callLog),
			];
		}

		return $actions;
	}//end logActions()

	/**
	 * The config vocabulary of a source call.
	 *
	 * Only `source` is required (validateConfig below enforces that); the rest
	 * shape the request. Naming the vocabulary is what lets the preflight
	 * refuse a key this node would silently ignore — and what lets the flow
	 * editor's step dialog render one field per option instead of a JSON box.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configKeys(): array {
		return [
			'source',
			'endpoint',
			'method',
			'query',
			'headers',
			'body',
			'bodyFrom',
			'output',
			'concurrency',
			'decode',
			'onError',
			'acceptStatuses',
			'responseMapping',
		];
	}//end configKeys()

	/**
	 * The fields this node is edited through.
	 *
	 * `source` is the field that matters most: it is a picker fed by the
	 * app's OWN sources listing, so an author chooses "CKAN production" by
	 * name instead of pasting a uuid into a JSON pane. `query`, `headers`
	 * and `body` are structured values whose shape depends on the source
	 * being called, and the editor's JSON pane represents them honestly
	 * where a flat text field would not — so they are deliberately absent.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'source',
				'label' => $this->l10n->t('Source'),
				'type' => 'select',
				'help' => $this->l10n->t(
					'The configured source this step calls. The call inherits its base URL, authentication and rate limits. '
					. 'A template such as jobboard-{{ json.channel }} picks the source per item.'
				),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/integriq/source',
			],
			[
				'key' => 'endpoint',
				'label' => $this->l10n->t('Endpoint'),
				'type' => 'text',
				'help' => $this->l10n->t('Path appended to the source\'s base URL. It must stay within the source — a path that escapes it is refused.'),
			],
			[
				'key' => 'method',
				'label' => $this->l10n->t('Method'),
				'type' => 'text',
				'help' => $this->l10n->t('The HTTP verb: GET, POST, PUT, PATCH or DELETE. GET when empty.'),
			],
			[
				'key' => 'output',
				'label' => $this->l10n->t('Field to store the response in'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'With a field name, the incoming item is preserved and the response is '
					. 'added under it. Empty means the response replaces the item.'
				),
			],
			[
				'key' => 'decode',
				'label' => $this->l10n->t('Read the response as'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'How the response body is read: auto, json, yaml, base64+yaml, base64+json or text. '
					. 'Auto reads JSON, and YAML when the server says it is YAML. Use yaml for a raw YAML file, '
					. 'and base64+yaml for a file API that returns the file base64-encoded in "content".'
				),
			],
			[
				'key' => 'onError',
				'label' => $this->l10n->t('On error'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'What a failed call does to the run: stop, continue or dead_letter. With continue, '
					. 'the failed item carries the error and the other items go on.'
				),
			],
			[
				'key' => 'concurrency',
				'label' => $this->l10n->t('Concurrent calls'),
				'type' => 'number',
				'help' => $this->l10n->t(
					'How many items are called at once, between 1 and 20. The default of 5 '
					. 'suits most sources; raising it multiplies load on the other side.'
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
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function validateConfig(array $config): void {
		FlowConfigGuard::assertNoForbiddenFields(config: $config, l10n: $this->l10n);

		if (trim((string)($config['source'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('The "source" field must name a configured source (its uuid, slug or reference).')
			);
		}

		$endpoint = (string)($config['endpoint'] ?? '');
		if (trim($endpoint) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('The "endpoint" field must name a path relative to the source.')
			);
		}

		FlowConfigGuard::assertEndpointContained(endpoint: $endpoint, l10n: $this->l10n);

		SourceCallConfigGuard::assertMethod(config: $config, l10n: $this->l10n);
		SourceCallConfigGuard::assertAcceptStatuses(config: $config, l10n: $this->l10n);
		SourceCallConfigGuard::assertRequestParts(config: $config, l10n: $this->l10n);
		SourceCallConfigGuard::assertBodyFrom(config: $config, l10n: $this->l10n);
		SourceCallConfigGuard::assertOnError(config: $config, l10n: $this->l10n);
		SourceCallConfigGuard::assertDecode(config: $config, l10n: $this->l10n);

		// Output keys stay here rather than moving to SourceCallConfigGuard
		// with their four siblings: this one is not the node's own vocabulary
		// but a delegation to the SHARED FlowConfigGuard, so it belongs beside
		// the two FlowConfigGuard calls above rather than in the class that
		// holds what only a source-call step may say.
		$this->assertOutput(config: $config);

	}//end validateConfig()

	/**
	 * Call the source once per item and return one item per input item.
	 *
	 * @param FlowNodeCall $call The input items, the step's configuration and the run context.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException On any failure the author has not opted into.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		// An empty branch makes no call and produces no items. That is the
		// filter contract, not a failure, so it short-circuits before the
		// owner is even resolved — there is nothing to attribute.
		if ($call->items === []) {
			return [];
		}

		$this->validateConfig(config: $call->config);

		$owner = $this->flowOwner->resolve(context: $call->context, nodeId: self::NODE_ID);

		// A plain reference resolves once, before any call, exactly as it
		// always did. A templated one names a source per item and resolves in
		// the call pass, one lookup per distinct name.
		$reference = trim((string)$call->config['source']);
		$source = null;
		if (FlowTemplate::hasPlaceholder(value: $reference) === false) {
			$source = $this->resolveSource(reference: $reference);
		}

		return $this->flowOwner->runAs(
			user: $owner,
			callback: function () use ($call, $source) {
				return $this->callForEachItem(run: $this->startRun(call: $call, source: $source));
			}
		);

	}//end execute()

	/**
	 * Resolve, once per step and in the owner context, what every item's call needs.
	 *
	 * @param FlowNodeCall      $call   The input items, the step's configuration and the run context.
	 * @param ObjectEntity|null $source The resolved Source object, or null for a templated reference.
	 *
	 * @return SourceCallRun The step run.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function startRun(FlowNodeCall $call, ?ObjectEntity $source): SourceCallRun {
		$config = $call->config;
		$run = new SourceCallRun(config: $config, source: $source);
		$run->reference = trim((string)$config['source']);
		$run->method = strtoupper(trim((string)($config['method'] ?? 'GET')));
		$run->outputKey = (string)($config['output'] ?? self::DEFAULT_OUTPUT_KEY);
		$run->onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $call->context);
		$run->stepId = FlowNodeSupport::stepId(config: $config, context: $call->context, nodeId: self::NODE_ID);
		$run->accepted = $this->acceptedStatuses(config: $config);
		$run->decode = strtolower(trim((string)($config['decode'] ?? ResponseDecoderInterface::MODE_AUTO)));
		$run->items = array_values($call->items);

		return $run;
	}//end startRun()

	/**
	 * Perform one call per item, in the already-applied owner context.
	 *
	 * @param SourceCallRun $run The step run.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException On any failure the author has not opted into.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function callForEachItem(SourceCallRun $run): array {
		// PASS 1 — render and GUARD every endpoint, before any request is
		// dispatched. See renderAndGuardEndpoints() for why it is its own
		// phase rather than part of the concurrent pass.
		[$endpoints, $records] = $this->renderAndGuardEndpoints(indexed: $run->items, config: $run->config);
		$run->endpoints = $endpoints;
		$run->records = $records;
		$this->resolveItemSources(run: $run);

		// PASS 2 — dispatch the calls CONCURRENTLY, bounded and in input order.
		$run->settled = $this->dispatchCalls(run: $run);

		// PASS 3 — apply the step's policy in INPUT order.
		//
		// Every decision below is the one the serial loop made; only the moment
		// the response arrived has changed. A run that fails on item 3 fails on
		// item 3 whichever order the responses came back in.
		$outputList = [];
		foreach (array_keys($run->items) as $index) {
			$run->index = $index;
			$outputList[] = $this->outputFor(run: $run);
		}

		return $outputList;
	}//end callForEachItem()

	/**
	 * Dispatch every item's call concurrently, bounded and in input order.
	 *
	 * @param SourceCallRun $run The step run, its endpoints and records rendered.
	 *
	 * @return array The settled call per item.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function dispatchCalls(SourceCallRun $run): array {
		return $this->concurrency->map(
			$run->items,
			function (array $item, int $index) use ($run): PromiseInterface {
				// An item whose source did not resolve makes no request; its
				// failure is applied in the policy pass, in input order.
				if (isset($run->unresolved[$index]) === true) {
					return Create::promiseFor(null);
				}

				return $this->callService->callAsync(
					source: $run->sources[$index],
					endpoint: $run->endpoints[$index],
					method: $run->method,
					config: SourceCallRequest::build(config: $run->config, json: $run->records[$index])
				);
			},
			$this->concurrencyLimit(config: $run->config)
		);
	}//end dispatchCalls()

	/**
	 * Apply the step's policy to the current item's settled call.
	 *
	 * @param SourceCallRun $run The step run, `index` naming the item.
	 *
	 * @return array The output item.
	 *
	 * @throws FlowNodeException On a failure the `onError` policy does not absorb.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function outputFor(SourceCallRun $run): array {
		if (isset($run->unresolved[$run->index]) === true) {
			return $this->absorbFailure(run: $run, exception: $run->unresolved[$run->index]);
		}

		try {
			$result = $this->outcomeOf(settled: $run->settled[$run->index], target: $this->targetOf(run: $run));
		} catch (FlowNodeException $exception) {
			return $this->absorbFailure(run: $run, exception: $exception);
		}

		$json = FlowTemplate::write(json: $run->records[$run->index], path: $run->outputKey, value: $result);
		$json = $this->applyResponseMapping(config: $run->config, json: $json, payload: ($result['body'] ?? null));

		return $this->outputItem(run: $run, json: $json);
	}//end outputFor()

	/**
	 * The call target of the current item.
	 *
	 * @param SourceCallRun $run The step run, `index` naming the item.
	 *
	 * @return SourceCallTarget The target.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function targetOf(SourceCallRun $run): SourceCallTarget {
		return new SourceCallTarget(
			reference: $run->references[$run->index],
			source: $run->sources[$run->index],
			endpoint: $run->endpoints[$run->index],
			method: $run->method,
			accepted: $run->accepted,
			decode: $run->decode
		);
	}//end targetOf()

	/**
	 * Log a failed call, and raise it or, under `continue`, carry it as error state.
	 *
	 * @param SourceCallRun     $run       The step run, `index` naming the item.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return array The output item carrying explicit error state.
	 *
	 * @throws FlowNodeException When the `onError` policy does not absorb the failure.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function absorbFailure(SourceCallRun $run, FlowNodeException $exception): array {
		$this->logFailure(run: $run, exception: $exception);

		if ($run->onError !== 'continue') {
			throw $exception;
		}

		return $this->errorItem(run: $run, exception: $exception);
	}//end absorbFailure()

	/**
	 * Log a failed call with the step, source, endpoint and method it concerned.
	 *
	 * @param SourceCallRun     $run       The step run, `index` naming the item.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function logFailure(SourceCallRun $run, FlowNodeException $exception): void {
		$this->logger->error(
			'[openconnector.source-call] ' . $exception->getMessage(),
			[
				'file' => __FILE__,
				'line' => __LINE__,
				'step' => $run->stepId,
				'source' => $run->references[$run->index],
				'endpoint' => $run->endpoints[$run->index],
				'method' => $run->method,
			]
		);
	}//end logFailure()

	/**
	 * An output item: the record, the input item's binary part and its position.
	 *
	 * @param SourceCallRun $run  The step run, `index` naming the item.
	 * @param array         $json The item's record.
	 *
	 * @return array The output item.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function outputItem(SourceCallRun $run, array $json): array {
		return [
			'json' => $json,
			'binary' => (array)($run->items[$run->index]['binary'] ?? []),
			'pairedItem' => ['item' => $run->index],
		];
	}//end outputItem()

	/**
	 * Render every item's endpoint and prove each one is in bounds.
	 *
	 * Its own phase, deliberately not folded into the concurrent pass. A
	 * containment refusal is a refusal of the STEP, not of one item: it
	 * aborts here whatever `onError` says, exactly as it did when the call
	 * loop was serial. Isolating it per item would quietly demote a security
	 * guard into a per-item `continue` — and doing it before dispatch means a
	 * document that names one out-of-bounds endpoint has made NO calls at
	 * all, rather than however many happened to be in flight when it was
	 * found.
	 *
	 * Returning the decoded `json` alongside the endpoints is what keeps that
	 * property: the later passes read the SAME record this one guarded,
	 * rather than decoding the item a second time and risking a different
	 * value reaching the request than the one that was checked.
	 *
	 * @param array $indexed The input items, re-indexed from zero.
	 * @param array $config The step's authored configuration.
	 *
	 * @return array{0: array<int, string>, 1: array<int, array>} The rendered
	 *                                                            endpoint per item, and the decoded `json` per item.
	 *
	 * @throws FlowNodeException When any rendered endpoint escapes its source.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function renderAndGuardEndpoints(array $indexed, array $config): array {
		$endpoints = [];
		$records = [];
		foreach ($indexed as $index => $item) {
			$json = (array)($item['json'] ?? []);

			$records[$index] = $json;
			SourceCallRequest::assertBodyFromResolves(config: $config, json: $json, index: $index, l10n: $this->l10n);
			$endpoints[$index] = FlowTemplate::renderString(
				template: (string)$config['endpoint'],
				json: $json
			);

			// The rendered endpoint is checked again here: a placeholder makes
			// the literal check inconclusive by construction.
			FlowConfigGuard::assertEndpointContained(
				endpoint: $endpoints[$index],
				l10n: $this->l10n,
				rendered: true
			);
		}

		return [$endpoints, $records];
	}//end renderAndGuardEndpoints()

	/**
	 * How many calls this step may have in flight at once.
	 *
	 * Unset means the shared default rather than "one": a flow-based read that
	 * silently ran serially would be the regression this concurrency exists to
	 * avoid. The value is clamped by `FlowConcurrency` itself, so a typo in an
	 * authored document cannot become a burst against an upstream.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return int|null The requested limit, or null for the shared default.
	 */
	private function concurrencyLimit(array $config): ?int {
		if (isset($config['concurrency']) === false) {
			return null;
		}

		return (int)$config['concurrency'];
	}//end concurrencyLimit()

	/**
	 * Normalise one settled call into the result written onto its item.
	 *
	 * This is the second half of what used to be `performCall()`. The dispatch
	 * half moved into the concurrent pass; everything here is unchanged
	 * decision-making, run once per item in INPUT order — so which response
	 * arrived first cannot change which item a run fails on.
	 *
	 * @param array            $settled One `FlowConcurrency::map()` result.
	 * @param SourceCallTarget $target  What the call was made to.
	 *
	 * @return array The response result written onto the item.
	 *
	 * @throws FlowNodeException On a non-accepted status, a transport failure or a body that does not decode.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-node-that-calls-a-source-once-per-item-dispatches-those-calls-concurrently-req-015
	 */
	private function outcomeOf(array $settled, SourceCallTarget $target): array {
		$callLog = $this->callLogOf(settled: $settled, target: $target);

		$body = (array)$callLog->getObject();
		$response = (array)($body['response'] ?? []);
		$statusCode = null;
		if (isset($body['statusCode']) === true) {
			$statusCode = (int)$body['statusCode'];
		} elseif (isset($response['statusCode']) === true) {
			$statusCode = (int)$response['statusCode'];
		}

		$statusMessage = (string)($body['statusMessage'] ?? ($response['statusMessage'] ?? ''));

		if ($this->isSuccess(statusCode: $statusCode, accepted: $target->accepted) === false) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The call to source "%1$s" endpoint "%2$s" returned status %3$s (%4$s), '
					. 'which the step does not accept.',
					[$target->reference, $target->endpoint, (string)($statusCode ?? 'unknown'), $statusMessage]
				),
				details: ['kind' => 'status', 'status' => $statusCode, 'statusMessage' => $statusMessage]
					+ $target->where()
					+ ['callLog' => $callLog->getUuid()]
			);
		}

		return [
			'status' => $statusCode,
			'statusMessage' => $statusMessage,
			'headers' => (array)($response['headers'] ?? []),
			'body' => $this->decodeBody(
				response: $response,
				decode: $target->decode,
				context: [$target->reference, $target->endpoint, $target->method, $statusCode]
			),
			'source' => $target->reference,
			'sourceId' => $target->source->getUuid(),
			'endpoint' => $target->endpoint,
			'method' => $target->method,
			'callLog' => $callLog->getUuid(),
		];

	}//end outcomeOf()

	/**
	 * The call log a settled call produced, or the failure it is.
	 *
	 * A transport-level failure (DNS, TLS, timeout, connection refused) is a
	 * failed call, never an empty response body. `callAsync()` converts every
	 * reason it CAN into a CallLog (a 4xx, a 5xx, a refused connection), so a
	 * rejection here is one that was not convertible at all.
	 *
	 * @param array            $settled One `FlowConcurrency::map()` result.
	 * @param SourceCallTarget $target  What the call was made to.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @throws FlowNodeException On a transport failure or a missing call log.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-node-that-calls-a-source-once-per-item-dispatches-those-calls-concurrently-req-015
	 */
	private function callLogOf(array $settled, SourceCallTarget $target): ObjectEntity {
		if ($settled['ok'] === false) {
			$reason = $settled['error'];

			throw new FlowNodeException(
				message: $this->l10n->t(
					'The call to source "%1$s" endpoint "%2$s" failed at transport level: %3$s',
					[$target->reference, $target->endpoint, $reason->getMessage()]
				),
				details: ['kind' => 'transport', 'status' => null] + $target->where(),
				previous: $reason
			);
		}

		$callLog = $settled['value'];
		if (($callLog instanceof ObjectEntity) === false) {
			// Defensive, and load-bearing: the two dispatch modes are kept in
			// step by `finalizeCall()`, so anything else here means that
			// contract moved. Say so rather than read `getObject()` off it.
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The call to source "%1$s" endpoint "%2$s" did not produce a call log.',
					[$target->reference, $target->endpoint]
				),
				details: ['kind' => 'transport', 'status' => null] + $target->where()
			);
		}

		return $callLog;

	}//end callLogOf()

	/**
	 * Give every item the source it is sent through.
	 *
	 * A plain reference gives every item the step's one source. A templated
	 * reference (for example `jobboard-{{ json.channel }}`) is rendered per
	 * item and each distinct name is looked up once per run. An item whose
	 * name does not resolve fails on its own: under `continue` it becomes an
	 * error item and the other items are still sent; under any other policy
	 * the step refuses before a single request is made, as a plain unknown
	 * source does.
	 *
	 * @param SourceCallRun $run The step run, its records rendered.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When an item's source does not resolve and the policy does not absorb it.
	 *
	 * @spec openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-a-flow-step-can-pick-its-source-and-mapping-per-item-req-jbc-001
	 */
	private function resolveItemSources(SourceCallRun $run): void {
		$resolved = [];
		foreach (array_keys($run->items) as $index) {
			if ($run->source !== null) {
				$run->references[$index] = $run->reference;
				$run->sources[$index] = $run->source;
				continue;
			}

			$name = FlowTemplate::renderReference(template: $run->reference, json: $run->records[$index]);
			$run->references[$index] = $name;

			if (array_key_exists($name, $resolved) === false) {
				$resolved[$name] = $this->resolveItemSource(name: $name);
			}

			if ($resolved[$name] instanceof ObjectEntity) {
				$run->sources[$index] = $resolved[$name];
				continue;
			}

			$run->unresolved[$index] = $resolved[$name];
		}//end foreach

		if ($run->unresolved === [] || $run->onError === 'continue') {
			return;
		}

		$run->index = (int)array_key_first($run->unresolved);
		$this->logFailure(run: $run, exception: $run->unresolved[$run->index]);

		throw $run->unresolved[$run->index];
	}//end resolveItemSources()

	/**
	 * Resolve one item's rendered source name, or say why there is none.
	 *
	 * @param string $name The rendered source name.
	 *
	 * @return ObjectEntity|FlowNodeException The Source, or the item's failure.
	 *
	 * @spec openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-a-flow-step-can-pick-its-source-and-mapping-per-item-req-jbc-001
	 */
	private function resolveItemSource(string $name): ObjectEntity|FlowNodeException {
		$previous = null;
		if ($name !== '') {
			try {
				return $this->resolveSource(reference: $name);
			} catch (FlowNodeException $exception) {
				$previous = $exception;
			}
		}

		return new FlowNodeException(
			message: $this->l10n->t(
				'No source configured: "%1$s". This item makes no request.',
				[$name]
			),
			details: ['kind' => 'source', 'source' => $name],
			previous: $previous
		);
	}//end resolveItemSource()

	/**
	 * Resolve the Source named by the step, or refuse.
	 *
	 * Reads register `openconnector`, schema `source`, and NEVER creates one:
	 * a find-or-create from a flow document is a raw-URL node wearing a
	 * Source's clothes.
	 *
	 * The read bypasses RBAC and multitenancy for the same reason
	 * `SynchronizationService::findSourceObject()` does: the `source` schema is
	 * admin-owned configuration, the ENGINE needs it rather than the user, and
	 * the resolved object never leaves this class — it is handed straight to
	 * `CallService`, which enforces the Source's own preconditions. The run
	 * owner's identity governs the CALL, not this configuration read.
	 *
	 * @param string $reference The authored source reference.
	 *
	 * @return ObjectEntity The resolved Source object.
	 *
	 * @throws FlowNodeException When the reference resolves to no Source.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function resolveSource(string $reference): ObjectEntity {
		$source = null;

		try {
			$source = $this->objectService->find(
				id: $reference,
				register: self::SOURCE_REGISTER,
				schema: self::SOURCE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The source "%1$s" named by this step could not be resolved: %2$s. '
					. 'No source is created and no request is made.',
					[$reference, $exception->getMessage()]
				),
				details: ['kind' => 'source', 'source' => $reference],
				previous: $exception
			);
		}

		if ($source === null) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The source "%1$s" named by this step does not exist. '
					. 'No source is created and no request is made.',
					[$reference]
				),
				details: ['kind' => 'source', 'source' => $reference]
			);
		}

		return $source;
	}//end resolveSource()

	/**
	 * Decode the response body in the step's `decode` mode.
	 *
	 * Unset or `auto` keeps the behaviour this node always had: JSON when it
	 * parses, otherwise the body as it came, and a non-UTF-8 body that
	 * `CallService` stored as base64 is handed on untouched. A named mode asks
	 * for a specific reading, so the stored base64 is undone first and a body
	 * that does not decode FAILS the item rather than becoming an empty value.
	 *
	 * @param array $response The CallLog's response array.
	 * @param string $decode The decode mode.
	 * @param array $context The source reference, endpoint, method and status, for a decode failure.
	 *
	 * @return mixed The decoded payload, or the raw string.
	 *
	 * @throws FlowNodeException When a named mode cannot decode the body.
	 *
	 * @spec openspec/specs/github-publiccode-source/spec.md#requirement-a-file-that-does-not-decode-fails-its-item-req-ghp-003
	 */
	private function decodeBody(array $response, string $decode, array $context): mixed {
		$body = ($response['body'] ?? null);
		$transportBase64 = ((string)($response['encoding'] ?? 'UTF-8') !== 'UTF-8');

		if ($decode === ResponseDecoderInterface::MODE_AUTO || $decode === ResponseDecoderInterface::MODE_TEXT) {
			// `CallService` base64-encodes a body that is not valid UTF-8;
			// handing that to a parser would only produce noise.
			if (is_string($body) === false || $transportBase64 === true) {
				return $body;
			}
		}

		if (is_string($body) === false) {
			$body = '';
		}

		if ($transportBase64 === true) {
			$bytes = base64_decode($body, true);
			if ($bytes !== false) {
				$body = $bytes;
			}
		}

		$contentType = $this->decoder->contentType(headers: (array)($response['headers'] ?? []));

		[$reference, $endpoint, $method, $statusCode] = array_pad($context, 4, null);

		try {
			return $this->decoder->decode(body: $body, mode: $decode, contentType: $contentType);
		} catch (ResponseDecodeException $exception) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The response of source "%1$s" endpoint "%2$s" could not be read as %3$s: %4$s',
					[(string)$reference, (string)$endpoint, $exception->getMode(), $exception->getReason()]
				),
				details: [
					'kind' => 'decode',
					'decode' => $exception->getMode(),
					'status' => $statusCode,
					'source' => $reference,
					'endpoint' => $endpoint,
					'method' => $method,
				],
				previous: $exception
			);
		}

	}//end decodeBody()

	/**
	 * Apply `responseMapping` onto the item's record.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $json The item's record.
	 * @param mixed $payload The decoded response payload.
	 *
	 * @return array The record, with the mapped values written.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function applyResponseMapping(array $config, array $json, mixed $payload): array {
		$mapping = ($config['responseMapping'] ?? null);
		if (is_array($mapping) === false || $mapping === []) {
			return $json;
		}

		foreach ($mapping as $target => $selector) {
			if (is_string($target) === false || is_string($selector) === false) {
				continue;
			}

			$json = FlowTemplate::write(
				json: $json,
				path: $target,
				value: FlowTemplate::select(payload: $payload, selector: $selector)
			);
		}

		return $json;
	}//end applyResponseMapping()

	/**
	 * Build the output item for a failed call under `onError: continue`.
	 *
	 * The output key is deliberately NOT written: a failed item must not be
	 * shaped like a successful one.
	 *
	 * @param SourceCallRun     $run       The step run, `index` naming the item.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return array The output item carrying explicit error state.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function errorItem(SourceCallRun $run, FlowNodeException $exception): array {
		$json = $run->records[$run->index];
		$json[FlowNodeSupport::ERROR_KEY] = $this->errorState(run: $run, exception: $exception);

		return $this->outputItem(run: $run, json: $json);

	}//end errorItem()

	/**
	 * The explicit error state of a failed call.
	 *
	 * @param SourceCallRun     $run       The step run, `index` naming the item.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return array The error state.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function errorState(SourceCallRun $run, FlowNodeException $exception): array {
		$details = $exception->getDetails();

		return [
			'step' => $run->stepId,
			'node' => self::NODE_ID,
			'status' => ($details['status'] ?? null),
			'statusMessage' => ($details['statusMessage'] ?? null),
			'kind' => ($details['kind'] ?? 'call'),
			'message' => $exception->getMessage(),
			'source' => $run->references[$run->index],
			'endpoint' => $run->endpoints[$run->index],
			'method' => $run->method,
		];

	}//end errorState()

	/**
	 * Whether a status counts as a success for this step.
	 *
	 * @param int|null $statusCode The status, or null when there was none.
	 * @param array $accepted Statuses the author opted into.
	 *
	 * @return boolean Whether the call succeeded.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function isSuccess(?int $statusCode, array $accepted): bool {
		if ($statusCode === null) {
			return false;
		}

		if (in_array($statusCode, $accepted, true) === true) {
			return true;
		}

		return ($statusCode >= 200 && $statusCode < 300);
	}//end isSuccess()

	/**
	 * The statuses the author opted into, as integers.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return array<int, int> The accepted statuses.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function acceptedStatuses(array $config): array {
		$accepted = ($config['acceptStatuses'] ?? []);
		if (is_array($accepted) === false) {
			return [];
		}

		return array_map('intval', array_values($accepted));
	}//end acceptedStatuses()

	/**
	 * Reject an output key or mapping target that claims a reserved item key.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a key is reserved.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function assertOutput(array $config): void {
		if (array_key_exists('output', $config) === true) {
			FlowConfigGuard::assertOutputKeyAllowed(outputKey: (string)$config['output'], l10n: $this->l10n);
		}

		$mapping = ($config['responseMapping'] ?? []);
		if (is_array($mapping) === false) {
			return;
		}

		foreach (array_keys($mapping) as $target) {
			if (is_string($target) === false) {
				continue;
			}

			FlowConfigGuard::assertOutputKeyAllowed(outputKey: $target, l10n: $this->l10n);
		}

	}//end assertOutput()
}//end class
