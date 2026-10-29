<?php

/**
 * Contract of the FetchFileNode.
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

use UnexpectedValueException;

/**
 * Public operations of {@see FetchFileNode} that other components call.
 */
interface FetchFileNodeInterface {
	/**
	 * The step type.
	 *
	 * FROZEN on `openconnector.*` across the openconnector -> integriq app-id
	 * rename: this value is written into stored flow documents (OpenRegister
	 * objects). Renaming it makes every existing flow reference a node type
	 * nothing answers to.
	 *
	 * @var string
	 */
	public const NODE_ID = 'openconnector.fetch-file';

	/**
	 * The step type.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getId(): string;

	/**
	 * The palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getDisplayName(): string;

	/**
	 * What the step does, in the palette.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getDescription(): string;

	/**
	 * The palette icon.
	 *
	 * @return string The icon.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function getIcon(): string;

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
	public function isAvailableForScope(int $scope): bool;

	/**
	 * The config vocabulary of a fetch-file step.
	 *
	 * @return array<int, string> The accepted top-level config keys.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function configKeys(): array;

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
	public function configForm(): array;

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
	public function validateConfig(array $config): void;

	/**
	 * Run the rule over every item in the page.
	 *
	 * @param array $items The input items.
	 * @param array $config The step's authored configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function execute(array $items, array $config, array $context): array;
}
