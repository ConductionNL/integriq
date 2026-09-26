<?php

/**
 * Integriq UWLR result-import client (mock).
 *
 * Deterministic, no-network implementation of
 * {@see UwlrResultImportClient}. Ships dormant — DI returns this
 * class until `lvs.import.feature_flag` is set to `1`. The canned
 * batch matches `tests/fixtures/lvs/fixture-uwlr-result-batch.json`
 * so downstream mapping code can be developed and tested against a
 * stable, UWLR-shaped surface without contacting any supplier.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Lvs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Lvs;

/**
 * Mock UWLR result-import client — dormant default.
 *
 * Returns the same three-record canned batch regardless of
 * `$supplierId`, so all four seeded Source rows (`lvs-cito-dult`,
 * `lvs-iep`, `lvs-boom`, `lvs-dia`) can be exercised identically in
 * mock mode.
 *
 * @spec openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001
 */
final class UwlrResultImportClientMock extends UwlrResultImportClient {
	/**
	 * Flavour identifier.
	 *
	 * @inheritDoc
	 *
	 * @return string
	 *
	 * @spec openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001
	 */
	public function flavour(): string {
		return 'mock';
	}//end flavour()

	/**
	 * Dormant fetch — returns a canned, UWLR-shaped result batch.
	 *
	 * @param string $supplierId Supplier Source row id (ignored by
	 *                           the mock; a live binding would use
	 *                           it to select the right koppeling).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001
	 */
	public function fetchResults(string $supplierId): array {
		unset($supplierId);

		return [
			[
				'leerlingReference' => 'leerling-mock-0001',
				'toetscode' => 'BL-M6',
				'referentieniveau' => '1F',
				'vaardigheidsscore' => 78,
				'afnamedatum' => '2026-06-15',
				'groep' => '6',
			],
			[
				'leerlingReference' => 'leerling-mock-0002',
				'toetscode' => 'BL-M6',
				'referentieniveau' => '1S',
				'vaardigheidsscore' => 92,
				'afnamedatum' => '2026-06-15',
				'groep' => '6',
			],
			[
				'leerlingReference' => 'leerling-mock-0003',
				'toetscode' => 'RK-E5',
				'referentieniveau' => '1F',
				'vaardigheidsscore' => 65,
				'afnamedatum' => '2026-01-20',
				'groep' => '5',
			],
		];
	}//end fetchResults()
}//end class
