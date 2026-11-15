<?php

/**
 * Integriq DriveSearchQuery.
 *
 * One Graph drive search: the terms, the optional modified-date window, the
 * hit limit and the search region.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\Saas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\Saas;

/**
 * Value object for one drive search (ADR-011).
 *
 * @spec openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008
 */
final class DriveSearchQuery {

	/**
	 * Constructor.
	 *
	 * @param string      $terms  The terms.
	 * @param string|null $from   Start, Y-m-d.
	 * @param string|null $to     End, Y-m-d.
	 * @param int         $limit  At most this many hits.
	 * @param string      $region The Graph search region an application grant needs.
	 */
	public function __construct(
		public readonly string $terms,
		public readonly ?string $from,
		public readonly ?string $to,
		public readonly int $limit,
		public readonly string $region,
	) {
	}//end __construct()
}//end class
