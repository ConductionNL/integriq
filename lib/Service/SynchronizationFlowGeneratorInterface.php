<?php

/**
 * Contract of the SynchronizationFlowGenerator.
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

use OCA\Integriq\Exception\SynchronizationNotMigratableException;

/**
 * Public operations of {@see SynchronizationFlowGenerator} that other components call.
 */
interface SynchronizationFlowGeneratorInterface {
	/**
	 * Generate the flow document for a synchronization named by reference.
	 *
	 * @param string $reference The synchronization's uuid, slug or reference.
	 *
	 * @return array The flow document.
	 *
	 * @throws SynchronizationNotMigratableException When the synchronization cannot be
	 *                                               resolved, or uses a feature the
	 *                                               decomposed flow cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFor(string $reference): array;

	/**
	 * Generate the flow document for an already-read synchronization.
	 *
	 * Nothing is persisted and no contract is touched: the caller decides what
	 * to do with the document, and the document itself is disabled.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array The flow document.
	 *
	 * @throws SynchronizationNotMigratableException When the synchronization uses a
	 *                                               feature the decomposed flow
	 *                                               cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFrom(array $synchronization): array;

	/**
	 * Every feature of this synchronization the decomposed flow cannot express.
	 *
	 * An empty list means the synchronization is migratable. A non-empty one is
	 * the refusal: emitting a flow anyway would replace a rule with silence,
	 * which is strictly worse than not migrating at all.
	 *
	 * @param array $synchronization The synchronization's serialised record.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $synchronization): array;
}
