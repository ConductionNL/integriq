<?php

/**
 * Integriq — calendar schedule calculator tests.
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
use InvalidArgumentException;
use OCA\Integriq\Service\JobCalendarSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every moment in these tests is fixed; nothing reads the system clock.
 */
#[CoversClass(JobCalendarSchedule::class)]
class JobCalendarScheduleTest extends TestCase {

	/**
	 * Monday to Friday.
	 *
	 * @var array<int, int>
	 */
	private const WORKING_DAYS = [1, 2, 3, 4, 5];

	/**
	 * Every day of the week.
	 *
	 * @var array<int, int>
	 */
	private const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7];

	/**
	 * The calculator under test.
	 *
	 * @var JobCalendarSchedule
	 */
	private JobCalendarSchedule $calendar;

	/**
	 * Build the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calendar = new JobCalendarSchedule();
	}//end setUp()

	/**
	 * A moment in Amsterdam time.
	 *
	 * @param string $local The wall-clock time.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private static function amsterdam(string $local): DateTimeImmutable {
		return new DateTimeImmutable($local, new DateTimeZone('Europe/Amsterdam'));
	}//end amsterdam()

	/**
	 * A calendar in Amsterdam time.
	 *
	 * @param array $fields The fields that differ from a working-day 07:00 calendar.
	 *
	 * @return array The calendar.
	 */
	private static function calendar(array $fields = []): array {
		return array_merge(
			['type' => 'calendar', 'weekdays' => self::WORKING_DAYS, 'times' => ['07:00'], 'timeZone' => 'Europe/Amsterdam'],
			$fields
		);
	}//end calendar()

	/**
	 * The last working day of October 2026 is Friday the 30th: the 31st is a Saturday.
	 *
	 * @return void
	 */
	public function testLastWorkingDayOfTheMonth(): void {
		$next = $this->calendar->nextRunAfter(
			after: self::amsterdam('2026-10-01 00:00'),
			calendar: self::calendar(['weekdays' => self::EVERY_DAY, 'dayOfMonth' => 'lastWorkingDay', 'times' => ['18:00']])
		);

		$this->assertSame('2026-10-30T18:00:00+01:00', $next?->format('c'));
	}//end testLastWorkingDayOfTheMonth()

	/**
	 * A skipped Friday moves the run to the next working day, Monday.
	 *
	 * @return void
	 */
	public function testASkippedDateIsSkipped(): void {
		$next = $this->calendar->nextRunAfter(
			after: self::amsterdam('2026-12-24 08:00'),
			calendar: self::calendar(['skipDates' => ['2026-12-25']])
		);

		$this->assertSame('2026-12-28T07:00:00+01:00', $next?->format('c'));
	}//end testASkippedDateIsSkipped()

	/**
	 * Without the skipped date the same calendar runs on the Friday.
	 *
	 * @return void
	 */
	public function testWithoutTheSkippedDateTheFridayRuns(): void {
		$next = $this->calendar->nextRunAfter(after: self::amsterdam('2026-12-24 08:00'), calendar: self::calendar());

		$this->assertSame('2026-12-25T07:00:00+01:00', $next?->format('c'));
	}//end testWithoutTheSkippedDateTheFridayRuns()

	/**
	 * 02:30 does not exist on 29 March 2026 in Amsterdam: the slot is 03:00, the first valid minute.
	 *
	 * @return void
	 */
	public function testATimeInTheSpringGapRunsAtTheNextValidMinute(): void {
		$next = $this->calendar->nextRunAfter(
			after: self::amsterdam('2026-03-28 12:00'),
			calendar: self::calendar(['weekdays' => [7], 'times' => ['02:30']])
		);

		$this->assertSame('2026-03-29T03:00:00+02:00', $next?->format('c'));
	}//end testATimeInTheSpringGapRunsAtTheNextValidMinute()

	/**
	 * 02:30 exists twice on 25 October 2026 in Amsterdam: it runs once, then a week later.
	 *
	 * @return void
	 */
	public function testATimeThatExistsTwiceInAutumnRunsOnce(): void {
		$slots = $this->calendar->preview(
			calendar: self::calendar(['weekdays' => [7], 'times' => ['02:30']]),
			count: 2,
			from: self::amsterdam('2026-10-24 12:00')
		);

		$this->assertSame(
			['2026-10-25T02:30:00+02:00', '2026-11-01T02:30:00+01:00'],
			array_map(static fn (DateTimeImmutable $slot): string => $slot->format('c'), $slots)
		);
	}//end testATimeThatExistsTwiceInAutumnRunsOnce()

	/**
	 * A failure on Tuesday at 07:00 waits for Wednesday at 07:00.
	 *
	 * @return void
	 */
	public function testTheNextSlotAfterARunIsTheNextWorkingDay(): void {
		$next = $this->calendar->nextRunAfter(after: self::amsterdam('2026-11-10 07:01'), calendar: self::calendar());

		$this->assertSame('2026-11-11T07:00:00+01:00', $next?->format('c'));
	}//end testTheNextSlotAfterARunIsTheNextWorkingDay()

	/**
	 * A slot equal to the moment does not count; the next time of day on the same day does.
	 *
	 * @return void
	 */
	public function testSeveralTimesOnOneDayAndASlotEqualToTheMomentIsSkipped(): void {
		$next = $this->calendar->nextRunAfter(
			after: self::amsterdam('2026-11-10 07:00'),
			calendar: self::calendar(['times' => ['12:30', '07:00']])
		);

		$this->assertSame('2026-11-10T12:30:00+01:00', $next?->format('c'));
	}//end testSeveralTimesOnOneDayAndASlotEqualToTheMomentIsSkipped()

	/**
	 * The preview lists the next five first working days.
	 *
	 * @return void
	 */
	public function testThePreviewListsTheNextFiveFirstWorkingDays(): void {
		$slots = $this->calendar->preview(
			calendar: self::calendar(['weekdays' => self::EVERY_DAY, 'dayOfMonth' => 'firstWorkingDay', 'times' => ['06:00'], 'timeZone' => 'UTC']),
			count: 5,
			from: new DateTimeImmutable('2026-11-13T12:00:00+00:00')
		);

		$this->assertSame(
			['2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01'],
			array_map(static fn (DateTimeImmutable $slot): string => $slot->format('Y-m-d'), $slots)
		);
	}//end testThePreviewListsTheNextFiveFirstWorkingDays()

	/**
	 * A skipped first working day moves the rule to the next working day.
	 *
	 * @return void
	 */
	public function testASkippedDateMovesTheFirstWorkingDay(): void {
		$next = $this->calendar->nextRunAfter(
			after: new DateTimeImmutable('2026-12-15T00:00:00+00:00'),
			calendar: self::calendar(['weekdays' => self::EVERY_DAY, 'dayOfMonth' => 'firstWorkingDay', 'timeZone' => 'UTC', 'skipDates' => ['2027-01-01']])
		);

		$this->assertSame('2027-01-04T07:00:00+00:00', $next?->format('c'));
	}//end testASkippedDateMovesTheFirstWorkingDay()

	/**
	 * Day rules first and last, and a day number.
	 *
	 * @return array<string, array{0: string|int, 1: string, 2?: string}>
	 */
	public static function dayRules(): array {
		return [
			'first' => ['first', '2026-12-01'],
			'last' => ['last', '2026-11-30'],
			'last of a 31-day month' => ['last', '2026-12-31', '2026-12-01T00:00:00+00:00'],
			'day 15' => [15, '2026-11-15'],
			'day 31 skips November' => [31, '2026-12-31'],
		];
	}//end dayRules()

	/**
	 * A day-of-month rule picks its day.
	 *
	 * @param string|integer $rule     The rule.
	 * @param string         $expected The expected date.
	 * @param string         $from     The moment to compute from.
	 *
	 * @return void
	 */
	#[DataProvider('dayRules')]
	public function testDayOfMonthRules(string|int $rule, string $expected, string $from = '2026-11-13T12:00:00+00:00'): void {
		$next = $this->calendar->nextRunAfter(
			after: new DateTimeImmutable($from),
			calendar: self::calendar(['weekdays' => self::EVERY_DAY, 'dayOfMonth' => $rule, 'timeZone' => 'UTC'])
		);

		$this->assertSame($expected . 'T07:00:00+00:00', $next?->format('c'));
	}//end testDayOfMonthRules()

	/**
	 * A calendar whose rule never meets its weekdays has no slot.
	 *
	 * @return void
	 */
	public function testACalendarWithoutASlotAnswersNull(): void {
		$this->assertNull(
			$this->calendar->nextRunAfter(
				after: new DateTimeImmutable('2026-11-13T12:00:00+00:00'),
				calendar: self::calendar(['weekdays' => [6], 'dayOfMonth' => 'firstWorkingDay'])
			)
		);
	}//end testACalendarWithoutASlotAnswersNull()

	/**
	 * Malformed calendars, each with the field its refusal names.
	 *
	 * @return array<string, array{array, string}>
	 */
	public static function malformed(): array {
		return [
			'no weekdays' => [['weekdays' => []], 'weekdays:'],
			'weekday 8' => [['weekdays' => [8]], 'weekdays:'],
			'weekday as text' => [['weekdays' => ['1']], 'weekdays:'],
			'unknown day rule' => [['dayOfMonth' => 'secondTuesday'], 'dayOfMonth:'],
			'day 32' => [['dayOfMonth' => 32], 'dayOfMonth:'],
			'no times' => [['times' => []], 'times:'],
			'time 24:00' => [['times' => ['24:00']], 'times:'],
			'time 7:00' => [['times' => ['7:00']], 'times:'],
			'unknown zone' => [['timeZone' => 'Mars/Olympus'], 'timeZone:'],
			'impossible date' => [['skipDates' => ['2026-02-30']], 'skipDates:'],
			'skip dates not a list' => [['skipDates' => '2026-12-25'], 'skipDates:'],
		];
	}//end malformed()

	/**
	 * A malformed calendar is refused with the field's name.
	 *
	 * @param array  $fields The malformed fields.
	 * @param string $field  The field the message starts with.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testAMalformedCalendarIsRefusedByName(array $fields, string $field): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/^' . preg_quote($field, '/') . '/');

		$this->calendar->nextRunAfter(after: new DateTimeImmutable(), calendar: self::calendar($fields));
	}//end testAMalformedCalendarIsRefusedByName()

	/**
	 * A preview of no slots is refused.
	 *
	 * @return void
	 */
	public function testAPreviewOfNoSlotsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('count: a preview needs at least one slot.');

		$this->calendar->preview(calendar: self::calendar(), count: 0);
	}//end testAPreviewOfNoSlotsIsRefused()

	/**
	 * Only a schedule of type calendar is a calendar.
	 *
	 * @return void
	 */
	public function testIsCalendar(): void {
		$this->assertTrue($this->calendar->isCalendar(jobData: ['schedule' => ['type' => 'calendar']]));
		$this->assertFalse($this->calendar->isCalendar(jobData: ['schedule' => ['type' => 'interval']]));
		$this->assertFalse($this->calendar->isCalendar(jobData: ['interval' => 3600]));
		$this->assertFalse($this->calendar->isCalendar(jobData: ['schedule' => 'calendar']));
	}//end testIsCalendar()

	/**
	 * The fingerprint ignores key order and changes with the calendar.
	 *
	 * @return void
	 */
	public function testTheFingerprintIgnoresKeyOrderAndFollowsEdits(): void {
		$one = $this->calendar->fingerprint(schedule: ['type' => 'calendar', 'times' => ['07:00'], 'weekdays' => [1]]);
		$same = $this->calendar->fingerprint(schedule: ['weekdays' => [1], 'type' => 'calendar', 'times' => ['07:00']]);
		$edited = $this->calendar->fingerprint(schedule: ['type' => 'calendar', 'times' => ['09:00'], 'weekdays' => [1]]);

		$this->assertSame($one, $same);
		$this->assertNotSame($one, $edited);
		$this->assertSame(40, strlen($one));
	}//end testTheFingerprintIgnoresKeyOrderAndFollowsEdits()

	/**
	 * Duplicated weekdays and times are read once, in order.
	 *
	 * @return void
	 */
	public function testNormaliseDeduplicatesAndSorts(): void {
		$normal = $this->calendar->normalise(
			calendar: self::calendar(['weekdays' => [5, 1, 5], 'times' => ['09:00', '07:00', '09:00'], 'dayOfMonth' => ''])
		);

		$this->assertSame([1, 5], $normal['weekdays']);
		$this->assertSame(['07:00', '09:00'], $normal['times']);
		$this->assertNull($normal['dayOfMonth']);
		$this->assertSame([], $normal['skipDates']);
		$this->assertSame('Europe/Amsterdam', $normal['timeZone']->getName());
	}//end testNormaliseDeduplicatesAndSorts()
}//end class
