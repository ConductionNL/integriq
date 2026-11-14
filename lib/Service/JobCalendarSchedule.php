<?php

/**
 * The next run of a job on a calendar schedule.
 *
 * A calendar is chosen weekdays, an optional day-of-month rule, one or more
 * times of day, an IANA time zone and dates to skip. This class answers one
 * question about it, "when is the first slot after this moment?", and has no
 * dependencies, so the run loop and the job form's preview give the same
 * answer.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/automation-job-calendar-schedules/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Computes calendar slots for a job schedule.
 *
 * Working days are Monday to Friday minus the calendar's skipped dates (D2).
 * A time that does not exist on a daylight saving day runs at the first valid
 * minute after the gap; a time that exists twice runs once.
 *
 * @spec openspec/changes/automation-job-calendar-schedules/design.md
 */
final class JobCalendarSchedule {

	/**
	 * The day-of-month rules that are not a day number.
	 *
	 * @var array<int, string>
	 */
	public const DAY_RULES = ['first', 'last', 'firstWorkingDay', 'lastWorkingDay'];

	/**
	 * How far ahead a slot is searched for before the calendar is called empty.
	 *
	 * Eight years covers every combination of weekday and day number, which
	 * repeats within a 28-year cycle but always hits within seven years.
	 *
	 * @var integer
	 */
	private const SEARCH_DAYS = 2930;

	/**
	 * Whether a job's schedule is a calendar.
	 *
	 * A job without `schedule`, or with `schedule.type` `interval`, is an
	 * interval job: today's behaviour.
	 *
	 * @param array $jobData The job's data.
	 *
	 * @return boolean True for a calendar job.
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-job-can-carry-a-calendar-schedule-instead-of-an-interval-req-jcal-001
	 */
	public function isCalendar(array $jobData): bool {
		$schedule = ($jobData['schedule'] ?? null);

		return is_array($schedule) === true && ($schedule['type'] ?? null) === 'calendar';
	}//end isCalendar()

	/**
	 * A stable hash of a schedule, so an edit to it can be noticed.
	 *
	 * Key order does not matter: the same calendar saved with its keys in a
	 * different order has the same fingerprint.
	 *
	 * @param array $schedule The job's `schedule` object.
	 *
	 * @return string The fingerprint.
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-next-run-follows-the-calendar-after-success-and-after-failure-req-jcal-002
	 */
	public function fingerprint(array $schedule): string {
		return sha1((string)json_encode(self::sortKeys(value: $schedule)));
	}//end fingerprint()

	/**
	 * The first calendar slot strictly after a moment.
	 *
	 * @param DateTimeInterface $after    The moment; a slot equal to it does not count.
	 * @param array             $calendar The job's `schedule` object.
	 *
	 * @return DateTimeImmutable|null The slot in the calendar's time zone, or null
	 *                                when the calendar has no slot at all.
	 *
	 * @throws InvalidArgumentException When the calendar is malformed; the message names the field.
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-next-run-follows-the-calendar-after-success-and-after-failure-req-jcal-002
	 */
	public function nextRunAfter(DateTimeInterface $after, array $calendar): ?DateTimeImmutable {
		$normal = $this->normalise(calendar: $calendar);
		$zone = $normal['timeZone'];
		$day = (new DateTimeImmutable('@' . $after->getTimestamp()))->setTimezone($zone)->setTime(0, 0);

		for ($offset = 0; $offset < self::SEARCH_DAYS; $offset++) {
			if ($this->matchesDay(day: $day, calendar: $normal) === true) {
				foreach ($normal['times'] as $time) {
					$slot = $this->slotOn(day: $day, time: $time, zone: $zone);
					if ($slot->getTimestamp() > $after->getTimestamp()) {
						return $slot;
					}
				}
			}

			$day = $day->modify('+1 day')->setTime(0, 0);
		}

		return null;
	}//end nextRunAfter()

