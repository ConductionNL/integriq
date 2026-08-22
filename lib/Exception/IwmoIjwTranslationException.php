<?php

/**
 * Integriq iWMO/iJW Translation Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\IwmoIjw\OutboundMessageTranslator}
 * and {@see \OCA\Integriq\Service\IwmoIjw\InboundReturnTranslator} when a
 * required field is missing/empty, or when a rendered envelope still
 * contains an unresolved template marker — the literal-leak guard. This
 * exception type MUST NEVER be swallowed into a passed-through envelope:
 * raising it is the ONLY way a translator refuses to emit XML for
 * incomplete data (see design.md "Literal-leak guard").
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
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a translator cannot produce a complete, leak-free envelope or
 * status update.
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */
class IwmoIjwTranslationException extends Exception {
}//end class
