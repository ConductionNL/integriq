<?php

/**
 * Integriq — the run loop on a calendar schedule.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-next-run-follows-the-calendar-after-success-and-after-failure-req-jcal-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\JobCalendarSchedule;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The clock is Tuesday 10 November 2026, 07:02 Amsterdam time, two minutes
 * after a working-day 07:00 slot.
 */
#[CoversClass(JobService::class)]
#[UsesClass(JobCalendarSchedule::class)]
class JobServiceCalendarTest extends TestCase {

	/**
	 * A working-day calendar at 07:00 Amsterdam time.
	 *
	 * @var array<string, mixed>
	 */
	private const WORKING_DAYS_AT_SEVEN = [
		'type' => 'calendar',
		'weekdays' => [1, 2, 3, 4, 5],
		'times' => ['07:00'],
		'timeZone' => 'Europe/Amsterdam',
	];

	/**
	 * The service under test.
	 *
	 * @var JobService
	 */
	private JobService $service;

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * Every object handed to saveObject(), per schema.
	 *
	 * @var array<string, array<int, array>>
	 */
	private array $saved = [];

	/**
	 * The jobs findAll() serves.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $jobs = [];

	/**
	 * What the job's action does: a value to return or a throwable to throw.
	 *
	 * @var mixed
	 */
	private mixed $actionOutcome = ['level' => 'SUCCESS'];

	/**
	 * How often the job's action ran.
	 *
	 * @var integer
	 */
	private int $actionRuns = 0;

	/**
	 * Build the service with a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objects = ObjectServiceMockBuilder::make($this);
		$this->objects->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->saved[$args[2]][] = $args[0];
				return ObjectServiceMockBuilder::objectEntity($this, $args[0], 'saved-' . $args[2]);
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			fn (): array => ['results' => $this->jobs, 'total' => count($this->jobs)]
		);

		$action = new class($this) {
			/**
			 * @param JobServiceCalendarTest $test The test that decides the outcome.
			 */
			public function __construct(private JobServiceCalendarTest $test) {
			}

