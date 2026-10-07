<?php

/**
 * Integriq — one Berichtenbox batch.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
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

namespace OCA\Integriq\Adapters\Berichtenbox;

/**
 * One GLOBE-R-BV-Request batch holding one letter, built and checked against the official XSD.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010
 */
final class BerichtenboxBatch {
	/**
	 * Constructor.
	 *
	 * @param string $xml The batch document.
	 * @param string $batchId The BatchID GUID.
	 * @param string $berichtId The BerichtID GUID of the one letter in it.
	 */
	public function __construct(
		public readonly string $xml,
		public readonly string $batchId,
		public readonly string $berichtId,
	) {
	}//end __construct()
}//end class
