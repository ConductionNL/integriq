<?php

/**
 * Integriq AccountRightsCheck.
 *
 * One rights check of a connection's account: OpenRegister's permission
 * handler, the resolved schema and the uid the check runs for.
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
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

/**
 * Value object for one account rights check (ADR-011).
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
 */
final class AccountRightsCheck {

	/**
	 * Constructor.
	 *
	 * @param object $handler The PermissionHandler, known to have hasPermission().
	 * @param object $schema  The resolved schema entity.
	 * @param string $userId  The uid to check.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	public function __construct(
		public readonly object $handler,
		public readonly object $schema,
		public readonly string $userId,
	) {

	}//end __construct()
}//end class
