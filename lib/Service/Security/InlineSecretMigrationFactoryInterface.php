<?php

/**
 * Contract of the InlineSecretMigrationFactory.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Security
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

namespace OCA\Integriq\Service\Security;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Builds the inline-secret migration planner and executor over an OpenRegister
 * object service that the caller resolved lazily.
 */
interface InlineSecretMigrationFactoryInterface {
	/**
	 * Build a planner over the given object service.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 *
	 * @return InlineSecretMigrationPlannerInterface The read-only planner.
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
	 */
	public function planner(OrObjectService $objectService): InlineSecretMigrationPlannerInterface;

	/**
	 * Build an executor over the given object service and planner.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param InlineSecretMigrationPlannerInterface $planner The planner the executor reuses.
	 *
	 * @return InlineSecretMigrationExecutorInterface The writing executor.
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	public function executor(OrObjectService $objectService, InlineSecretMigrationPlannerInterface $planner): InlineSecretMigrationExecutorInterface;
}
