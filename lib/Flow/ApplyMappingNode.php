<?php

/**
 * Integriq Apply Mapping flow node.
 *
 * `openconnector.apply-mapping` — applies one configured Mapping to EVERY
 * item's record inside ONE step execution, through the existing
 * `MappingService`. This is the "[Map the page]" step of the decomposed
 * synchronization flow: nodes carry a page of objects and loop internally, so
 * the engine's fixed per-item overhead is paid once per page, never once per
 * object.
 *
 * WHY A MAPPING REFERENCE AND NOT AN INLINE DEFINITION
 * ----------------------------------------------------
 * A step names an already-configured Mapping (uuid, slug or reference), and an
 * inline mapping array is refused — the same rule `synchronization-run`
 * applies to synchronizations. A mapping edited through its own surface is
 * versioned, reviewable and shared between flows; one buried in a flow
 * document is none of those, and the flow-native-synchronization design opens
 * the REAL mapping editor from this step's dialog rather than growing a
 * second, worse one.
 *
 * WHY THE LOOP IS PLAIN
 * ---------------------
 * Mapping is CPU-bound: no network waits, so a concurrency pool would add
 * dispatch overhead and reorder failures for zero wall-clock gain. The page
 * loop is deliberately serial and in input order.
 *
 * ON `onError: dead_letter`
 * -------------------------
 * A per-item mapping failure under `dead_letter` is treated exactly like
 * `continue` here — the item carries explicit `__error` state and the run goes
 * on. The dead-letter capture itself is engine-side wiring: the engine reads
 * the step's own `onError` and routes what this node raises or marks; this
 * node's job is only to never let a failed item masquerade as a mapped one.
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
use OCA\Integriq\Service\MappingService;
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
 * Applies a configured Mapping to every item in the page, in one execution.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#1-engine-steps-each-a-thin-adapter-over-a-kept-service
 */
class ApplyMappingNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

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
	public const NODE_ID = 'openconnector.apply-mapping';

	/**
	 * Constructor.
	 *
	 * @param MappingService $mappingService The existing transformation engine.
	 * @param FlowOwner $flowOwner Fail-closed run-owner resolution.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urlGenerator For the palette icon.
	 * @param LoggerInterface $logger Run diagnostics.
	 */
	public function __construct(
		private readonly MappingService $mappingService,
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
		return $this->l10n->t('Apply a mapping');
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
			'Apply a configured mapping to every item in the page, in one step execution.'
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
			'mapping',
			'input',
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
				'key' => 'mapping',
				'label' => $this->l10n->t('Mapping'),
				'type' => 'select',
				'help' => $this->l10n->t('The configured mapping applied to every item in the page.'),
				'required' => true,
				'optionsFrom' => '/apps/openregister/api/objects/openconnector/mapping',
			],
			[
				'key' => 'input',
				'label' => $this->l10n->t('Input path'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Dot-path within the item to feed the mapping. Leave empty to map the whole item.'
				),
			],
			[
				'key' => 'output',
				'label' => $this->l10n->t('Output key'),
				'type' => 'text',
				'help' => $this->l10n->t(
					'Item key the mapped result is written under. Leave empty to replace the item with the result.'
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
		FlowNodeSupport::assertReference(config: $config, key: 'mapping', l10n: $this->l10n);

		if (array_key_exists('input', $config) === true
			&& (is_string($config['input']) === false || trim($config['input']) === '')
		) {
			throw new UnexpectedValueException(
				$this->l10n->t('The "input" field must be a non-empty dot-path when set.')
			);
		}

		if (array_key_exists('output', $config) === true) {
			FlowConfigGuard::assertOutputKeyAllowed(outputKey: (string)$config['output'], l10n: $this->l10n);
		}

		FlowNodeSupport::assertOnError(config: $config, l10n: $this->l10n);

	}//end validateConfig()

	/**
	 * Apply the mapping to every item, in the run owner's identity context.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata (carries the run owner).
	 *
	 * @return array The output items, one per input item, in input order.
	 *
	 * @throws FlowNodeException On a failure the `onError` policy does not absorb.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function execute(array $items, array $config, array $context): array {
		// An empty page maps nothing and produces no items — the filter
		// contract, not a failure.
		if ($items === []) {
			return [];
		}

		$this->validateConfig(config: $config);

		$owner = $this->flowOwner->resolve(context: $context, nodeId: self::NODE_ID);

		return $this->flowOwner->runAs(
			user: $owner,
			callback: function () use ($items, $config, $context) {
				return $this->mapEachItem(items: $items, config: $config, context: $context);
			}
		);

	}//end execute()

	/**
	 * Map every item in the page, serially and in input order.
	 *
	 * The loop is plain on purpose — mapping is CPU-bound, so a concurrency
	 * pool would buy nothing and cost failure ordering (see class docblock).
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @throws FlowNodeException When a mapping fails and the policy is `stop`.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function mapEachItem(array $items, array $config, array $context): array {
		$reference = trim((string)$config['mapping']);
		$inputPath = trim((string)($config['input'] ?? ''));
		$outputKey = trim((string)($config['output'] ?? ''));
		$onError = FlowNodeSupport::onErrorPolicy(config: $config, context: $context);
		$stepId = FlowNodeSupport::stepId(config: $config, context: $context, nodeId: self::NODE_ID);

		$outputList = [];
		foreach (array_values($items) as $index => $item) {
			$json = (array)($item['json'] ?? []);

			try {
				$mapped = $this->mapOne(json: $json, reference: $reference, inputPath: $inputPath);
				$json = $this->applyResult(json: $json, mapped: $mapped, outputKey: $outputKey);
			} catch (Throwable $exception) {
				$failure = $this->asNodeFailure(exception: $exception, reference: $reference);

				$this->logger->error(
					'[openconnector.apply-mapping] ' . $failure->getMessage(),
					[
						'file' => __FILE__,
						'line' => __LINE__,
						'step' => $stepId,
						'mapping' => $reference,
						'item' => $index,
					]
				);

				// `dead_letter` is treated like `continue` here: the item
				// carries explicit error state and the dead-letter capture is
				// engine-side wiring (see class docblock).
				if ($onError === 'stop') {
					throw $failure;
				}

				$json[FlowNodeSupport::ERROR_KEY] = [
					'step' => $stepId,
					'node' => self::NODE_ID,
					'kind' => 'mapping',
					'message' => $failure->getMessage(),
					'mapping' => $reference,
				];
			}//end try

			$outputList[] = FlowItems::item(
				json: $json,
				binary: (array)($item['binary'] ?? []),
				fromItemIndex: $index
			);
		}//end foreach

		return $outputList;
	}//end mapEachItem()

	/**
	 * Place a mapped result onto its item's record.
	 *
	 * No output key means the mapped result REPLACES the record — the shape
	 * the downstream write step expects; a key routes it beside the original.
	 *
	 * @param array $json The item's record.
	 * @param array $mapped The mapped result.
	 * @param string $outputKey The author-named output key, or empty.
	 *
	 * @return array The resulting record.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function applyResult(array $json, array $mapped, string $outputKey): array {
		if ($outputKey === '') {
			return $mapped;
		}

		return FlowTemplate::write(json: $json, path: $outputKey, value: $mapped);
	}//end applyResult()

	/**
	 * Map one item's input through the configured mapping.
	 *
	 * @param array $json The item's record.
	 * @param string $reference The authored mapping reference.
	 * @param string $inputPath Dot-path to the input, or empty for the whole record.
	 *
	 * @return array The mapped result.
	 *
	 * @throws FlowNodeException When the input path resolves to no object.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function mapOne(array $json, string $reference, string $inputPath): array {
		$input = $json;
		if ($inputPath !== '') {
			$value = FlowTemplate::lookup(path: $inputPath, json: $json);
			if (is_array($value) === false) {
				throw new FlowNodeException(
					message: $this->l10n->t(
						'The "input" path "%1$s" did not resolve to an object on this item; nothing was mapped.',
						[$inputPath]
					),
					details: ['kind' => 'mapping', 'input' => $inputPath, 'mapping' => $reference]
				);
			}

			$input = $value;
		}

		return $this->mappingService->executeMapping(mapping: $reference, input: $input);
	}//end mapOne()

	/**
	 * Normalise any failure into a raised node failure.
	 *
	 * @param Throwable $exception The underlying failure.
	 * @param string $reference The authored mapping reference.
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
				'The mapping "%1$s" failed: %2$s',
				[$reference, $exception->getMessage()]
			),
			details: ['kind' => 'mapping', 'mapping' => $reference],
			previous: $exception
		);
	}//end asNodeFailure()
}//end class