	/**
	 * The next slots of a calendar after a moment, for the job form's preview.
	 *
	 * @param array                  $calendar The job's `schedule` object.
	 * @param integer                $count    How many slots; at least 1.
	 * @param DateTimeInterface|null $from     The moment to start after; now when null.
	 *
	 * @return array<int, DateTimeImmutable> The slots, ascending; fewer than
	 *                                       `$count` when the calendar runs out.
	 *
	 * @throws InvalidArgumentException When the calendar is malformed or `$count` is below 1.
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
	 */
	public function preview(array $calendar, int $count, ?DateTimeInterface $from = null): array {
		if ($count < 1) {
			throw new InvalidArgumentException('count: a preview needs at least one slot.');
		}

		$after = ($from ?? new DateTimeImmutable());
		$slots = [];
		while (count($slots) < $count) {
			$next = $this->nextRunAfter(after: $after, calendar: $calendar);
			if ($next === null) {
				break;
			}

			$slots[] = $next;
			$after = $next;
		}

		return $slots;
	}//end preview()

	/**
	 * The fields of a calendar a five-field cron cannot say, in the server's zone.
	 *
	 * A cron can say weekdays and whole times. It cannot say a day-of-month
	 * rule such as the last working day, dates to skip, a time zone other
	 * than the one the flow schedule worker reads it in, or several times of
	 * day with different minutes in one expression.
	 *
	 * @param array  $calendar   The job's `schedule` object.
	 * @param string $serverZone The zone the cron is evaluated in.
	 *
	 * @return array<int, string> The field names; empty when a cron can say the calendar.
	 *
	 * @throws InvalidArgumentException When the calendar is malformed.
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-calendar-job-migrates-to-a-flow-only-when-a-cron-can-say-it-req-jcal-004
	 */
	public function cronRefusals(array $calendar, string $serverZone): array {
		$normal = $this->normalise(calendar: $calendar);
		$fields = [];
		if ($normal['dayOfMonth'] !== null) {
			$fields[] = 'dayOfMonth';
		}

		if ($normal['skipDates'] !== []) {
			$fields[] = 'skipDates';
		}

		if ($normal['timeZone']->getName() !== $serverZone) {
			$fields[] = 'timeZone';
		}

		$minutes = array_unique(array_map(static fn (string $time): string => substr($time, 3, 2), $normal['times']));
		if (count($minutes) > 1) {
			$fields[] = 'times';
		}

		return $fields;
	}//end cronRefusals()

	/**
	 * The five-field cron of a calendar that has no cron refusals.
	 *
	 * Weekdays become cron's numbering, where Sunday is 0; runs of
	 * consecutive days become ranges, every day becomes `*`.
	 *
	 * @param array $calendar The job's `schedule` object.
	 *
	 * @return string The cron, as `minute hours * * weekdays`.
	 *
	 * @throws InvalidArgumentException When the calendar is malformed.
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-calendar-job-migrates-to-a-flow-only-when-a-cron-can-say-it-req-jcal-004
	 */
	public function cronFor(array $calendar): string {
		$normal = $this->normalise(calendar: $calendar);
		$minute = (int)substr($normal['times'][0], 3, 2);
		$hours = array_values(array_unique(array_map(static fn (string $time): int => (int)substr($time, 0, 2), $normal['times'])));

		$days = '*';
		if (count($normal['weekdays']) < 7) {
			$cronDays = array_map(static fn (int $day): int => $day % 7, $normal['weekdays']);
			sort($cronDays);
			$days = self::ranges(numbers: $cronDays);
		}

		return sprintf('%d %s * * %s', $minute, implode(',', $hours), $days);
	}//end cronFor()

	/**
	 * Sorted numbers written as cron ranges: 1,2,3,5 becomes 1-3,5.
	 *
	 * @param array<int, int> $numbers The numbers, ascending and unique.
	 *
	 * @return string The list.
	 */
	private static function ranges(array $numbers): string {
		$parts = [];
		$start = $numbers[0];
		$end = $start;
		foreach (array_slice($numbers, 1) as $number) {
			if ($number === $end + 1) {
				$end = $number;
				continue;
			}

			$parts[] = self::range(start: $start, end: $end);
			$start = $number;
			$end = $number;
		}

		$parts[] = self::range(start: $start, end: $end);

		return implode(',', $parts);
	}//end ranges()

	/**
	 * One cron range: `3` for a single number, `1-5` for a run.
	 *
	 * @param integer $start The first number.
	 * @param integer $end   The last number.
	 *
	 * @return string The range.
	 */
	private static function range(int $start, int $end): string {
		if ($start === $end) {
			return (string)$start;
		}

		return $start . '-' . $end;
	}//end range()

