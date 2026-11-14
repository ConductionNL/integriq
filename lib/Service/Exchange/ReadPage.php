<?php

/**
 * Integriq exchange read page.
 *
 * Kept beside ExchangeReadModel so a bounded read takes its filters and one
 * page instead of the page size, offset and cap one by one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

/**
 * One page of a bounded read: the size asked for, the offset, and the largest size allowed.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
 */
final class ReadPage {

	/**
	 * The largest page allowed.
	 *
	 * @var int
	 */
	public int $cap = ExchangeReadModel::MAX_LIMIT;

	/**
	 * Constructor.
	 *
	 * @param int $limit  The page size asked for.
	 * @param int $offset The page offset asked for.
	 */
	public function __construct(
		public readonly int $limit,
		public readonly int $offset,
	) {

	}//end __construct()
}//end class
