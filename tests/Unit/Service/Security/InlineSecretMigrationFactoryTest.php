<?php

/**
 * Tests for InlineSecretMigrationFactory.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\Integriq\Service\Security\InlineSecretMigrationFactory;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCA\Integriq\Tests\Helpers\RenderBoundarySimulatingObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Integriq\Service\Security\InlineSecretMigrationFactory
 */
class InlineSecretMigrationFactoryTest extends TestCase {

	/**
	 * The planner reads raw data from the object service it was built over.
	 *
	 * @return void
	 */
	public function testPlannerReadsTheGivenObjectService(): void {
		$objectService = new RenderBoundarySimulatingObjectService();
		$objectService->stored['src-1'] = ['name' => 'Live', 'apikey' => 'inline-secret'];

		$factory = new InlineSecretMigrationFactory(new NullLogger(), $this->createMock(ContainerInterface::class));
		$planner = $factory->planner(objectService: $objectService);

		$this->assertInstanceOf(InlineSecretMigrationPlanner::class, $planner);
		$this->assertSame('inline-secret', $planner->readRawSource(uuid: 'src-1')['apikey']);
	}//end testPlannerReadsTheGivenObjectService()

	/**
	 * The executor is the concrete executor over the given planner.
	 *
	 * @return void
	 */
	public function testExecutorIsBuiltOverThePlanner(): void {
		$objectService = new RenderBoundarySimulatingObjectService();
		$factory = new InlineSecretMigrationFactory(new NullLogger(), $this->createMock(ContainerInterface::class));
		$planner = $factory->planner(objectService: $objectService);

		$executor = $factory->executor(objectService: $objectService, planner: $planner);

		$this->assertInstanceOf(InlineSecretMigrationExecutor::class, $executor);
		$this->assertSame($planner, (new \ReflectionProperty(InlineSecretMigrationExecutor::class, 'planner'))->getValue($executor));
	}//end testExecutorIsBuiltOverThePlanner()
}//end class
