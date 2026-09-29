<?php

/**
 * Unit tests for SynchronizationRunProgressService: the run record learns its
 * source and what started it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-every-run-records-its-source-and-what-started-it-req-crun-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationRunProgressService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The run record carries `sourceId` and `triggeredBy`, and the register takes it.
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-every-run-records-its-source-and-what-started-it-req-crun-001
 */
final class SynchronizationRunProgressServiceTest extends TestCase {

	/**
	 * Payloads handed to saveObject().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over an object service that records every save.
	 *
	 * @return SynchronizationRunProgressService
	 */
	private function makeService(): SynchronizationRunProgressService {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('7f1c1d2e-0000-4000-8000-000000000001');
				return $entity;
			}
		);

		return new SynchronizationRunProgressService($objects, $this->createMock(LoggerInterface::class));
	}//end makeService()

	/**
	 * A scheduled pull records its source and `cron`, and the register accepts the record.
	 *
	 * @return void
	 */
	public function testAScheduledPullRecordsItsSourceAndCron(): void {
		$service = $this->makeService();

		$service->start(
			synchronizationId: 'a1b2c3d4-0000-4000-8000-000000000002',
			sourceId: 'b1b2c3d4-0000-4000-8000-000000000003',
			triggeredBy: 'cron'
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame('b1b2c3d4-0000-4000-8000-000000000003', $this->saved[0]['sourceId']);
		$this->assertSame('cron', $this->saved[0]['triggeredBy']);
		$this->assertSame([], RegisterSchemaValidator::errors('synchronization_run', $this->saved[0]));
	}//end testAScheduledPullRecordsItsSourceAndCron()

	/**
	 * The trigger and the source survive the final write.
	 *
	 * @return void
	 */
	public function testTheFinalWriteKeepsTheSourceAndTrigger(): void {
		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-1', sourceId: 'source-1', triggeredBy: 'rerun');
		$service->finish(status: 'failed', counters: ['invalid' => 2], message: 'Source answered 500');

		$last = end($this->saved);
		$this->assertSame('source-1', $last['sourceId']);
		$this->assertSame('rerun', $last['triggeredBy']);
		$this->assertSame([], RegisterSchemaValidator::errors('synchronization_run', $last));
	}//end testTheFinalWriteKeepsTheSourceAndTrigger()

	/**
	 * The run id stays readable after the run finished, so the caller can link it.
	 *
	 * @return void
	 */
	public function testTheRunIdIsReadableAfterTheRunFinished(): void {
		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-1', sourceId: 'source-1', triggeredBy: 'manual');
		$service->finish(status: 'success');

		$this->assertSame('7f1c1d2e-0000-4000-8000-000000000001', $service->lastRunId());
	}//end testTheRunIdIsReadableAfterTheRunFinished()

	/**
	 * A trigger the record does not know is refused by the register.
	 *
	 * @return void
	 */
	public function testTheRegisterRefusesAnUnknownTrigger(): void {
		$errors = RegisterSchemaValidator::errors(
			'synchronization_run',
			['synchronizationId' => 'sync-1', 'status' => 'running', 'triggeredBy' => 'somebody']
		);

		$this->assertNotSame([], $errors);
	}//end testTheRegisterRefusesAnUnknownTrigger()

	/**
	 * What started a run: an asked-for rerun wins, a cron trace reads cron, anything else manual.
	 *
	 * @return void
	 */
	public function testTheTriggerIsResolvedFromTheRequestAndTheTrace(): void {
		$this->assertSame('rerun', SynchronizationRunProgressService::resolveTrigger(requested: 'rerun', traceTrigger: 'cron'));
		$this->assertSame('cron', SynchronizationRunProgressService::resolveTrigger(requested: null, traceTrigger: 'cron'));
		$this->assertSame('manual', SynchronizationRunProgressService::resolveTrigger(requested: null, traceTrigger: 'manual'));
		$this->assertSame('manual', SynchronizationRunProgressService::resolveTrigger(requested: null, traceTrigger: null));
		$this->assertSame('manual', SynchronizationRunProgressService::resolveTrigger(requested: 'nonsense', traceTrigger: null));
	}//end testTheTriggerIsResolvedFromTheRequestAndTheTrace()
}//end class
