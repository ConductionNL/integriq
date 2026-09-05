<?php

/**
 * Integriq Event Emit flow node.
 *
 * `openconnector.event-emit` — the `event` step of the retired app-local flow
 * runner, re-homed on OpenRegister's engine. OpenRegister ships no node that
 * emits a CloudEvent (its send-* nodes address people — email, notification,
 * Talk — not systems), so the emit half of the `event` vocabulary lives here,
 * as a thin adapter over the existing `EventService::emitCloudEvent()`
 * pipeline: persistence to the `event` schema, subscription fan-out and
 * delivery all behave exactly as they do for an imperatively emitted event.
 *
 * One event is emitted PER ITEM, with the item's record as the CloudEvent's
 * `data` — the item model every other node obeys. A flow that has just
 * synchronised a page of objects therefore announces each object, not a
 * summary blob.
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
 * @spec openspec/changes/retire-integriq-flow-schema/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCA\Integriq\Service\EventService;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Emits one CloudEvent per item through the existing event pipeline.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The count is the engine
 * vocabulary itself — the three node interfaces plus FlowItems and the
 * shared guards — the same fan-in every sibling node carries (they sit in
 * phpmd.baseline.xml for the identical reason).
 * @SuppressWarnings(PHPMD.StaticAccess) FlowConfigGuard, FlowNodeSupport
 * and FlowTemplate are the shared static helpers every Integriq node
 * validates and templates through; instantiating them would add state to
 * say the same thing.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
 */
class EventEmitNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 *
	 * FROZEN on `openconnector.*` — the id is written into stored flow
	 * documents, so it survives the openconnector -> integriq app-id rename.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.event-emit';

	/**
	 * Constructor.
	 *
	 * `EventService` is resolved lazily through the container rather than
	 * constructor-injected — the same idiom `FlowRunnerService` uses —
	 * because `EventService`'s delivery path can dispatch flow work of its
	 * own, and an eager constructor edge from the flow palette into it drags
	 * the whole delivery graph into every palette build.
	 *
	 * @param ContainerInterface $container Lazily resolves EventService.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
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
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Emit an event');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Emit a CloudEvent for every item, delivered through the configured event subscriptions.'
		);

	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/share.svg');
	}//end getIcon()

	/**
	 * Whether the node is offered in the given scope.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The node's whole config vocabulary.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function configKeys(): array {
		return ['type', 'source', 'subject', 'output', 'onError'];
	}//end configKeys()

	/**
	 * The fields this node's configuration is edited through.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'type',
				'label' => $this->l10n->t('Event type'),
				'type' => 'text',
				'help' => $this->l10n->t('The CloudEvent "type", e.g. "nl.example.object.updated". Subscriptions match on it.'),
				'required' => true,
			],
			[
				'key' => 'source',
				'label' => $this->l10n->t('Event source'),
				'type' => 'text',
				'help' => $this->l10n->t('The CloudEvent "source" URI identifying the emitter.'),
				'required' => true,
			],
			[
				'key' => 'subject',
				'label' => $this->l10n->t('Subject'),
				'type' => 'text',
				'help' => $this->l10n->t('Optional CloudEvent "subject". Supports {{dotted.path}} placeholders resolved from each item.'),
			],
			[
				'key' => 'output',
				'label' => $this->l10n->t('Output key'),
				'type' => 'text',
				'help' => $this->l10n->t('Item key the emit summary is written under. Defaults to "eventResult".'),
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
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function validateConfig(array $config): void {
		FlowConfigGuard::assertNoForbiddenFields(config: $config, l10n: $this->l10n);

		if (trim((string)($config['type'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Name the event "type": an event without a type matches no subscription.')
			);
		}

		if (trim((string)($config['source'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('Name the event "source": a CloudEvent must say where it came from.')
			);
		}

		if (array_key_exists('output', $config) === true) {
			FlowConfigGuard::assertOutputKeyAllowed(outputKey: (string)$config['output'], l10n: $this->l10n);
		}

		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Emit one CloudEvent per item through the existing pipeline.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, each carrying the emit summary under the output key.
	 *
	 * @throws FlowNodeException On a failure the `onError` policy does not absorb.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	public function execute(array $items, array $config, array $context): array {
		// An empty page emits nothing and produces no items — the filter
		// contract, not a failure.
		if ($items === []) {
			return [];
		}

		$this->validateConfig(config: $config);

		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);
		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$eventService = $this->container->get(EventService::class);

		$outputKey = trim((string)($config['output'] ?? ''));
		if ($outputKey === '') {
			$outputKey = 'eventResult';
		}

		$out = [];
		foreach ($items as $index => $item) {
			$json = [];
			$rebuilt = [];
			if (is_array($item) === true) {
				$json = (array)($item[FlowItems::JSON] ?? []);
				$rebuilt = $item;
			}

			$rebuilt[FlowItems::JSON] = $this->emitForItem(
				eventService: $eventService,
				json: $json,
				config: $config,
				stepId: $stepId,
				onError: $onError,
				outputKey: $outputKey
			);
			if (array_key_exists(FlowItems::PAIRED_ITEM, $rebuilt) === false) {
				$rebuilt[FlowItems::PAIRED_ITEM] = ['item' => $index];
			}

			$out[] = $rebuilt;
		}//end foreach

		return $out;

	}//end execute()

	/**
	 * Emit one item's event; success lands under the output key, failure is
	 * explicit — a raise, or `__error` state under `continue`.
	 *
	 * @param EventService $eventService The resolved event pipeline.
	 * @param array $json The item's record.
	 * @param array $config The step's authored configuration.
	 * @param string $stepId The step id, for error messages.
	 * @param string $onError The step's error policy.
	 * @param string $outputKey The key the emit summary lands under.
	 *
	 * @return array The item's record, carrying the summary or the error state.
	 *
	 * @throws FlowNodeException On a failure the `onError` policy does not absorb.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function emitForItem(
		EventService $eventService,
		array $json,
		array $config,
		string $stepId,
		string $onError,
		string $outputKey,
	): array {
		try {
			$subject = trim(FlowTemplate::renderString(
				template: (string)($config['subject'] ?? ''),
				json: $json
			));
			if ($subject === '') {
				$subject = null;
			}

			$messages = $eventService->emitCloudEvent(
				type: (string)$config['type'],
				source: (string)$config['source'],
				subject: $subject,
				data: $json
			);

			$json[$outputKey] = [
				'emitted' => true,
				'messageCount' => count($messages),
			];
		} catch (Throwable $e) {
			if ($onError !== 'continue') {
				throw new FlowNodeException(
					message: $this->l10n->t(
						'Step "%1$s" failed to emit event "%2$s": %3$s',
						[$stepId, (string)$config['type'], $e->getMessage()]
					),
					details: ['stepId' => $stepId, 'type' => (string)$config['type']],
					previous: $e
				);
			}

			$this->logger->warning(
				'EventEmitNode: item failed to emit, carried as error state (onError: continue)',
				['stepId' => $stepId, 'exception' => $e]
			);

			// Explicit error state, never a success-shaped empty summary.
			$json[FlowNodeSupport::ERROR_KEY] = [
				'failed' => true,
				'stepId' => $stepId,
				'message' => $e->getMessage(),
				'type' => (string)$config['type'],
			];
		}//end try

		return $json;

	}//end emitForItem()
}//end class
