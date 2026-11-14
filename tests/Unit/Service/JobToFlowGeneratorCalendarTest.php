<?php

/**
 * Integriq — a calendar job translated into a flow cron, or refused.
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
 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-calendar-job-migrates-to-a-flow-only-when-a-cron-can-say-it-req-jcal-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Service\Job\MovedJobClasses;
use OCA\Integriq\Service\JobCalendarSchedule;
use OCA\Integriq\Service\JobIntervalCron;
use OCA\Integriq\Service\JobToFlowGenerator;
use OCA\Integriq\Service\MigrationEntityReader;
use OCA\Integriq\Service\MigrationSubject;
use OCA\Integriq\Service\PipelineEdges;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The server zone is pinned for each test and restored after it.
 */
#[CoversClass(JobToFlowGenerator::class)]
#[CoversClass(JobCalendarSchedule::class)]
#[UsesClass(JobIntervalCron::class)]
#[UsesClass(MigrationEntityReader::class)]
#[UsesClass(MigrationSubject::class)]
#[UsesClass(MovedJobClasses::class)]
#[UsesClass(PipelineEdges::class)]
#[UsesClass(EntityNotMigratableException::class)]
class JobToFlowGeneratorCalendarTest extends TestCase {

	/**
	 * The zone the tests evaluate crons in.
	 *
	 * @var string
	 */
	private const ZONE = 'Europe/Amsterdam';

	/**
	 * The server zone before the test.
	 *
	 * @var string
	 */
	private string $previousZone;

	/**
	 * The generator under test.
	 *
	 * @var JobToFlowGenerator
	 */
	private JobToFlowGenerator $generator;

	/**
	 * Build the generator in the configured-and-valid acting-account state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->previousZone = date_default_timezone_get();
		date_default_timezone_set(self::ZONE);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $parameters === [] ? $text : vsprintf($text, $parameters)
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('integriq-scheduler');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		$this->generator = new JobToFlowGenerator(
			reader: new MigrationEntityReader(objectService: $this->createMock(ORObjectService::class), l10n: $l10n),
			cadence: new JobIntervalCron(),
			subject: new MigrationSubject(),
			l10n: $l10n,
			appConfig: $appConfig,
			userManager: $userManager,
			calendar: new JobCalendarSchedule()
		);
	}//end setUp()

	/**
	 * Restore the server zone.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		date_default_timezone_set($this->previousZone);
		parent::tearDown();
	}//end tearDown()

	/**
	 * A synchronization job on a calendar.
	 *
	 * @param array $calendar The calendar fields that differ from working days at 07:00.
	 *
	 * @return array The job.
	 */
	private static function job(array $calendar = []): array {
		return [
			'uuid' => 'a1b2c3d4-0000-4000-8000-000000000002',
			'name' => 'Morning pull',
			'jobClass' => 'OCA\Integriq\Service\Job\SynchronizationAction',
			'arguments' => ['synchronizationId' => 'morning-pull'],
			// An interval no cron can say: a calendar job is not judged by it.
			'interval' => 4321,
			'isEnabled' => true,
			'schedule' => array_merge(
				['type' => 'calendar', 'weekdays' => [1, 2, 3, 4, 5], 'times' => ['07:00'], 'timeZone' => self::ZONE],
				$calendar
			),
		];
	}//end job()

	/**
	 * The refusal reasons for a job.
	 *
	 * @param array $job The job.
	 *
	 * @return string The reasons, joined.
	 */
	private function refusal(array $job): string {
		try {
			$this->generator->generateFrom(job: $job);
		} catch (EntityNotMigratableException $refusal) {
			return implode(' | ', $refusal->getReasons());
		}

		$this->fail('The generator accepted a calendar a cron cannot say.');
	}//end refusal()

	/**
	 * Monday to Friday at 07:00 becomes `0 7 * * 1-5`, on the flow and on its trigger.
	 *
	 * @return void
	 */
	public function testAWeekdayCalendarBecomesACron(): void {
		$flow = $this->generator->generateFrom(job: self::job());

		$this->assertSame('0 7 * * 1-5', $flow['cron']);
		$this->assertSame('0 7 * * 1-5', $flow['nodes'][0]['config']['cron']);
		$this->assertFalse($flow['enabled']);
		$this->assertStringContainsString('calendar became the cron "0 7 * * 1-5"', $flow['description']);
	}//end testAWeekdayCalendarBecomesACron()

