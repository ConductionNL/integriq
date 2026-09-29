<?php

/**
 * An import job hands its records to the owning app, and the answer ends the job.
 *
 * Runs the real ExchangeJobRunner over the real ExchangeTargetDispatcher, with
 * an event dispatcher that calls a listener the test supplies, so the path
 * from gate answer to stored rejection and job status is exercised end to end.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-010-an-import-job-hands-its-records-to-the-owning-app
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Exchange;

use OCA\Integriq\Action\ExchangeJobAction;
use OCA\Integriq\Adapters\Swv\SwvHandoffClient;
use OCA\Integriq\Event\ExchangeJobConcludedEvent;
use OCA\Integriq\Event\ExchangeRecordsReceivedEvent;
use OCA\Integriq\Service\Exchange\ExchangeGateClient;
use OCA\Integriq\Service\Exchange\ExchangeJobRunner;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Sources\Swv\SwvHandoffSourceAdapter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher
 * @covers \OCA\Integriq\Service\Exchange\ExchangeJobRunner
 */
final class ExchangeImportLandingTest extends TestCase {

	/**
	 * Every saved job state.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Every stored rejection.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * Every records event dispatched.
	 *
	 * @var array<int, ExchangeRecordsReceivedEvent>
	 */
	private array $landed = [];

	/**
	 * The owning app's listener, or null for none.
	 *
	 * @var callable|null
	 */
	private $listener = null;

