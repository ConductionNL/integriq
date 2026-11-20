<?php

/**
 * Contract of the ObjectRightsService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Writes the matrix's object rights onto the configuration schemas.
 */
interface ObjectRightsServiceInterface {
	/**
	 * Apply the `object.<schema>.<verb>` entries of an action matrix to the
	 * OpenRegister `authorization` block of each configuration schema.
	 *
	 * @param array<string, array<int, string>> $matrix The stored action matrix.
	 *
	 * @return array<int, string> The slugs of the schemas whose update did not go through.
	 *
	 * @spec openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
	 */
	public function apply(array $matrix): array;
}//end interface
