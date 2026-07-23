<?php
/**
 * Unit tests for StufZknRetryJob.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Cron;

use OCA\OpenConnector\Cron\StufZknRetryJob;
use OCA\OpenConnector\Service\StufZknSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the scheduled StUF-ZKN outbound retry background job — proves the job actually
 * invokes StufZknSyncService::retryFailed() (orphaned-capability rule).
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
 */
class StufZknRetryJobTest extends TestCase
{

    /**
     * @var StufZknSyncService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $syncService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var StufZknRetryJob
     */
    private StufZknRetryJob $job;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $timeFactory       = $this->createMock(ITimeFactory::class);
        $this->syncService = $this->createMock(StufZknSyncService::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->job = new StufZknRetryJob($timeFactory, $this->syncService, $this->logger);

    }//end setUp()

    /**
     * The job wires its dependencies and constructs without error.
     *
     * @return void
     */
    public function testConstructs(): void
    {
        $this->assertInstanceOf(StufZknRetryJob::class, $this->job);

    }//end testConstructs()

    /**
     * Running the job invokes one retryFailed() sweep — proves invocation, not just declaration
     * (the orphaned-capability rule).
     *
     * @return void
     */
    public function testRunInvokesRetryFailed(): void
    {
        $this->syncService->expects($this->once())->method('retryFailed')->willReturn(2);

        $this->job->run(null);

    }//end testRunInvokesRetryFailed()

    /**
     * With no eligible rows, retryFailed() no-ops (returns 0) and the job does not error.
     *
     * @return void
     */
    public function testRunWithNoEligibleRowsNoOps(): void
    {
        $this->syncService->method('retryFailed')->willReturn(0);
        $this->logger->expects($this->never())->method('error');

        $this->job->run(null);

    }//end testRunWithNoEligibleRowsNoOps()

    /**
     * A sweep-level exception is contained and logged — the cron pipeline never wedges.
     *
     * @return void
     */
    public function testRunContainsSweepException(): void
    {
        $this->syncService->method('retryFailed')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->job->run(null);

    }//end testRunContainsSweepException()
}//end class
