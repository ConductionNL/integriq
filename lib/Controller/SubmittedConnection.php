<?php

/**
 * SubmittedConnection
 *
 * What an administrator submitted for an intake connection: the signature
 * trust and the account the intake acts as.
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
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

/**
 * The submitted trust configuration and account of one connection save.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-4
 */
final class SubmittedConnection {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $trust  The submitted trust configuration.
	 * @param string               $userId The chosen uid; empty clears the account.
	 */
	public function __construct(
		public readonly array $trust,
		public readonly string $userId,
	) {

	}//end __construct()
}//end class
