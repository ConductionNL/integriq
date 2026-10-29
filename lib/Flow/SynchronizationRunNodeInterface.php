<?php

/**
 * Contract of the SynchronizationRunNode.
 *
 * @category Contract
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use UnexpectedValueException;

/**
 * Public operations of {@see SynchronizationRunNode} that other components call.
 */
interface SynchronizationRunNodeInterface {
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
	public const NODE_ID = 'openconnector.synchronization-run';

	/**
	 * The step type.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getId(): string;

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDisplayName(): string;

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getDescription(): string;

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function getIcon(): string;

	/**
	 * Whether the node is offered in the given scope.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function isAvailableForScope(int $scope): bool;

	/**
	 * The config vocabulary of a synchronization run.
	 *
	 * Only `synchronization` is required (validateConfig below enforces
	 * that). Naming the vocabulary is what lets the preflight refuse a key
	 * this node would silently ignore — and what lets the flow editor's step
	 * dialog render one field per option instead of a JSON box.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configKeys(): array;

	/**
	 * The fields this node is edited through.
	 *
	 * `synchronization` is a picker fed by the app's OWN synchronizations
	 * listing, so an author chooses one by name instead of pasting a uuid
	 * into a JSON pane.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function configForm(): array;

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
	public function validateConfig(array $config): void;

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
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function execute(array $items, array $config, array $context): array;
}
