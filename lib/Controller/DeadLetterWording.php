<?php

/**
 * Integriq DeadLetterWording.
 *
 * The two sentences a dead-letter controller words its own way: the 404 for
 * an unknown id and the 400 for an ids parameter that is not a list.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

/**
 * The translated sentences of one dead-letter controller.
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011
 */
final class DeadLetterWording {

	/**
	 * Constructor.
	 *
	 * @param string $notFound    The translated 404 sentence for an unknown id.
	 * @param string $idsNotAList The translated 400 sentence for an ids parameter that is not a list.
	 */
	public function __construct(
		public readonly string $notFound,
		public readonly string $idsNotAList,
	) {
	}//end __construct()
}//end class
