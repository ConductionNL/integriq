<?php

/**
 * Integriq DUO ROD Translation Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\Rod\RodEnvelopeTranslator} and
 * {@see \OCA\Integriq\Service\Rod\RodAcknowledgementTranslator} when a
 * required field is missing/empty, or when a rendered envelope still
 * contains an unresolved template marker — the literal-leak guard. This
 * exception type MUST NEVER be swallowed into a passed-through envelope:
 * raising it is the ONLY way a translator refuses to emit XML for
 * incomplete data (mirrors IwmoIjwTranslationException).
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a translator cannot produce a complete, leak-free envelope or
 * acknowledgement event.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */
class RodTranslationException extends Exception {
}//end class