	/**
	 * A calendar checked and brought into one shape.
	 *
	 * @param array $calendar The job's `schedule` object.
	 *
	 * @return array{weekdays: array<int, int>, dayOfMonth: string|int|null, times: array<int, string>,
	 *     timeZone: DateTimeZone, skipDates: array<string, true>}
	 *
	 * @throws InvalidArgumentException When a field is malformed; the message names it.
	 *
	 * @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-job-can-carry-a-calendar-schedule-instead-of-an-interval-req-jcal-001
	 */
	public function normalise(array $calendar): array {
		return [
			'weekdays' => $this->weekdaysOf(value: ($calendar['weekdays'] ?? null)),
			'dayOfMonth' => $this->dayRuleOf(value: ($calendar['dayOfMonth'] ?? null)),
			'times' => $this->timesOf(value: ($calendar['times'] ?? null)),
			'timeZone' => $this->zoneOf(value: ($calendar['timeZone'] ?? null)),
			'skipDates' => $this->skipDatesOf(value: ($calendar['skipDates'] ?? [])),
		];
	}//end normalise()

	/**
	 * The weekdays, ISO numbered (1 is Monday, 7 is Sunday), unique and sorted.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, int> The weekdays.
	 *
	 * @throws InvalidArgumentException When the list is empty or holds anything but 1 to 7.
	 */
	private function weekdaysOf(mixed $value): array {
		if (is_array($value) === false || $value === []) {
			throw new InvalidArgumentException('weekdays: a calendar needs at least one weekday.');
		}

		$days = [];
		foreach ($value as $day) {
			if (is_int($day) === false || $day < 1 || $day > 7) {
				throw new InvalidArgumentException('weekdays: each weekday is a number from 1 (Monday) to 7 (Sunday).');
			}

			$days[$day] = $day;
		}

		ksort($days);

		return array_values($days);
	}//end weekdaysOf()

	/**
	 * The day-of-month rule: a named rule, a day number, or null for none.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|integer|null The rule.
	 *
	 * @throws InvalidArgumentException When the value is not a known rule or a day from 1 to 31.
	 */
	private function dayRuleOf(mixed $value): string|int|null {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_string($value) === true && in_array($value, self::DAY_RULES, true) === true) {
			return $value;
		}

		if (is_int($value) === true && $value >= 1 && $value <= 31) {
			return $value;
		}

