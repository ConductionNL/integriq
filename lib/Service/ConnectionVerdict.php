<?php

/**
 * Integriq ConnectionVerdict.
 *
 * The status and message one D4 rule gives a connection row, and the time it
 * counts as checked at. {@see ConnectionStatusResolver} builds one per matched
 * rule and turns it into the outcome array.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * A resolved connection status.
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */
final class ConnectionVerdict {

	/**
	 * When the status counts as checked; null when it was never checked.
	 *
	 * @var string|null
	 */
	public ?string $checkedAt = null;

	/**
	 * Constructor.
	 *
	 * @param string $status The status, one of ConnectionStatusResolver::STATUSES.
	 * @param string $message The status message.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $message,
	) {
	}//end __construct()
}//end class