			/**
			 * @param array $args The job's arguments.
			 *
			 * @return mixed The outcome.
			 */
			public function run(array $args): mixed {
				return $this->test->runAction();
			}
		};
		$traceService = $this->createMock(ExecutionTraceService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class): object => $class === ExecutionTraceService::class ? $traceService : $action
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-10 07:02', new DateTimeZone('Europe/Amsterdam')));

		$this->service = new JobService(
			$this->createMock(IJobList::class),
			$this->objects,
			$this->createMock(IDBConnection::class),
			$container,
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$appConfig,
			new JobCalendarSchedule(),
			$clock,
		);
	}//end setUp()

	/**
	 * The job's action: counts the run and returns or throws the outcome.
	 *
	 * @return mixed The outcome.
	 */
	public function runAction(): mixed {
		$this->actionRuns++;
		if ($this->actionOutcome instanceof \Throwable) {
			throw $this->actionOutcome;
		}

		return $this->actionOutcome;
	}//end runAction()

	/**
	 * A job whose data is merged over an enabled calendar job.
	 *
	 * @param array $overrides The fields that differ.
	 *
	 * @return ObjectEntity The job.
	 */
	private function job(array $overrides = []): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			array_merge(
				['isEnabled' => true, 'jobClass' => 'Some\\Action', 'interval' => 300, 'schedule' => self::WORKING_DAYS_AT_SEVEN],
				$overrides
			),
			'job-1'
		);
	}//end job()

	/**
	 * The fingerprint the service stores for the working-day calendar.
	 *
	 * @param array $schedule The schedule.
	 *
	 * @return string The fingerprint.
	 */
	private static function fingerprintOf(array $schedule = self::WORKING_DAYS_AT_SEVEN): string {
		return (new JobCalendarSchedule())->fingerprint(schedule: $schedule);
	}//end fingerprintOf()

	/**
	 * A calendar job that fails on Tuesday waits for Wednesday at 07:00, not the next tick.
	 *
	 * @return void
	 */
	public function testAFailedCalendarRunWaitsForTheNextSlot(): void {
		$this->actionOutcome = new RuntimeException('partner down');

		$this->service->executeJob(
			job: $this->job(['nextRun' => '2026-11-10T07:00:00+01:00', 'scheduleFingerprint' => self::fingerprintOf()])
		);

		$this->assertSame(1, $this->actionRuns);
		$job = $this->saved['job'][0];
		$this->assertSame('2026-11-11T07:00:00+01:00', $job['nextRun']);
		$this->assertSame(self::fingerprintOf(), $job['scheduleFingerprint']);
		$this->assertSame('ERROR', $this->saved['job_log'][0]['level']);
	}//end testAFailedCalendarRunWaitsForTheNextSlot()

	/**
	 * A successful calendar run also moves to the next slot, not by its interval.
	 *
	 * @return void
	 */
	public function testASuccessfulCalendarRunMovesToTheNextSlot(): void {
		$this->service->executeJob(
			job: $this->job(['nextRun' => '2026-11-10T07:00:00+01:00', 'scheduleFingerprint' => self::fingerprintOf()])
		);

		$this->assertSame('2026-11-11T07:00:00+01:00', $this->saved['job'][0]['nextRun']);
	}//end testASuccessfulCalendarRunMovesToTheNextSlot()

	/**
	 * A single-run calendar job is disabled after a success, like an interval job.
	 *
	 * @return void
	 */
	public function testASingleRunCalendarJobIsDisabledAfterSuccess(): void {
		$this->service->executeJob(
			job: $this->job(['nextRun' => '2026-11-10T07:00:00+01:00', 'scheduleFingerprint' => self::fingerprintOf(), 'singleRun' => true])
		);

		$this->assertFalse($this->saved['job'][0]['isEnabled']);
	}//end testASingleRunCalendarJobIsDisabledAfterSuccess()

	/**
	 * A force run keeps the calendar's next run untouched.
	 *
	 * @return void
	 */
	public function testAForceRunKeepsTheSchedule(): void {
		$this->service->executeJob(
			job: $this->job(['nextRun' => '2026-11-12T07:00:00+01:00', 'scheduleFingerprint' => self::fingerprintOf()]),
			forceRun: true
		);

		$this->assertSame('2026-11-12T07:00:00+01:00', $this->saved['job'][0]['nextRun']);
	}//end testAForceRunKeepsTheSchedule()

	/**
	 * A calendar job with no next run gets one saved and does not run on that tick.
	 *
	 * @return void
	 */
	public function testACalendarJobWithoutANextRunIsScheduledNotRun(): void {
		$this->jobs = [$this->job()];

		$logs = $this->service->run();

		$this->assertSame([], $logs);
		$this->assertSame(0, $this->actionRuns);
		$this->assertSame(['job'], array_keys($this->saved));
		$this->assertSame('2026-11-11T07:00:00+01:00', $this->saved['job'][0]['nextRun']);
		$this->assertSame(self::fingerprintOf(), $this->saved['job'][0]['scheduleFingerprint']);
	}//end testACalendarJobWithoutANextRunIsScheduledNotRun()

	/**
	 * An edited calendar is picked up on the next tick, and the job does not run early.
	 *
	 * @return void
	 */
	public function testAnEditedCalendarIsRescheduledNotRun(): void {
		$edited = array_merge(self::WORKING_DAYS_AT_SEVEN, ['times' => ['09:00']]);
		$this->jobs = [
			$this->job(['schedule' => $edited, 'nextRun' => '2026-11-10T07:00:00+01:00', 'scheduleFingerprint' => self::fingerprintOf()]),
		];

		$this->service->run();

		$this->assertSame(0, $this->actionRuns);
		$this->assertSame('2026-11-10T09:00:00+01:00', $this->saved['job'][0]['nextRun']);
		$this->assertSame(self::fingerprintOf($edited), $this->saved['job'][0]['scheduleFingerprint']);
	}//end testAnEditedCalendarIsRescheduledNotRun()

	/**
	 * A due calendar job with an unchanged calendar runs.
	 *
	 * @return void
	 */
	public function testADueCalendarJobRuns(): void {
		$this->jobs = [$this->job(['nextRun' => '2026-11-10T07:00:00+01:00', 'scheduleFingerprint' => self::fingerprintOf()])];

		$logs = $this->service->run();

		$this->assertCount(1, $logs);
		$this->assertSame(1, $this->actionRuns);
		$this->assertSame('2026-11-11T07:00:00+01:00', $this->saved['job'][0]['nextRun']);
	}//end testADueCalendarJobRuns()

	/**
	 * A calendar with no slot is computed once and then never runs on its own.
	 *
	 * @return void
	 */
	public function testACalendarWithoutASlotNeverRuns(): void {
		$never = array_merge(self::WORKING_DAYS_AT_SEVEN, ['weekdays' => [6], 'dayOfMonth' => 'firstWorkingDay']);
		$this->jobs = [$this->job(['schedule' => $never])];

		$this->service->run();
		$this->assertArrayNotHasKey('nextRun', $this->saved['job'][0]);

		$this->jobs = [$this->job(['schedule' => $never, 'scheduleFingerprint' => self::fingerprintOf($never)])];
		$this->saved = [];
		$this->service->run();

		$this->assertSame(0, $this->actionRuns);
		$this->assertSame([], $this->saved);
	}//end testACalendarWithoutASlotNeverRuns()

	/**
	 * A malformed calendar does not stop the other jobs of the pass.
	 *
	 * @return void
	 */
	public function testAMalformedCalendarDoesNotStopThePass(): void {
		$this->jobs = [
			$this->job(['schedule' => ['type' => 'calendar', 'weekdays' => []]]),
			ObjectServiceMockBuilder::objectEntity($this, ['isEnabled' => true, 'jobClass' => 'Some\\Action', 'interval' => 3600], 'job-2'),
		];

		$logs = $this->service->run();

		$this->assertCount(1, $logs);
		$this->assertSame(1, $this->actionRuns);
	}//end testAMalformedCalendarDoesNotStopThePass()

	/**
	 * An interval job without a schedule still moves by its interval.
	 *
	 * @return void
	 */
	public function testAnIntervalJobWithoutAScheduleIsUnchanged(): void {
		$before = time();
		$this->service->executeJob(
			job: ObjectServiceMockBuilder::objectEntity($this, ['isEnabled' => true, 'jobClass' => 'Some\\Action', 'interval' => 3600], 'job-3')
		);

		$job = $this->saved['job'][0];
		$this->assertArrayNotHasKey('scheduleFingerprint', $job);
		$next = (new DateTimeImmutable($job['nextRun']))->getTimestamp();
		$this->assertGreaterThanOrEqual($before + 3600 - 60, $next);
		$this->assertLessThanOrEqual(time() + 3600, $next);
	}//end testAnIntervalJobWithoutAScheduleIsUnchanged()
}//end class
