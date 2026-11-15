<?php

/**
 * TracePageWindow
 *
 * The page size and offset one execution-trace listing asked for.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

/**
 * A listing's `limit` and `offset`, as the route received them.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 */
final class TracePageWindow {

	/**
	 * Constructor.
	 *
	 * @param int|null $limit  Maximum number of results.
	 * @param int|null $offset Starting offset.
	 */
	public function __construct(
		public readonly ?int $limit,
		public readonly ?int $offset,
	) {

	}//end __construct()
}//end class
