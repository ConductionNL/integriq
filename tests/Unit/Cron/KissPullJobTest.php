<?php
/**
 * Unit tests for KissPullJob.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kiss-kcc-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Cron;

use OCA\OpenConnector\Cron\KissPullJob;
use OCA\OpenConnector\Service\KissSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the scheduled KISS klantcontacten pull background job.
 *
 * @spec openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-pull-sync-of-klantcontacten-with-a-persisted-cursor
 */
class KissPullJobTest extends TestCase
{

    /**
     * @var KissSyncService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $syncService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var KissPullJob
     */
    private KissPullJob $job;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $timeFactory       = $this->createMock(ITimeFactory::class);
        $this->syncService = $this->createMock(KissSyncService::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->job = new KissPullJob($timeFactory, $this->syncService, $this->logger);

    }//end setUp()

    /**
     * The job wires its dependencies and constructs without error.
     *
     * @return void
     */
    public function testConstructs(): void
    {
        $this->assertInstanceOf(KissPullJob::class, $this->job);

    }//end testConstructs()

    /**
     * Running the job invokes one pullAll sweep.
     *
     * @return void
     */
    public function testRunInvokesPullAll(): void
    {
        $this->syncService->expects($this->once())->method('pullAll')->willReturn(3);

        $this->job->run(null);

    }//end testRunInvokesPullAll()

    /**
     * With no KISS source configured, pullAll() no-ops (returns 0) and the job does not error.
     *
     * @return void
     */
    public function testRunWithNoSourceConfiguredNoOps(): void
    {
        $this->syncService->method('pullAll')->willReturn(0);
        $this->logger->expects($this->never())->method('error');

        $this->job->run(null);

    }//end testRunWithNoSourceConfiguredNoOps()

    /**
     * A sweep-level exception is contained and logged — the cron pipeline never wedges.
     *
     * @return void
     */
    public function testRunContainsSweepException(): void
    {
        $this->syncService->method('pullAll')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->job->run(null);

    }//end testRunContainsSweepException()
}//end class
