<?php

/**
 * Integriq roster-import client (abstract base).
 *
 * Low-level abstract base for pulling timetable data from the four
 * VO/MBO/HE rostering systems market-intelligence round 1 found:
 * Zermelo (REST/JSON, token auth), Untis (WebUntis OneRoster API),
 * Xedule (REST API + the OAuth2 Xedule Connect layer) and TimeEdit
 * (REST API). Concrete subclasses:
 *
 *   - {@see RosterImportClientMock} — deterministic mock; default.
 *     Returns a canned lesson batch so downstream mapping (learniq's
 *     rostering-import job) can be developed and tested without ever
 *     contacting a scheduling system.
 *   - A live HTTP binding is intentionally NOT built in this change
 *     — see `openspec/changes/integriq-adapter-rostering-imports/proposal.md`
 *     "Out of Scope". Each system requires its own institution-level
 *     OAuth/API-key onboarding this change cannot complete.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Roster;

/**
 * Abstract roster-import client.
 *
 * Subclasses MUST implement `fetchLessons()` plus a `flavour()`
 * self-identifier so the structured logger can record which binding
 * actually handled the call.
 *
 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
 */
abstract class RosterImportClient {
	/**
	 * Mock or live flavour identifier — used in structured logs so
	 * operators can verify which binding handled a call.
	 *
	 * @return string `mock` or `https`.
	 *
	 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
	 */
	abstract public function flavour(): string;

	/**
	 * Fetch a batch of lessons for one rostering system.
	 *
	 * @param string $systemId One of `roster-zermelo`,
	 *                         `roster-untis-oneroster`, `roster-xedule`,
	 *                         `roster-timeedit` (the Source row id, see
	 *                         `lib/sources.seed.json`).
	 *
	 * @return array<int,array<string,mixed>> Lesson records — each
	 *                                        carrying `subject`,
	 *                                        `startsAt`, `endsAt`,
	 *                                        `room`, `teacherReference`,
	 *                                        `groupReference`.
	 *
	 * @spec openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001
	 */
	abstract public function fetchLessons(string $systemId): array;
}//end class
