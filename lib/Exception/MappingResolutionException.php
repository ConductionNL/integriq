<?php

/**
 * Integriq Mapping Resolution Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\OpenFormulieren\FormFieldMapper}
 * when a declared `from`/`template` mapping expression cannot resolve
 * against the submitted values. This is the deliberate opposite of the known
 * `oc-mapping-literal-leak` bug class (Integriq `sourceTargetMapping`
 * returning the literal dot-path string when a bare-path source key is
 * absent) — an unresolvable declared field MUST error, never silently carry
 * the literal expression through as if it were resolved data.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
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
 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a declared field mapping cannot resolve against submitted values.
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
 */
class MappingResolutionException extends Exception {
}//end class
