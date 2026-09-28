<?php

/**
 * Tests for the rostering mapping preset registry and the session mapper.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Sources\Roster
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
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Sources\Roster;

use InvalidArgumentException;
use OCA\Integriq\Sources\Roster\RosterMappingPresetRegistry;
use OCA\Integriq\Sources\Roster\RosterSessionMapper;
use PHPUnit\Framework\TestCase;

/**
 * Presets load per source and map a vendor lesson onto a planninq session.
 */
class RosterMappingPresetRegistryTest extends TestCase {
	/**
	 * The four rostering Source row ids in lib/sources.seed.json.
	 */
	private const SOURCES = ['roster-zermelo', 'roster-untis-oneroster', 'roster-xedule', 'roster-timeedit'];

	/**
	 * Every rostering source has a planninq preset naming the required fields.
	 *
	 * @return void
	 */
	public function testEveryRosteringSourceHasAPlanninqPreset(): void {
		$registry = new RosterMappingPresetRegistry();

		$this->assertSame(self::SOURCES, array_keys($registry->all()));
		foreach (self::SOURCES as $id) {
			$preset = $registry->get($id);
			$this->assertSame('planninq.timetableSession', $preset['target']);
			$this->assertSame(1, $preset['contractVersion']);
			foreach (['externalRef', 'subject', 'startsAt', 'endsAt'] as $field) {
				$this->assertArrayHasKey($field, $preset['fields'], "{$id} must map {$field}");
			}

			$this->assertArrayNotHasKey('cohortId', $preset['fields'], 'a fleet id is never read from a vendor');
			$this->assertArrayNotHasKey('teacherUserId', $preset['fields'], 'a fleet id is never read from a vendor');
		}
	}//end testEveryRosteringSourceHasAPlanninqPreset()

	/**
	 * The preset ids match the rostering rows of the sources seed.
	 *
	 * @return void
	 */
	public function testPresetIdsMatchTheSeededSourceRows(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/sources.seed.json'), true);
		$rows = $seed['sources'] ?? $seed;
		$rosterIds = [];
		foreach ($rows as $row) {
			if (($row['subCategory'] ?? '') === 'rostering-import') {
				$rosterIds[] = $row['id'];
			}
		}

		$this->assertSame(self::SOURCES, $rosterIds);
	}//end testPresetIdsMatchTheSeededSourceRows()

	/**
	 * An unknown source is refused.
	 *
	 * @return void
	 */
	public function testUnknownSourceIsRefused(): void {
		$registry = new RosterMappingPresetRegistry();

		$this->assertFalse($registry->has('roster-unknown'));
		$this->expectException(InvalidArgumentException::class);
		$registry->get('roster-unknown');
	}//end testUnknownSourceIsRefused()

	/**
	 * A Zermelo appointment becomes a planninq session: Unix seconds to ISO 8601,
	 * first list element, codes and ids apart, the cancelled flag to a status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
	 */
	public function testZermeloAppointmentBecomesAPlanninqSession(): void {
		$preset = (new RosterMappingPresetRegistry())->get('roster-zermelo');
		$record = [
			'appointmentInstance' => 881002,
			'start' => 1790582400,
			'end' => 1790585400,
			'subjects' => ['wi', 'wa'],
			'groups' => ['3a'],
			'teachers' => ['JAN'],
			'locations' => ['A1.12'],
			'cancelled' => true,
			'cohortId' => 'must-not-leak',
		];

		$session = (new RosterSessionMapper())->map(
			$preset,
			$record,
			['groupMap' => ['3a' => 'cohort-1'], 'teacherMap' => ['PIE' => 'pie']]
		);

		$this->assertSame('881002', $session['externalRef']);
		$this->assertSame('wi', $session['subject']);
		$this->assertSame(1790582400, strtotime($session['startsAt']));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $session['startsAt']);
		$this->assertSame('3a', $session['groupReference']);
		$this->assertSame('cohort-1', $session['cohortId']);
		$this->assertSame('JAN', $session['teacherReference']);
		$this->assertArrayNotHasKey('teacherUserId', $session, 'JAN has no mapping, so no account is invented');
		$this->assertSame('cancelled', $session['status']);
	}//end testZermeloAppointmentBecomesAPlanninqSession()

	/**
	 * An Untis period keeps its offset, maps a numeric group code, and reads the code field as its status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
	 */
	public function testUntisPeriodKeepsItsOffsetAndStatus(): void {
		$preset = (new RosterMappingPresetRegistry())->get('roster-untis-oneroster');
		$mapper = new RosterSessionMapper();
		$maps = ['groupMap' => ['1024' => 'cohort-numeric'], 'teacherMap' => ['KLA' => 'klaas']];

		$session = $mapper->map(
			$preset,
			['id' => 5501, 'startDateTime' => '2026-09-28T09:00:00+02:00', 'endDateTime' => '2026-09-28T09:50:00+02:00', 'klasseId' => 1024, 'faechId' => 'BIO', 'lehrerId' => 'KLA', 'raumId' => '', 'code' => ''],
			$maps
		);

		$this->assertSame('2026-09-28T09:00:00+02:00', $session['startsAt']);
		$this->assertSame('cohort-numeric', $session['cohortId']);
		$this->assertSame('klaas', $session['teacherUserId']);
		$this->assertSame('scheduled', $session['status']);
		$this->assertArrayNotHasKey('roomReference', $session, 'an empty vendor value is not sent');

		$cancelled = $mapper->map($preset, ['id' => 5502, 'code' => 'cancelled'], $maps);
		$this->assertSame('cancelled', $cancelled['status']);
		$this->assertArrayNotHasKey('startsAt', $cancelled, 'an absent vendor field is not sent');
	}//end testUntisPeriodKeepsItsOffsetAndStatus()

	/**
	 * An unreadable time passes through so planninq rejects it with invalid-dates.
	 *
	 * @return void
	 */
	public function testUnreadableTimePassesThroughForPlanninqToReject(): void {
		$preset = (new RosterMappingPresetRegistry())->get('roster-xedule');

		$session = (new RosterSessionMapper())->map(
			$preset,
			['eventId' => 'xe-1', 'activityName' => 'Programmeren', 'startMoment' => 'next tuesday-ish', 'endMoment' => '2026-09-28T10:30:00+02:00'],
			['groupMap' => [], 'teacherMap' => []]
		);

		$this->assertSame('next tuesday-ish', $session['startsAt']);
		$this->assertSame('Programmeren', $session['title']);
	}//end testUnreadableTimePassesThroughForPlanninqToReject()
}//end class
