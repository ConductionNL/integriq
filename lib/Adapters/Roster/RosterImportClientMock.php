<?php

/**
 * Integriq roster-import client (mock).
 *
 * Deterministic, no-network implementation of
 * {@see RosterImportClient}. Ships dormant: DI returns this class for
 * every source, because no live binding exists yet. The canned batches
 * match `tests/fixtures/roster/fixture-roster-batch.json` so the
 * rostering mapping presets can be developed and tested against a
 * stable surface without contacting any scheduling system.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Roster
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
 * @spec openspec/changes/integriq-adapter-rostering-imports/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Roster;

/**
 * Mock roster-import client — dormant default.
 *
 * Returns two canned lessons per rostering source, each batch in that
 * vendor's own field names (a Zermelo appointment, a WebUntis period, a
 * Xedule event, a TimeEdit reservation), so every preset in
 * `lib/roster-mapping-presets.seed.json` is exercised in mock mode. An
 * unknown source id returns no lessons.
 *
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
 */
final class RosterImportClientMock extends RosterImportClient {
	/**
	 * Canned vendor-shaped batches, keyed by Source row id. Mirrors
	 * `tests/fixtures/roster/fixture-roster-batch.json`.
	 */
	private const BATCHES = [
		'roster-zermelo' => [
			[
				'appointmentInstance' => 881001,
				'start' => 1790578800,
				'end' => 1790581800,
				'subjects' => ['wi'],
				'groups' => ['3a'],
				'teachers' => ['JAN'],
				'locations' => ['A1.12'],
				'cancelled' => false,
			],
			[
				'appointmentInstance' => 881002,
				'start' => 1790582400,
				'end' => 1790585400,
				'subjects' => ['ne'],
				'groups' => ['3a'],
				'teachers' => ['PIE'],
				'locations' => ['B2.04'],
				'cancelled' => true,
			],
		],
		'roster-untis-oneroster' => [
			[
				'id' => 5501,
				'startDateTime' => '2026-09-28T09:00:00+02:00',
				'endDateTime' => '2026-09-28T09:50:00+02:00',
				'klasseId' => '4B',
				'faechId' => 'BIO',
				'lehrerId' => 'KLA',
				'raumId' => 'C003',
				'code' => '',
			],
			[
				'id' => 5502,
				'startDateTime' => '2026-09-28T10:00:00+02:00',
				'endDateTime' => '2026-09-28T10:50:00+02:00',
				'klasseId' => '4B',
				'faechId' => 'EN',
				'lehrerId' => 'SMI',
				'raumId' => 'C004',
				'code' => 'cancelled',
			],
		],
		'roster-xedule' => [
			[
				'eventId' => 'xe-7001',
				'startMoment' => '2026-09-28T09:00:00+02:00',
				'endMoment' => '2026-09-28T10:30:00+02:00',
				'groupCode' => 'ICT-2A',
				'activityName' => 'Programmeren',
				'teacherCode' => 'JDV',
				'locationName' => 'Lokaal 2.14',
				'status' => 'planned',
			],
			[
				'eventId' => 'xe-7002',
				'startMoment' => '2026-09-28T11:00:00+02:00',
				'endMoment' => '2026-09-28T12:30:00+02:00',
				'groupCode' => 'ICT-2A',
				'activityName' => 'Databases',
				'teacherCode' => 'MBR',
				'locationName' => 'Lokaal 2.16',
				'status' => 'cancelled',
			],
		],
		'roster-timeedit' => [
			[
				'activityId' => 'te-9001',
				'beginTime' => '2026-09-28T09:00:00+02:00',
				'endTime' => '2026-09-28T10:45:00+02:00',
				'resourceGroup' => 'BK-1',
				'activityTitle' => 'Bedrijfskunde hoorcollege',
				'staffId' => 's1001',
				'roomName' => 'Aula',
				'cancelled' => false,
			],
			[
				'activityId' => 'te-9002',
				'beginTime' => '2026-09-28T13:00:00+02:00',
				'endTime' => '2026-09-28T14:45:00+02:00',
				'resourceGroup' => 'BK-1',
				'activityTitle' => 'Werkcollege statistiek',
				'staffId' => 's1002',
				'roomName' => 'Zaal 1.02',
				'cancelled' => true,
			],
		],
	];

	/**
	 * Flavour identifier.
	 *
	 * @inheritDoc
	 *
	 * @return string
	 *
	 * @spec openspec/changes/integriq-adapter-rostering-imports/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()

	/**
	 * Dormant fetch — returns the canned batch for one source.
	 *
	 * @param string $systemId Rostering system Source row id.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
	 */
	public function fetchLessons(string $systemId): array {
		return (self::BATCHES[$systemId] ?? []);
	}//end fetchLessons()
}//end class
