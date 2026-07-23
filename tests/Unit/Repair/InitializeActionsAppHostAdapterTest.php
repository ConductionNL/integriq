<?php

/**
 * InitializeActions repair step — AppHost adapter tests.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Repair;

use OCA\OpenConnector\Repair\InitializeActions;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the leaf `InitializeActions` repair step is a real IRepairStep
 * that constructs cleanly through the engine's `GenericActionAuthService`,
 * scoped to the `openconnector` app id (ADR-023).
 */
class InitializeActionsAppHostAdapterTest extends TestCase
{
    /**
     * The adapter must implement IRepairStep so `appinfo/info.xml`
     * `<repair-steps>` can still reference it.
     *
     * @return void
     */
    public function testImplementsRepairStep(): void
    {
        $step = new InitializeActions(
            appId: 'openconnector',
            actionAuth: $this->createMock(GenericActionAuthService::class),
            appManager: $this->createMock(IAppManager::class),
            logger: $this->createMock(LoggerInterface::class)
        );

        $this->assertInstanceOf(IRepairStep::class, $step);
    }//end testImplementsRepairStep()

    /**
     * `getName()` names the app so `occ` output stays identifiable.
     *
     * @return void
     */
    public function testGetNameNamesTheApp(): void
    {
        $step = new InitializeActions(
            appId: 'openconnector',
            actionAuth: $this->createMock(GenericActionAuthService::class),
            appManager: $this->createMock(IAppManager::class),
            logger: $this->createMock(LoggerInterface::class)
        );

        $this->assertStringContainsString('openconnector', $step->getName());
    }//end testGetNameNamesTheApp()

    /**
     * `run()` degrades cleanly (never throws) when invoked, matching the
     * pre-adoption bespoke step's contract.
     *
     * @return void
     */
    public function testRunDoesNotThrow(): void
    {
        $step = new InitializeActions(
            appId: 'openconnector',
            actionAuth: $this->createMock(GenericActionAuthService::class),
            appManager: $this->createMock(IAppManager::class),
            logger: $this->createMock(LoggerInterface::class)
        );

        $step->run($this->createMock(IOutput::class));
        $this->addToAssertionCount(1);
    }//end testRunDoesNotThrow()
}//end class
