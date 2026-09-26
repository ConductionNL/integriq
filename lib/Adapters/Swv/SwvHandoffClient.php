<?php

/**
 * Integriq SWV hand-off client (abstract base).
 *
 * Low-level abstract base for handing off a support-request/TLV
 * dossier to a samenwerkingsverband (SWV) receiver, shaped after
 * Kindkans and LDOS — both receive their referral dossiers via the
 * OSO SWV protocol (care-swv/round1/sources.md). Concrete subclasses:
 *
 *   - {@see SwvHandoffClientMock} — deterministic mock; default.
 *     Returns a canned acknowledgement so downstream code can be
 *     developed and tested without ever contacting a receiver.
 *   - A live OSO-shaped binding is intentionally NOT built in this
 *     change — see `openspec/changes/integriq-adapter-swv/proposal.md`
 *     "Out of Scope". Both receivers require the same governance
 *     chain (Privacyconvenant verwerkersovereenkomst, per-SWV
 *     aansluiting) this change cannot complete.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Swv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Swv;

/**
 * Abstract SWV hand-off client.
 *
 * Subclasses MUST implement `handOff()` plus a `flavour()`
 * self-identifier so the structured logger can record which binding
 * actually handled the call.
 *
 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
 */
abstract class SwvHandoffClient {
	/**
	 * Mock or live flavour identifier — used in structured logs so
	 * operators can verify which binding handled a call.
	 *
	 * @return string `mock` or `https`.
	 *
	 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
	 */
	abstract public function flavour(): string;

	/**
	 * Hand off one already-composed SWV dossier to a receiver.
	 *
	 * @param string $receiverId One of `swv-kindkans`, `swv-ldos`
	 *                           (the Source row id, see
	 *                           `lib/sources.seed.json`).
	 * @param array<string,mixed> $dossier The already-composed SWV
	 *                                     dossier (support-request +
	 *                                     TLV fields).
	 *
	 * @return array<string,mixed> Receiver acknowledgement —
	 *                             `referenceId`, `acceptedStatus`,
	 *                             `receivedAt`.
	 *
	 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
	 */
	abstract public function handOff(string $receiverId, array $dossier): array;
}//end class
