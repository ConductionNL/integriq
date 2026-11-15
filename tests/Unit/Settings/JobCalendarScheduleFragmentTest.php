<?php

/**
 * Integriq — the job calendar schedule register fragment.
 *
 * Validates job objects against the `job` schema as InitializeRegister
 * builds it (the base register merged with every register.d fragment),
 * with opis/json-schema, the validator OpenRegister itself uses.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
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
 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-job-can-carry-a-calendar-schedule-instead-of-an-interval-req-jcal-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Service\JobCalendarSchedule;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The merged job schema accepts calendars and refuses malformed ones.
 */
#[CoversNothing]
class JobCalendarScheduleFragmentTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The `job` schema as InitializeRegister imports it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private static function jobSchema(): array {
		$root = self::root();
		$descriptor = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true, flags: JSON_THROW_ON_ERROR);

		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$descriptor = $merge->invoke(null, $descriptor, $fragment);
			}
		}

		return $descriptor['components']['schemas']['job'];
	}//end jobSchema()

	/**
	 * Whether a job validates against the merged schema.
	 *
	 * @param array $job The job.
	 *
	 * @return boolean True when valid.
	 */
	private static function valid(array $job): bool {
		return (new Validator())->validate(
			json_decode(json_encode($job, JSON_THROW_ON_ERROR)),
			json_encode(self::jobSchema(), JSON_THROW_ON_ERROR)
		)->isValid();
	}//end valid()

	/**
	 * A job carrying a schedule.
	 *
	 * @param array $schedule The schedule fields that differ from working days at 07:00.
	 *
	 * @return array The job.
	 */
	private static function job(array $schedule = []): array {
		return [
			'name' => 'Morning pull',
			'jobClass' => 'OCA\Integriq\Service\Job\SynchronizationAction',
			'schedule' => array_merge(
				['type' => 'calendar', 'weekdays' => [1, 2, 3, 4, 5], 'times' => ['07:00'], 'timeZone' => 'Europe/Amsterdam'],
				$schedule
			),
		];
	}//end job()

	/**
	 * Schedules the schema accepts.
	 *
	 * @return array<string, array{array}>
	 */
	public static function accepted(): array {
		return [
			'working days at seven' => [[]],
			'last working day' => [['dayOfMonth' => 'lastWorkingDay', 'times' => ['18:00']]],
			'a day number' => [['dayOfMonth' => 15]],
			'skipped dates' => [['skipDates' => ['2026-12-25', '2026-12-26']]],
			'interval' => [['type' => 'interval', 'weekdays' => null]],
		];
	}//end accepted()

	/**
	 * Each accepted schedule validates.
	 *
	 * @param array $schedule The schedule fields.
	 *
	 * @return void
	 */
	#[DataProvider('accepted')]
	public function testTheSchemaAcceptsACalendar(array $schedule): void {
		$job = self::job($schedule);
		$job['schedule'] = array_filter($job['schedule'], static fn (mixed $value): bool => $value !== null);

		$this->assertTrue(self::valid($job));
	}//end testTheSchemaAcceptsACalendar()

	/**
	 * Schedules the schema refuses.
	 *
	 * @return array<string, array{array}>
	 */
	public static function refused(): array {
		return [
			'unknown day rule' => [['dayOfMonth' => 'secondTuesday']],
			'day 32' => [['dayOfMonth' => 32]],
			'unknown type' => [['type' => 'cron']],
			'weekday 0' => [['weekdays' => [0]]],
			'no weekdays' => [['weekdays' => []]],
			'time without leading zero' => [['times' => ['7:00']]],
			'not a date' => [['skipDates' => ['25-12-2026']]],
		];
	}//end refused()

	/**
	 * Each refused schedule fails validation.
	 *
	 * @param array $schedule The schedule fields.
	 *
	 * @return void
	 */
	#[DataProvider('refused')]
	public function testTheSchemaRefusesAMalformedCalendar(array $schedule): void {
		$this->assertFalse(self::valid(self::job($schedule)));
	}//end testTheSchemaRefusesAMalformedCalendar()

	/**
	 * A calendar without times is refused, an interval schedule needs none.
	 *
	 * @return void
	 */
	public function testACalendarNeedsItsFieldsAndAnIntervalDoesNot(): void {
		$job = self::job();
		unset($job['schedule']['times']);
		$this->assertFalse(self::valid($job));

		$job['schedule'] = ['type' => 'interval'];
		$this->assertTrue(self::valid($job));
	}//end testACalendarNeedsItsFieldsAndAnIntervalDoesNot()

	/**
	 * The schema's day rules are the calculator's.
	 *
	 * @return void
	 */
	public function testTheDayRulesMatchTheCalculator(): void {
		$enum = self::jobSchema()['properties']['schedule']['properties']['dayOfMonth']['enum'];

		$this->assertSame(JobCalendarSchedule::DAY_RULES, array_values(array_filter($enum, 'is_string')));
		$this->assertSame(range(1, 31), array_values(array_filter($enum, 'is_int')));
	}//end testTheDayRulesMatchTheCalculator()

	/**
	 * A fresh install seeds example-weekday-morning, disabled, valid, and on a calendar.
	 *
	 * @return void
	 */
	public function testTheSeedHasADisabledWeekdayMorningJob(): void {
		$seed = json_decode((string)file_get_contents(self::root() . '/lib/Settings/integriq_seed_data.json'), true, flags: JSON_THROW_ON_ERROR);
		$jobs = array_values(
			array_filter($seed['job'], static fn (array $job): bool => ($job['@self']['slug'] ?? '') === 'example-weekday-morning')
		);

		$this->assertCount(1, $jobs);
		$job = $jobs[0];
		$this->assertFalse($job['isEnabled']);
		unset($job['@self']);
		$this->assertTrue(self::valid($job));
		$this->assertSame(
			'0 7 * * 1-5',
			(new JobCalendarSchedule())->cronFor(calendar: $job['schedule'])
		);
	}//end testTheSeedHasADisabledWeekdayMorningJob()

	/**
	 * The other seeded jobs carry no schedule, so they stay interval jobs.
	 *
	 * @return void
	 */
	public function testTheOtherSeededJobsStayIntervalJobs(): void {
		$seed = json_decode((string)file_get_contents(self::root() . '/lib/Settings/integriq_seed_data.json'), true, flags: JSON_THROW_ON_ERROR);
		foreach ($seed['job'] as $job) {
			if ($job['@self']['slug'] === 'example-weekday-morning') {
				continue;
			}

			$this->assertArrayNotHasKey('schedule', $job, $job['@self']['slug']);
		}
	}//end testTheOtherSeededJobsStayIntervalJobs()
}//end class
