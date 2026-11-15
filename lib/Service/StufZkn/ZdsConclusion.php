<?php

/**
 * Integriq StUF-ZDS conclusion.
 *
 * How one StUF-ZDS message attempt ended, as its row and its result record
 * it: whether the case system confirmed it, which attempt it was, the
 * transport result, the fout on failure, the zaak identificatie when one is
 * known, and whether a retry is scheduled.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * The outcome of one StUF-ZDS message attempt.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */
final class ZdsConclusion {

	/**
	 * Constructor.
	 *
	 * @param bool        $sent      Whether the case system confirmed it.
	 * @param int         $attempt   The attempt (1-indexed).
	 * @param array       $transport The transport result.
	 * @param array|null  $fout      The fout on failure.
	 * @param string|null $caseId    The zaak identificatie, if known.
	 * @param bool        $retrying  Whether a retry is scheduled.
	 */
	public function __construct(
		public readonly bool $sent,
		public readonly int $attempt,
		public readonly array $transport,
		public readonly ?array $fout,
		public readonly ?string $caseId,
		public readonly bool $retrying=false,
	) {

	}//end __construct()
}//end class
