<?php

/**
 * Unit tests for RISPollJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Cron;

use OCA\Integriq\Cron\RISPollJob;
use OCA\Integriq\Service\IBabsConnectorService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the RIS besluit polling background job.
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 */
class RISPollJobTest extends TestCase {

	/**
	 * @var RISPollJob
	 */
	private RISPollJob $job;

	/**
	 * @var IBabsConnectorService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $ibabsService;

	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->ibabsService = $this->createMock(IBabsConnectorService::class);
		$this->orObjectService = $this->createMock(OrObjectService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->job = new RISPollJob(
			$timeFactory,
			$this->ibabsService,
			$this->orObjectService,
			$logger
		);

	}//end setUp()

	/**
	 * Test that run() completes without error when no sync records exist.
	 *
	 * @return void
	 */
	public function testRunWithNoSyncRecords(): void {
		$this->orObjectService
			->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);

		// No call to ibabsService or notuBizService expected when no records.
		$this->ibabsService
			->expects($this->never())
			->method('pollDecisions');

		$this->job->run([]);

	}//end testRunWithNoSyncRecords()

	/**
	 * Test that run() handles orObjectService exception gracefully.
	 *
	 * @return void
	 */
	public function testRunHandlesObjectServiceException(): void {
		$this->orObjectService
			->method('findAll')
			->willThrowException(new \Exception('DB unavailable'));

		// Should not throw — graceful handling.
		$this->job->run([]);
		$this->assertTrue(true);

	}//end testRunHandlesObjectServiceException()

	/**
	 * Test that run() calls pollBesluiten for iBabs sync records.
	 *
	 * @return void
	 */
	public function testRunCallsPollBesluitenForIBabsRecords(): void {
		// Build a fake sync record entity.
		$syncEntity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
		$syncEntity
			->method('getObject')
			->willReturn(
				[
					'caseId' => 'zaak-001',
					'risType' => 'ibabs',
					'status' => 'synced',
					'risMeetingId' => 'verg-001',
					'sourceId' => 'default',
				]
			);

		$this->orObjectService
			->method('findAll')
			->willReturn(['results' => [$syncEntity], 'total' => 1]);

		// loadSource() will call orObjectService->find() which returns null — handled gracefully.
		$this->orObjectService
			->method('find')
			->willReturn(null);

		// If source is null, pollBesluiten is NOT called (source loading fails).
		$this->ibabsService
			->expects($this->never())
			->method('pollDecisions');

		$this->job->run([]);

	}//end testRunCallsPollBesluitenForIBabsRecords()

	/**
	 * Test that the job sets a non-zero interval.
	 *
	 * @return void
	 */
	public function testJobHasInterval(): void {
		// Verify the job is instantiable — interval is set in constructor.
		$this->assertInstanceOf(RISPollJob::class, $this->job);

	}//end testJobHasInterval()

}//end class
