<?php

/**
 * Integriq DsoAlertNotice.
 *
 * One connection alert as it is sent to every administrator: the reason, the
 * sanitised channel and the send time.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

/**
 * Value object for one connection alert (ADR-011).
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
 */
final class DsoAlertNotice {

	/**
	 * Constructor.
	 *
	 * @param string $reason  The reason as given.
	 * @param string $channel The channel, lowercase letters only.
	 * @param int    $now     The send time.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function __construct(
		public readonly string $reason,
		public readonly string $channel,
		public readonly int $now,
	) {

	}//end __construct()
}//end class
