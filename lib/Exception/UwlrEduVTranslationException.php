<?php

/**
 * Integriq UWLR/Edu-V Translation Exception.
 *
 * Raised by UwlrExportEnvelopeTranslator, EduVExportEnvelopeTranslator,
 * BasispoortSyncTranslator, EntreeContentSyncTranslator and
 * UwlrEduVAcknowledgementTranslator when a required field is missing/
 * empty, an unknown subtype/data service is named, or a rendered envelope
 * still contains an unresolved template marker — the literal-leak guard
 * (mirrors OsoTranslationException).
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a translator cannot produce a complete, leak-free envelope
 * or acknowledgement event.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
 */
class UwlrEduVTranslationException extends Exception {
}//end class
