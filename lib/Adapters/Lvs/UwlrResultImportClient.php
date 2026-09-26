<?php

/**
 * Integriq UWLR result-import client (abstract base).
 *
 * Low-level abstract base for pulling normed toets results from the
 * four Dutch PO leerlingvolgsysteem (LVS) suites that exchange
 * results in a UWLR-shaped format: Cito (Leerling in Beeld, via
 * DULT-verwerking), IEP (Bureau ICE), Boom (Boom Testcentrum) and
 * Dia (Diataal). Concrete subclasses:
 *
 *   - {@see UwlrResultImportClientMock} — deterministic mock; default.
 *     Returns a canned, UWLR-shaped result batch so downstream
 *     mapping (learniq's `lvs-import-contract`) can be developed and
 *     tested without ever contacting a supplier.
 *   - A live HTTP/SFTP binding is intentionally NOT built in this
 *     change — see `openspec/changes/integriq-adapter-lvs-imports/proposal.md`
 *     "Out of Scope". Each supplier requires its own commercial
 *     koppelpartner onboarding that this change cannot complete.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Lvs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Lvs;

/**
 * Abstract UWLR result-import client.
 *
 * Subclasses MUST implement `fetchResults()` plus a `flavour()`
 * self-identifier so the structured logger can record which binding
 * actually handled the call.
 *
 * @spec openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001
 */
abstract class UwlrResultImportClient {
	/**
	 * Mock or live flavour identifier — used in structured logs so
	 * operators can verify which binding handled a call.
	 *
	 * @return string `mock` or `https`.
	 *
	 * @spec openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001
	 */
	abstract public function flavour(): string;

	/**
	 * Fetch a batch of UWLR-shaped toets results for one supplier.
	 *
	 * @param string $supplierId One of `lvs-cito-dult`, `lvs-iep`,
	 *                           `lvs-boom`, `lvs-dia` (the Source row
	 *                           id, see `lib/sources.seed.json`).
	 *
	 * @return array<int,array<string,mixed>> UWLR-shaped result
	 *                                        records — each carrying
	 *                                        `leerlingReference`,
	 *                                        `toetscode`,
	 *                                        `referentieniveau`,
	 *                                        `vaardigheidsscore`,
	 *                                        `afnamedatum`, `groep`.
	 *
	 * @spec openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001
	 */
	abstract public function fetchResults(string $supplierId): array;
}//end class