		throw new InvalidArgumentException(
			'dayOfMonth: use first, last, firstWorkingDay, lastWorkingDay or a day number from 1 to 31.'
		);
	}//end dayRuleOf()

	/**
	 * The times of day as `HH:MM`, unique and sorted.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, string> The times.
	 *
	 * @throws InvalidArgumentException When the list is empty or holds a malformed time.
	 */
	private function timesOf(mixed $value): array {
		if (is_array($value) === false || $value === []) {
			throw new InvalidArgumentException('times: a calendar needs at least one time of day.');
		}

		$times = [];
		foreach ($value as $time) {
			if (is_string($time) === false || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
				throw new InvalidArgumentException('times: each time of day is written as HH:MM, from 00:00 to 23:59.');
			}

			$times[$time] = $time;
		}

		ksort($times);

		return array_values($times);
	}//end timesOf()

	/**
	 * The calendar's time zone.
	 *
	 * @param mixed $value The stored IANA name.
	 *
	 * @return DateTimeZone The zone.
	 *
	 * @throws InvalidArgumentException When the name is missing or unknown.
	 */
	private function zoneOf(mixed $value): DateTimeZone {
		if (is_string($value) === false || in_array($value, DateTimeZone::listIdentifiers(), true) === false) {
			throw new InvalidArgumentException('timeZone: use an IANA time zone such as Europe/Amsterdam.');
		}

		return new DateTimeZone($value);
	}//end zoneOf()

	/**
	 * The dates to skip, keyed by `YYYY-MM-DD` for lookup.
	 *
	 * @param mixed $value The stored list.
	 *
	 * @return array<string, true> The dates.
	 *
	 * @throws InvalidArgumentException When the value is not a list of real dates.
	 */
	private function skipDatesOf(mixed $value): array {
		if ($value === null) {
			return [];
		}

		if (is_array($value) === false) {
			throw new InvalidArgumentException('skipDates: a list of dates written as YYYY-MM-DD.');
		}

		$dates = [];
		foreach ($value as $date) {
			$parsed = false;
			if (is_string($date) === true) {
				$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
			}

			if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
				throw new InvalidArgumentException('skipDates: a list of dates written as YYYY-MM-DD.');
			}

			$dates[$date] = true;
		}

		return $dates;
	}//end skipDatesOf()

	/**
	 * Whether a calendar runs on a day at all.
	 *
	 * @param DateTimeImmutable $day      Midnight of the day, in the calendar's zone.
	 * @param array             $calendar The normalised calendar.
	 *
	 * @return boolean True when the day has slots.
	 */
	private function matchesDay(DateTimeImmutable $day, array $calendar): bool {
		if (isset($calendar['skipDates'][$day->format('Y-m-d')]) === true) {
			return false;
		}

		if (in_array((int)$day->format('N'), $calendar['weekdays'], true) === false) {
			return false;
		}

		$rule = $calendar['dayOfMonth'];
		$dayNumber = (int)$day->format('j');

		return match ($rule) {
			null => true,
			'first' => $dayNumber === 1,
			'last' => $dayNumber === (int)$day->format('t'),
			'firstWorkingDay' => $dayNumber === $this->workingDayOfMonth(day: $day, skipDates: $calendar['skipDates'], fromEnd: false),
			'lastWorkingDay' => $dayNumber === $this->workingDayOfMonth(day: $day, skipDates: $calendar['skipDates'], fromEnd: true),
			default => $dayNumber === $rule,
		};
	}//end matchesDay()

	/**
	 * The day number of the first or last working day in a day's month.
	 *
	 * @param DateTimeImmutable    $day       Any day in the month.
	 * @param array<string, true>  $skipDates The skipped dates.
	 * @param boolean              $fromEnd   True for the last working day.
	 *
	 * @return integer The day number; 0 when the month has no working day.
	 */
	private function workingDayOfMonth(DateTimeImmutable $day, array $skipDates, bool $fromEnd): int {
		$length = (int)$day->format('t');
		$numbers = range(1, $length);
		if ($fromEnd === true) {
			$numbers = array_reverse($numbers);
		}

		foreach ($numbers as $number) {
			$candidate = $day->setDate((int)$day->format('Y'), (int)$day->format('n'), $number);
			if ((int)$candidate->format('N') <= 5 && isset($skipDates[$candidate->format('Y-m-d')]) === false) {
				return $number;
			}
		}

		return 0;
	}//end workingDayOfMonth()

	/**
	 * The instant of a time of day on a day, in a zone.
	 *
	 * A wall-clock time inside a daylight saving gap does not exist; the slot
	 * is then the first valid minute after the gap, the moment of the change.
	 *
	 * @param DateTimeImmutable $day  Midnight of the day, in the zone.
	 * @param string            $time The time as HH:MM.
	 * @param DateTimeZone      $zone The zone.
	 *
	 * @return DateTimeImmutable The slot.
	 */
	private function slotOn(DateTimeImmutable $day, string $time, DateTimeZone $zone): DateTimeImmutable {
		[$hour, $minute] = array_map('intval', explode(':', $time));
		$slot = $day->setTime($hour, $minute);
		if ($slot->format('H:i') === $time && $slot->format('Y-m-d') === $day->format('Y-m-d')) {
			return $slot;
		}

		// Inside a gap: PHP moved the time forward by the gap's length. The
		// change itself happened at the last transition before that instant.
		$transitions = $zone->getTransitions($slot->getTimestamp() - 86400, $slot->getTimestamp());
		$last = end($transitions);
		if (is_array($last) === true && $last['ts'] <= $slot->getTimestamp()) {
			return (new DateTimeImmutable('@' . $last['ts']))->setTimezone($zone);
		}

		return $slot;
	}//end slotOn()

	/**
	 * A value with every associative array's keys sorted, recursively.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, sorted.
	 */
	private static function sortKeys(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(static fn (mixed $item): mixed => self::sortKeys(value: $item), $value);
	}//end sortKeys()
}//end class