	/**
	 * Build a runner for one queued import job.
	 *
	 * @param string $target The import target.
	 *
	 * @return ExchangeJobRunner
	 */
	private function runner(string $target): ExchangeJobRunner {
		$job = new ObjectEntity();
		$job->setUuid('job-1');
		$job->setObject(
			[
				'name' => 'Import',
				'jobClass' => ExchangeJobAction::class,
				'ownerApp' => 'learniq',
				'ownerRef' => 'import/7',
				'exchangeTarget' => $target,
				'exchangeDirection' => 'import',
				'exchangeScope' => ['schoolYear' => '2026-2027'],
				'exchangeStatus' => 'queued',
			]
		);

		$jobs = $this->createMock(ExchangeJobService::class);
		$jobs->method('findJob')->willReturn($job);
		$jobs->method('saveJob')->willReturnCallback(
			function (array $data, ?string $uuid=null) use ($job): ObjectEntity {
				$this->saves[] = $data;
				return $job;
			}
		);

		$gate = $this->createMock(ExchangeGateClient::class);
		$gate->method('ask')->willReturn(
			[
				'decision' => 'allow',
				'code' => '',
				'reason' => '',
				'checkedAt' => '2026-10-01T08:00:00+02:00',
				'records' => [
					['recordId' => 'a', 'sourceKind' => 'lvs-result', 'data' => ['score' => 41]],
					['recordId' => 'b', 'sourceKind' => 'lvs-result', 'data' => ['score' => 38]],
				],
			]
		);

		$rejections = $this->createMock(ExchangeRejectionService::class);
		$rejections->method('record')->willReturnCallback(
			function (string $jobId, string $target, array $rejection, string $ownerApp): void {
				$this->stored[] = $rejection;
			}
		);

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				if ($event instanceof ExchangeRecordsReceivedEvent) {
					$this->landed[] = $event;
					if ($this->listener !== null) {
						($this->listener)($event);
					}
				}
			}
		);

		$dispatcher = new ExchangeTargetDispatcher(
			$this->createMock(RodService::class),
			$this->createMock(VerzuimloketService::class),
			$this->createMock(OsoService::class),
			$this->createMock(UwlrEduVService::class),
			new SwvHandoffSourceAdapter(
				$this->createMock(IAppConfig::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(SwvHandoffClient::class)
			),
			$events,
			$this->createMock(LoggerInterface::class)
		);

		return new ExchangeJobRunner(
			$jobs,
			$gate,
			$dispatcher,
			$rejections,
			$this->createMock(MappingService::class),
			$events,
			$this->createMock(LoggerInterface::class)
		);
	}//end runner()

	/**
	 * The last saved job state.
	 *
	 * @return array<string, mixed>
	 */
	private function lastSave(): array {
		return $this->saves[(count($this->saves) - 1)];
	}//end lastSave()

	/**
	 * Each of the three import targets dispatches the event with the job's context and records.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#scenario-an-lvs-results-import-is-handed-over
	 */
	public function testTheRecordsAreHandedToTheOwningApp(): void {
		foreach (['lvs-results', 'oso', 'migration-import'] as $target) {
			$this->landed = [];
			$this->listener = static fn (ExchangeRecordsReceivedEvent $event) => $event->accept(acceptedCount: 2);
			$this->runner(target: $target)->run(jobId: 'job-1');

			$this->assertCount(1, $this->landed, $target);
			$event = $this->landed[0];
			$this->assertSame('job-1', $event->getJobId());
			$this->assertSame('learniq', $event->getOwnerApp());
			$this->assertSame($target, $event->getTarget());
			$this->assertSame('import', $event->getDirection());
			$this->assertSame('import/7', $event->getOwnerRef());
			$this->assertSame(['schoolYear' => '2026-2027'], $event->getScope());
			$this->assertSame(['a', 'b'], array_column($event->getRecords(), 'recordId'));
			$this->assertSame('succeeded', $this->lastSave()['exchangeStatus'], $target);
		}
	}//end testTheRecordsAreHandedToTheOwningApp()

	/**
	 * One accepted and one rejected record end the job partial, with the rejection stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#scenario-the-owning-app-accepts-one-and-rejects-one
	 */
	public function testTheAnswerEndsTheJob(): void {
		$this->listener = static function (ExchangeRecordsReceivedEvent $event): void {
			$event->accept(acceptedCount: 1, rejected: [['recordId' => 'b', 'errorCode' => 'LVS-DUPLICATE', 'offendingFields' => ['score']]]);
		};

		$this->runner(target: 'lvs-results')->run(jobId: 'job-1');

		$save = $this->lastSave();
		$this->assertSame('partial', $save['exchangeStatus']);
		$this->assertSame(1, $save['exchangeResult']['recordsAccepted']);
		$this->assertSame(2, $save['exchangeResult']['recordsProcessed']);
		$this->assertSame(
			[['recordId' => 'b', 'sourceKind' => 'lvs-result', 'errorCode' => 'LVS-DUPLICATE', 'offendingFields' => ['score']]],
			$this->stored
		);
	}//end testTheAnswerEndsTheJob()

	/**
	 * No listener ends the job failed with no-owner-answer and stores nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#scenario-learniq-does-not-listen-yet
	 */
	public function testAnUnansweredImportEndsNoOwnerAnswer(): void {
		$this->runner(target: 'oso')->run(jobId: 'job-1');

		$save = $this->lastSave();
		$this->assertCount(1, $this->landed);
		$this->assertSame('failed', $save['exchangeStatus']);
		$this->assertStringStartsWith('no-owner-answer', $save['exchangeError']);
		$this->assertSame([], $this->stored);
	}//end testAnUnansweredImportEndsNoOwnerAnswer()

	/**
	 * A listener that throws counts as no answer, and its message is not stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-012-an-unanswered-import-ends-with-no-owner-answer
	 */
	public function testAThrowingListenerEndsNoOwnerAnswer(): void {
		$this->listener = static function (): void {
			throw new RuntimeException('duplicate pupil 123456782');
		};

		$this->runner(target: 'migration-import')->run(jobId: 'job-1');

		$save = $this->lastSave();
		$this->assertSame('failed', $save['exchangeStatus']);
		$this->assertStringStartsWith('no-owner-answer', $save['exchangeError']);
		$this->assertStringNotContainsString('123456782', (string) json_encode($save));
	}//end testAThrowingListenerEndsNoOwnerAnswer()
}//end class
