<?php

/**
 * Integriq UWLR result-import client (live).
 *
 * Reads a UWLR result batch from the pupil tracking system through the
 * supplier's own source row (`lvs-cito-dult`, `lvs-iep`, `lvs-boom`,
 * `lvs-dia`), so its address and brokered credential come from
 * configuration. Bound only when `lvs.import.feature_flag` is on. A refused
 * credential throws, so no result is written, and CallService's call log
 * records the refusal on the source.
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
 *
 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Lvs;

use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Adapters\Support\SupplierSourceCall;

/**
 * Live UWLR results, in the UWLR field names the source adapter maps.
 *
 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
 */
final class UwlrResultImportClientHttp extends UwlrResultImportClient {

	/**
	 * The results endpoint below the supplier's address.
	 *
	 * @var string
	 */
	public const ENDPOINT = '/uwlr/results';

	/**
	 * Constructor.
	 *
	 * @param SupplierSourceCall $calls Calls the supplier's source.
	 */
	public function __construct(
		private readonly SupplierSourceCall $calls,
	) {
	}//end __construct()

	/**
	 * The transport flavour.
	 *
	 * @return string Always `https`.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
	 */
	public function flavour(): string {
		return 'https';
	}//end flavour()

	/**
	 * The result records of the supplier's batch.
	 *
	 * The batch is `{supplierId, results: [...]}`; the supplier id in the
	 * batch must be the one asked for, so a misrouted batch is never filed
	 * under another supplier.
	 *
	 * @param string $supplierId The supplier source slug.
	 *
	 * @return array<int,array<string,mixed>> UWLR-shaped result records.
	 *
	 * @throws SupplierCallException When the supplier refuses or fails the call, or answers another supplier's batch.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
	 */
	public function fetchResults(string $supplierId): array {
		$batch = $this->calls->json(slug: $supplierId, endpoint: self::ENDPOINT, withholdBodies: true);

		$answered = (string)($batch['supplierId'] ?? $supplierId);
		if ($answered !== $supplierId) {
			throw new SupplierCallException(
				message: sprintf('%s answered a batch for %s; nothing is imported.', $supplierId, $answered)
			);
		}

		$results = ($batch['results'] ?? null);
		if (is_array($results) === false) {
			throw new SupplierCallException(message: sprintf('%s answered a batch without results.', $supplierId));
		}

		return array_values(array_filter($results, 'is_array'));
	}//end fetchResults()
}//end class
