<?php

/**
 * OpenConnector Source Call flow node.
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

use GuzzleHttp\Promise\PromiseInterface;
use OCA\OpenConnector\Exception\FlowNodeException;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeLogActions;
use OCA\OpenRegister\Service\ObjectService as OpenRegisterObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Calls a configured Source once per flow item.
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-2-sourcecallnode-source-targeting-per-item-execution-response-mapping
 */
class SourceCallNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeLogActions {

	/**
	 * The step type this node answers to.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.source-call';

	/**
	 * The OpenRegister register Sources live in.
	 *
	 * @var string
	 */
	private const SOURCE_REGISTER = 'openconnector';

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
	 * @param CallService $callService The governed outbound call engine.
	 * @param FlowConcurrency $concurrency Bounded, ordered per-item dispatch.
	 * @param OpenRegisterObjectService $objectService Resolves the Source object.
	 * @param FlowOwner $flowOwner Fail-closed run-owner resolution.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly FlowConcurrency $concurrency,
		private readonly OpenRegisterObjectService $objectService,
		private readonly FlowOwner $flowOwner,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * App-namespaced, so two apps cannot silently claim one type: the
	 * registry refuses a collision rather than resolving it by load order.
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
		return $this->l10n->t('Call a source');
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
			'Make one governed API call per item through a configured source, and put the response on the item.'
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
		return $this->urlGenerator->imagePath('openconnector', 'flow-source-call.svg');
	}//end getIcon()

	/**
	 * The links a call this node made earns in the run log.
	 *
	 * An operator reading a failed call wants the Source it went to and the
	 * call log it wrote — both of which openconnector owns and OpenRegister
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
		$root = $this->urlGenerator->linkToRoute('openconnector.ui.dashboard', ['path' => '']);

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
	 * Whether the node is offered in the given scope.
	 *
	 * Answered with Nextcloud's own constants, per OpenRegister's convention,
	 * and false for anything else — an unrecognised scope is not a reason to
	 * offer a node that makes outbound calls.
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
	 * The config vocabulary of a source call.
	 *
	 * Only `source` is required (validateConfig below enforces that); the rest
	 * shape the request. Naming the vocabulary is what lets the preflight
	 * refuse a key this node would silently ignore — and what lets the flow
	 * editor's step dialog render one field per option instead of a JSON box.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configKeys(): array {
		return ['source', 'endpoint', 'method', 'query', 'headers', 'body', 'output', 'concurrency'];
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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'source',
				'label' => $this->l10n->t('Source'),
				'type' => 'select',
				'help' => $this->l10n->t('The configured source this step calls. The call inherits its base URL, authentication and rate limits.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/source',
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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
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
		SourceCallConfigGuard::assertOnError(config: $config, l10n: $this->l10n);

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
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (carries the run owner).
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException On any failure the author has not opted into.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
		// An empty branch makes no call and produces no items. That is the
		// filter contract, not a failure, so it short-circuits before the
		// owner is even resolved — there is nothing to attribute.
		if ($items === []) {
			return [];
		}

		$this->validateConfig(config: $config);

		$owner = $this->flowOwner->resolve(context: $context, nodeId: self::NODE_ID);
		$source = $this->resolveSource(reference: trim((string)$config['source']));

		return $this->flowOwner->runAs(
			user: $owner,
			callback: function () use ($items, $config, $context, $source) {
				return $this->callForEachItem(items: $items, config: $config, context: $context, source: $source);
			}
		);

	}//end execute()

	/**
	 * Perform one call per item, in the already-applied owner context.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 * @param ObjectEntity $source The resolved Source object.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException On any failure the author has not opted into.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function callForEachItem(array $items, array $config, array $context, ObjectEntity $source): array {
		$reference = trim((string)$config['source']);
		$method = strtoupper(trim((string)($config['method'] ?? 'GET')));
		$outputKey = (string)($config['output'] ?? self::DEFAULT_OUTPUT_KEY);
		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);
		$accepted = $this->acceptedStatuses(config: $config);
		$outputList = [];
		$indexed = array_values($items);

		// PASS 1 — render and GUARD every endpoint, before any request is
		// dispatched. See renderAndGuardEndpoints() for why it is its own
		// phase rather than part of the concurrent pass.
		[$endpoints, $records] = $this->renderAndGuardEndpoints(indexed: $indexed, config: $config);

		// PASS 2 — dispatch the calls CONCURRENTLY, bounded and in input order.
		$settled = $this->concurrency->map(
			$indexed,
			function (array $item, int $index) use ($source, $endpoints, $records, $method, $config): PromiseInterface {
				return $this->callService->callAsync(
					source: $source,
					endpoint: $endpoints[$index],
					method: $method,
					config: $this->buildRequestConfig(config: $config, json: $records[$index])
				);
			},
			$this->concurrencyLimit(config: $config)
		);

		// PASS 3 — apply the step's policy in INPUT order.
		//
		// Every decision below is the one the serial loop made; only the moment
		// the response arrived has changed. A run that fails on item 3 fails on
		// item 3 whichever order the responses came back in.
		foreach ($indexed as $index => $item) {
			$json = $records[$index];
			$endpoint = $endpoints[$index];

			try {
				$result = $this->outcomeOf(
					settled: $settled[$index],
					endpoint: $endpoint,
					method: $method,
					accepted: $accepted,
					reference: $reference,
					source: $source
				);
			} catch (FlowNodeException $exception) {
				$this->logger->error(
					'[openconnector.source-call] ' . $exception->getMessage(),
					[
						'file' => __FILE__,
						'line' => __LINE__,
						'step' => $stepId,
						'source' => $reference,
						'endpoint' => $endpoint,
						'method' => $method,
					]
				);

				if ($onError !== 'continue') {
					throw $exception;
				}

				$outputList[] = $this->errorItem(
					item: $item,
					index: $index,
					json: $json,
					stepId: $stepId,
					reference: $reference,
					endpoint: $endpoint,
					method: $method,
					exception: $exception
				);
				continue;
			}//end try

			$json = FlowTemplate::write(json: $json, path: $outputKey, value: $result);
			$json = $this->applyResponseMapping(config: $config, json: $json, payload: ($result['body'] ?? null));

			$outputList[] = [
				'json' => $json,
				'binary' => (array)($item['binary'] ?? []),
				'pairedItem' => ['item' => $index],
			];
		}//end foreach

		return $outputList;
	}//end callForEachItem()

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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function renderAndGuardEndpoints(array $indexed, array $config): array {
		$endpoints = [];
		$records = [];
		foreach ($indexed as $index => $item) {
			$json = (array)($item['json'] ?? []);

			$records[$index] = $json;
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
	 * @param array $settled One `FlowConcurrency::map()` result.
	 * @param string $endpoint The rendered endpoint.
	 * @param string $method The HTTP method.
	 * @param array $accepted Statuses the author opted into.
	 * @param string $reference The authored source reference.
	 * @param ObjectEntity $source The resolved Source object.
	 *
	 * @return array The response result written onto the item.
	 *
	 * @throws FlowNodeException On a non-accepted status or a transport failure.
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-a-node-that-calls-a-source-once-per-item-dispatches-those-calls-concurrently-req-015
	 */
	private function outcomeOf(
		array $settled,
		string $endpoint,
		string $method,
		array $accepted,
		string $reference,
		ObjectEntity $source,
	): array {
		if ($settled['ok'] === false) {
			// A transport-level failure (DNS, TLS, timeout, connection
			// refused) is a failed call, never an empty response body.
			//
			// `callAsync()` converts every reason it CAN into a CallLog — a
			// 4xx, a 5xx, a refused connection all fulfil with a log row, and
			// arrive at the status check below. Reaching here means the reason
			// was not convertible at all, which is the same set of failures the
			// synchronous `call()` let escape as a \Throwable.
			$reason = $settled['error'];

			throw new FlowNodeException(
				message: $this->l10n->t(
					'The call to source "%1$s" endpoint "%2$s" failed at transport level: %3$s',
					[$reference, $endpoint, $reason->getMessage()]
				),
				details: [
					'kind' => 'transport',
					'status' => null,
					'source' => $reference,
					'endpoint' => $endpoint,
					'method' => $method,
				],
				previous: $reason
			);
		}//end if

		$callLog = $settled['value'];
		if (($callLog instanceof ObjectEntity) === false) {
			// Defensive, and load-bearing: the two dispatch modes are kept in
			// step by `finalizeCall()`, so anything else here means that
			// contract moved. Say so rather than read `getObject()` off it.
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The call to source "%1$s" endpoint "%2$s" did not produce a call log.',
					[$reference, $endpoint]
				),
				details: [
					'kind' => 'transport',
					'status' => null,
					'source' => $reference,
					'endpoint' => $endpoint,
					'method' => $method,
				]
			);
		}

		$body = (array)$callLog->getObject();
		$response = (array)($body['response'] ?? []);
		$statusCode = null;
		if (isset($body['statusCode']) === true) {
			$statusCode = (int)$body['statusCode'];
		} elseif (isset($response['statusCode']) === true) {
			$statusCode = (int)$response['statusCode'];
		}

		$statusMessage = (string)($body['statusMessage'] ?? ($response['statusMessage'] ?? ''));

		if ($this->isSuccess(statusCode: $statusCode, accepted: $accepted) === false) {
			throw new FlowNodeException(
				message: $this->l10n->t(
					'The call to source "%1$s" endpoint "%2$s" returned status %3$s (%4$s), '
					. 'which the step does not accept.',
					[$reference, $endpoint, (string)($statusCode ?? 'unknown'), $statusMessage]
				),
				details: [
					'kind' => 'status',
					'status' => $statusCode,
					'statusMessage' => $statusMessage,
					'source' => $reference,
					'endpoint' => $endpoint,
					'method' => $method,
					'callLog' => $callLog->getUuid(),
				]
			);
		}

		return [
			'status' => $statusCode,
			'statusMessage' => $statusMessage,
			'headers' => (array)($response['headers'] ?? []),
			'body' => $this->decodeBody(response: $response),
			'source' => $reference,
			'sourceId' => $source->getUuid(),
			'endpoint' => $endpoint,
			'method' => $method,
			'callLog' => $callLog->getUuid(),
		];

	}//end outcomeOf()

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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
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
	 * Build the request configuration handed to `CallService`.
	 *
	 * An array body travels as `json` (the Guzzle option that encodes it); a
	 * string body travels as `body`. Nothing here sets an authentication
	 * header — `FlowConfigGuard` has already refused any attempt to.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $json The current item's record.
	 *
	 * @return array The request configuration.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function buildRequestConfig(array $config, array $json): array {
		$requestConfig = [];

		$query = ($config['query'] ?? null);
		if (is_array($query) === true && $query !== []) {
			$requestConfig['query'] = FlowTemplate::renderValue(value: $query, json: $json);
		}

		$headers = ($config['headers'] ?? null);
		if (is_array($headers) === true && $headers !== []) {
			$requestConfig['headers'] = FlowTemplate::renderValue(value: $headers, json: $json);
		}

		$body = ($config['body'] ?? null);
		if (is_array($body) === true && $body !== []) {
			$requestConfig['json'] = FlowTemplate::renderValue(value: $body, json: $json);
		} elseif (is_string($body) === true && $body !== '') {
			$requestConfig['body'] = FlowTemplate::renderString(template: $body, json: $json);
		}

		return $requestConfig;
	}//end buildRequestConfig()

	/**
	 * Decode the response body, keeping a non-UTF-8 payload untouched.
	 *
	 * @param array $response The CallLog's response array.
	 *
	 * @return mixed The decoded payload, or the raw string.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function decodeBody(array $response): mixed {
		$body = ($response['body'] ?? null);
		if (is_string($body) === false) {
			return $body;
		}

		// `CallService` base64-encodes a body that is not valid UTF-8; handing
		// that to json_decode would only produce noise.
		if ((string)($response['encoding'] ?? 'UTF-8') !== 'UTF-8') {
			return $body;
		}

		if (trim($body) === '') {
			return $body;
		}

		$decoded = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $body;
		}

		return $decoded;
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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
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
	 * @param array $item The input item.
	 * @param int $index The input item's index.
	 * @param array $json The item's record.
	 * @param string $stepId The step id, for the error state.
	 * @param string $reference The authored source reference.
	 * @param string $endpoint The rendered endpoint.
	 * @param string $method The HTTP method.
	 * @param FlowNodeException $exception The failure.
	 *
	 * @return array The output item carrying explicit error state.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function errorItem(
		array $item,
		int $index,
		array $json,
		string $stepId,
		string $reference,
		string $endpoint,
		string $method,
		FlowNodeException $exception,
	): array {
		$details = $exception->getDetails();

		$json[FlowNodeSupport::ERROR_KEY] = [
			'step' => $stepId,
			'node' => self::NODE_ID,
			'status' => ($details['status'] ?? null),
			'statusMessage' => ($details['statusMessage'] ?? null),
			'kind' => ($details['kind'] ?? 'call'),
			'message' => $exception->getMessage(),
			'source' => $reference,
			'endpoint' => $endpoint,
			'method' => $method,
		];

		return [
			'json' => $json,
			'binary' => (array)($item['binary'] ?? []),
			'pairedItem' => ['item' => $index],
		];

	}//end errorItem()

	/**
	 * Whether a status counts as a success for this step.
	 *
	 * @param int|null $statusCode The status, or null when there was none.
	 * @param array $accepted Statuses the author opted into.
	 *
	 * @return boolean Whether the call succeeded.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
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
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
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
