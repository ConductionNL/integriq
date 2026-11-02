<?php

/**
 * Contract of the JobToFlowGenerator.
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
 * Public operations of {@see JobToFlowGenerator} that other components call.
 */
interface JobToFlowGeneratorInterface {
	/**
	 * Generate the flow document for a job named by reference.
	 *
	 * @param string $reference The job's uuid, slug or reference.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When the job cannot be read, or uses a
	 *                                      feature the flow vocabulary cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFor(string $reference): array;

	/**
	 * Generate the flow document for an already-read job.
	 *
	 * Nothing is persisted: the caller decides what to do with the document, and
	 * the document itself is disabled.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array The flow document.
	 *
	 * @throws EntityNotMigratableException When the job uses a feature the flow
	 *                                      vocabulary cannot express.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function generateFrom(array $job): array;

	/**
	 * Every feature of this job the flow vocabulary cannot express.
	 *
	 * An empty list means the job is migratable. A non-empty one is the refusal:
	 * emitting a flow anyway would replace a scheduled action with silence.
	 *
	 * @param array $job The job's serialised record.
	 *
	 * @return array<int, string> One sentence per unsupported feature.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function refusalsFor(array $job): array;
}
