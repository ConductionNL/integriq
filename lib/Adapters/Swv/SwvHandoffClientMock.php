<?php

/**
 * Integriq SWV hand-off client (mock).
 *
 * Deterministic, no-network implementation of
 * {@see SwvHandoffClient}. Ships dormant — DI returns this class
 * until `swv.handoff.feature_flag` is set to `1`. Matches
 * `tests/fixtures/swv/fixture-swv-dossier.json` so downstream
 * mapping code can be developed and tested against a stable surface
 * without contacting Kindkans or LDOS.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Swv
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
 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Swv;

/**
 * Mock SWV hand-off client — dormant default.
 *
 * AVG-safe by construction: the dossier is accepted but never echoed
 * back nor logged — `handOff()` returns a synthetic acknowledgement
 * only.
 *
 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
 */
final class SwvHandoffClientMock extends SwvHandoffClient {
	/**
	 * Flavour identifier.
	 *
	 * @inheritDoc
	 *
	 * @return string
	 *
	 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()

	/**
	 * Dormant hand-off — returns a synthetic acknowledgement.
	 *
	 * @param string $receiverId Receiver Source row id (ignored by
	 *                           the mock; a live binding would use it
	 *                           to select the right OSO aansluiting).
	 * @param array<string,mixed> $dossier The dossier (ignored — never
	 *                                     echoed back nor logged).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001
	 */
	public function handOff(string $receiverId, array $dossier): array {
		unset($receiverId, $dossier);

		return [
			'referenceId' => 'swv-mock-' . bin2hex(random_bytes(8)),
			'acceptedStatus' => 'received',
			'receivedAt' => gmdate('c'),
			'flavour' => 'mock',
		];
	}//end handOff()
}//end class
