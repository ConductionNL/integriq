<?php

/**
 * Integriq DSO Translation Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\Dso\DsoRequestTranslator} when a
 * DSO Verzoek cannot be translated into the normalised handoff-ready fields
 * a `dso_verzoek` record carries — a missing `verzoekId` (the correlation
 * reference), or a mandatory contract field with no resolvable source data.
 * This exception type MUST NEVER be swallowed into a fabricated value: the
 * literal-leak guard (mirrors `open-formulieren-intake`'s `FormFieldMapper`
 * and `iwmo-ijw-adapter`'s translators) means an unresolvable field always
 * raises this, never a placeholder/blank string masquerading as data.
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
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a DSO Verzoek cannot produce a complete, leak-free
 * normalised mapping.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DsoTranslationException extends Exception {
}//end class
