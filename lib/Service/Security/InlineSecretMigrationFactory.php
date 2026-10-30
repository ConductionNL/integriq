<?php

/**
 * Integriq Inline Secret Migration Factory.
 *
 * The repair steps resolve OpenRegister's object service lazily, so they cannot
 * take a planner or executor through their constructor. They take this factory
 * instead and build both once the object service is known.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the inline-secret migration planner and executor.
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
 */
class InlineSecretMigrationFactory implements InlineSecretMigrationFactoryInterface {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Secret-free logging only.
	 * @param ContainerInterface $container App container the executor resolves the credential broker from.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * Build a planner over the given object service.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 *
	 * @return InlineSecretMigrationPlannerInterface The read-only planner.
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
	 */
	public function planner(OrObjectService $objectService): InlineSecretMigrationPlannerInterface {
		return new InlineSecretMigrationPlanner(
			objectService: $objectService,
			logger: $this->logger
		);
	}//end planner()

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
	public function executor(OrObjectService $objectService, InlineSecretMigrationPlannerInterface $planner): InlineSecretMigrationExecutorInterface {
		return new InlineSecretMigrationExecutor(
			objectService: $objectService,
			planner: $planner,
			logger: $this->logger,
			container: $this->container
		);
	}//end executor()
}//end class
