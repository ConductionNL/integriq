<?php

/**
 * Contract of the repair that blanks stored export placeholders.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
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
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * Reports, and on apply blanks, stored placeholder secrets in sources and rules.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
interface PlaceholderSecretRepairInterface {
	/**
	 * Scan every source and rule; blank the placeholder values only when $apply is true.
	 *
	 * @param boolean $apply Write the blanked objects back.
	 * @param integer $limit Maximum number of objects per schema.
	 *
	 * @return array{objects: array<int, array<string, mixed>>, found: int, blanked: int, failed: int}
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function run(bool $apply, int $limit): array;
}
