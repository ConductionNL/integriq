<?php

/**
 * Integriq OSO Translation Exception.
 *
 * Raised by OsoExportEnvelopeTranslator, OsoImportTranslator and
 * OsoAcknowledgementTranslator when a required field is missing/empty, or
 * a rendered envelope still contains an unresolved template marker — the
 * literal-leak guard (mirrors RodTranslationException).
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-002-export-envelope-translation-with-a-literal-leak-guard
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a translator cannot produce a complete, leak-free envelope,
 * import event, or acknowledgement event.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-002-export-envelope-translation-with-a-literal-leak-guard
 */
class OsoTranslationException extends Exception {
}//end class
