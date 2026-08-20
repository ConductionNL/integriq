<?php

/**
 * Stub for OCA\OpenRegister\Service\Flow\IFlowNode.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub mirrors the deployed interface exactly
 * (verified against openregister/lib/Service/Flow/IFlowNode.php) so the two
 * node classes OpenConnector contributes can be loaded and unit-tested without
 * a full Nextcloud installation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Contract for a contributed flow node type.
 */
interface IFlowNode {
	/**
	 * The step `type` this node answers to, unique across the fleet.
	 *
	 * @return string
	 */
	public function getId(): string;

	/**
	 * Human-readable name for the palette.
	 *
	 * @return string
	 */
	public function getDisplayName(): string;

	/**
	 * What this node does, in one sentence.
	 *
	 * @return string
	 */
	public function getDescription(): string;

	/**
	 * Absolute URL of the palette icon.
	 *
	 * @return string
	 */
	public function getIcon(): string;

	/**
	 * Whether this node is offered in the given scope.
	 *
	 * @param int $scope
	 * @return bool
	 */
	public function isAvailableForScope(int $scope): bool;

	/**
	 * Reject a configuration the author cannot have meant.
	 *
	 * @param array $config
	 * @return void
	 *
	 * @throws \UnexpectedValueException
	 */
	public function validateConfig(array $config): void;

	/**
	 * Do the work: items in, items out.
	 *
	 * @param array $items
	 * @param array $config
	 * @param array $context
	 * @return array
	 */
	public function execute(array $items, array $config, array $context): array;
}
