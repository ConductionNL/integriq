<?php

/**
 * Integriq Message Validation Refused Exception.
 *
 * Raised when a synchronization in mode `refuse` meets a source object or a
 * target body that does not match its message schema. The engine's per-item
 * isolation catches it and dead-letters the item with this message, so one
 * bad object never stops the run.
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
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;

/**
 * Thrown when a synchronization refuses a message that does not match its message schema.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
 */
class MessageValidationRefusedException extends RuntimeException {
}//end class
