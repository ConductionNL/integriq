<?php

/**
 * Integriq Roster Session Mapper.
 *
 * Applies a rostering mapping preset to one vendor lesson record and returns
 * a planninq timetable session row (planninq contract v1, change
 * `school-timetable-target`). Three transforms cover the four vendors:
 * `text` (a scalar, or the first element of a list), `datetime` (Unix
 * seconds or a parseable date and time, to ISO 8601) and `status` (a flag or
 * code to `scheduled` or `cancelled`).
 *
 * The school's own codes and the fleet ids are kept apart: the vendor feeds
 * `groupReference` and `teacherReference`, and only the target
 * configuration's maps fill `cohortId` and `teacherUserId`.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Roster
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
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Maps a vendor lesson onto a planninq timetable session.
 *
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
 */
class RosterSessionMapper {
	/**
	 * The planninq fields a preset may feed. `cohortId` and `teacherUserId`
	 * are deliberately absent: they come from the target configuration only.
	 */
	private const MAPPABLE_FIELDS = [
		'externalRef',
		'subject',
		'title',
		'startsAt',
		'endsAt',
		'groupReference',
		'teacherReference',
		'roomReference',
		'roomLabel',
		'status',
	];

	/**
	 * Map one vendor record.
	 *
	 * @param array<string,mixed>                                          $preset The preset (its `fields` are read).
	 * @param array<string,mixed>                                          $record The vendor lesson record.
	 * @param array{groupMap:array<string,string>,teacherMap:array<string,string>} $maps   Code to fleet id maps.
	 *
	 * @return array<string,string> The planninq session row.
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
	 */
	public function map(array $preset, array $record, array $maps): array {
		$session = [];
		$fields = $preset['fields'] ?? [];

		foreach (self::MAPPABLE_FIELDS as $field) {
			$rule = $fields[$field] ?? null;
			if (is_array($rule) === false || array_key_exists((string)($rule['from'] ?? ''), $record) === false) {
				continue;
			}

			$value = $this->transform(rule: $rule, value: $record[$rule['from']]);
			if ($value !== null) {
				$session[$field] = $value;
			}
		}

		$group = $session['groupReference'] ?? '';
		if ($group !== '' && isset($maps['groupMap'][$group]) === true) {
			$session['cohortId'] = $maps['groupMap'][$group];
		}

		$teacher = $session['teacherReference'] ?? '';
		if ($teacher !== '' && isset($maps['teacherMap'][$teacher]) === true) {
			$session['teacherUserId'] = $maps['teacherMap'][$teacher];
		}

		return $session;
	}//end map()

	/**
	 * Apply one field rule to one vendor value.
	 *
	 * @param array<string,mixed> $rule  The field rule (`from`, `transform`, `cancelledValues`).
	 * @param mixed               $value The vendor value.
	 *
	 * @return string|null The mapped value, or null when there is nothing to send.
	 */
	private function transform(array $rule, mixed $value): ?string {
		$transform = (string)($rule['transform'] ?? 'text');

		if ($transform === 'status') {
			return $this->status(value: $value, cancelledValues: (array)($rule['cancelledValues'] ?? []));
		}

		$text = $this->text(value: $value);
		if ($text === null || $transform !== 'datetime') {
			return $text;
		}

		return $this->datetime(text: $text);
	}//end transform()

	/**
	 * A scalar, or the first scalar of a list, as trimmed text.
	 *
	 * @param mixed $value The vendor value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_array($value) === true) {
			$value = (array_values($value)[0] ?? null);
		}

		if (is_scalar($value) === false || is_bool($value) === true) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()

	/**
	 * Unix seconds or a parseable date and time, as ISO 8601; unreadable text
	 * passes through so planninq rejects it with `invalid-dates`.
	 *
	 * @param string $text The vendor value as text.
	 *
	 * @return string
	 */
	private function datetime(string $text): string {
		try {
			if (ctype_digit($text) === true) {
				return (new DateTimeImmutable('@' . $text))
					->setTimezone(new DateTimeZone(date_default_timezone_get()))
					->format(DATE_ATOM);
			}

			// A text value keeps the offset the vendor sent.
			return (new DateTimeImmutable($text))->format(DATE_ATOM);
		} catch (Exception $e) {
			return $text;
		}
	}//end datetime()

	/**
	 * A flag or code as a planninq status.
	 *
	 * @param mixed            $value           The vendor value.
	 * @param array<int,mixed> $cancelledValues The values that mean cancelled.
	 *
	 * @return string `cancelled` or `scheduled`.
	 */
	private function status(mixed $value, array $cancelledValues): string {
		if (in_array($value, $cancelledValues, true) === true) {
			return 'cancelled';
		}

		return 'scheduled';
	}//end status()
}//end class
