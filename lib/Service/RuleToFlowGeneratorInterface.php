<?php

/**
 * Contract of the RuleToFlowGenerator.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;

/**
 * Public operations of {@see RuleToFlowGenerator} that other components call.
 */
interface RuleToFlowGeneratorInterface {
	/**
	 * Generate the flow document for a rule on one of its endpoints.
	 *
	 * @param string $ruleReference The rule's uuid, slug or reference.
	 * @param string $endpointReference The endpoint's uuid, slug or reference.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When either entity cannot be read, or the
	 *                                      rule uses a feature the flow vocabulary
	 *                                      cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFor(string $ruleReference, string $endpointReference): array;

	/**
	 * Generate the flow document for an already-read rule and endpoint.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param array $endpoint The serialised record of the endpoint that runs it.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When the rule uses a feature the flow
	 *                                      vocabulary cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFrom(array $rule, array $endpoint): array;

	/**
	 * Every feature of this rule the flow vocabulary cannot express.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param array $endpoint The serialised record of the endpoint that runs it.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $rule, array $endpoint): array;
}