	/**
	 * Calendars a cron can say, with the cron each becomes.
	 *
	 * @return array<string, array{array, string}>
	 */
	public static function expressible(): array {
		return [
			'two times, one minute' => [['times' => ['07:30', '13:30']], '30 7,13 * * 1-5'],
			'weekend, Sunday is 0' => [['weekdays' => [6, 7], 'times' => ['09:00']], '0 9 * * 0,6'],
			'every day' => [['weekdays' => [1, 2, 3, 4, 5, 6, 7]], '0 7 * * *'],
			'scattered days' => [['weekdays' => [1, 3, 4, 5]], '0 7 * * 1,3-5'],
		];
	}//end expressible()

	/**
	 * Each expressible calendar becomes its cron.
	 *
	 * @param array  $calendar The calendar fields.
	 * @param string $cron     The expected cron.
	 *
	 * @return void
	 */
	#[DataProvider('expressible')]
	public function testExpressibleCalendars(array $calendar, string $cron): void {
		$this->assertSame($cron, $this->generator->generateFrom(job: self::job($calendar))['cron']);
	}//end testExpressibleCalendars()

	/**
	 * Calendars a cron cannot say, with the field each refusal names.
	 *
	 * @return array<string, array{array, string}>
	 */
	public static function inexpressible(): array {
		return [
			'last day of the month' => [['dayOfMonth' => 'last'], 'dayOfMonth'],
			'a day number' => [['dayOfMonth' => 15], 'dayOfMonth'],
			'skipped dates' => [['skipDates' => ['2026-12-25']], 'skipDates'],
			'another time zone' => [['timeZone' => 'America/New_York'], 'timeZone'],
			'times with different minutes' => [['times' => ['07:00', '07:30']], 'times'],
		];
	}//end inexpressible()

	/**
	 * A calendar a cron cannot say is refused by the field's name, and no flow is produced.
	 *
	 * @param array  $calendar The calendar fields.
	 * @param string $field    The field the refusal names.
	 *
	 * @return void
	 */
	#[DataProvider('inexpressible')]
	public function testInexpressibleCalendarsAreRefusedByName(array $calendar, string $field): void {
		$reasons = $this->refusal(job: self::job($calendar));

		$this->assertStringStartsWith($field . ': a five-field cron', $reasons);
		$this->assertStringNotContainsString('interval', $reasons);
	}//end testInexpressibleCalendarsAreRefusedByName()

	/**
	 * Every inexpressible field is named at once.
	 *
	 * @return void
	 */
	public function testEveryInexpressibleFieldIsNamed(): void {
		$reasons = $this->generator->refusalsFor(job: self::job(['dayOfMonth' => 'lastWorkingDay', 'skipDates' => ['2026-12-25']]));

		$this->assertCount(2, $reasons);
		$this->assertStringStartsWith('dayOfMonth:', $reasons[0]);
		$this->assertStringStartsWith('skipDates:', $reasons[1]);
	}//end testEveryInexpressibleFieldIsNamed()

	/**
	 * A malformed calendar is refused, naming the schedule.
	 *
	 * @return void
	 */
	public function testAMalformedCalendarIsRefused(): void {
		$reasons = $this->refusal(job: self::job(['weekdays' => []]));

		$this->assertStringStartsWith('schedule: the calendar is not valid (weekdays:', $reasons);
	}//end testAMalformedCalendarIsRefused()

	/**
	 * An interval schedule is still judged by its interval.
	 *
	 * @return void
	 */
	public function testAnIntervalScheduleIsJudgedByItsInterval(): void {
		$job = self::job();
		$job['schedule'] = ['type' => 'interval'];

		$this->assertStringContainsString('interval 4321 seconds', $this->refusal(job: $job));
	}//end testAnIntervalScheduleIsJudgedByItsInterval()
}//end class
