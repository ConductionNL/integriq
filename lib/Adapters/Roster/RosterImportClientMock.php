<?php

/**
 * Integriq roster-import client (mock).
 *
 * Deterministic, no-network implementation of
 * {@see RosterImportClient}. Ships dormant — DI returns this class
 * until `roster.import.feature_flag` is set to `1`. The canned batch
 * matches `tests/fixtures/roster/fixture-roster-batch.json` so
 * downstream mapping code can be developed and tested against a
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
 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Roster;

/**
 * Mock roster-import client — dormant default.
 *
 * Returns the same two-lesson canned batch regardless of
 * `$systemId`, so all four seeded Source rows (`roster-zermelo`,
 * `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit`) can
 * be exercised identically in mock mode.
 *
 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
 */
final class RosterImportClientMock extends RosterImportClient {
	/**
	 * Flavour identifier.
	 *
	 * @inheritDoc
	 *
	 * @return string
	 *
	 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()

	/**
	 * Dormant fetch — returns a canned lesson batch.
	 *
	 * @param string $systemId Rostering system Source row id
	 *                         (ignored by the mock; a live binding
	 *                         would use it to select the right
	 *                         connection).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
	 */
	public function fetchLessons(string $systemId): array {
		unset($systemId);

		return [
			[
				'subject' => 'Wiskunde',
				'startsAt' => '2026-09-28T09:00:00+02:00',
				'endsAt' => '2026-09-28T09:50:00+02:00',
				'room' => 'A1.12',
				'teacherReference' => 'docent-mock-0001',
				'groupReference' => 'klas-mock-3a',
			],
			[
				'subject' => 'Nederlands',
				'startsAt' => '2026-09-28T10:00:00+02:00',
				'endsAt' => '2026-09-28T10:50:00+02:00',
				'room' => 'B2.04',
				'teacherReference' => 'docent-mock-0002',
				'groupReference' => 'klas-mock-3a',
			],
		];
	}//end fetchLessons()
}//end class
