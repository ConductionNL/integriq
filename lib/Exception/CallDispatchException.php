<?php

/**
 * Integriq Call Dispatch Exception.
 *
 * Raised when an outbound call could not be made at all: the target is
 * unknown, or the transport refused before a response existed. A call that
 * reached a receiver and came back with an error is not this; that is a
 * recorded call with a status. Messages MUST stay secret-free.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when an outbound call cannot be dispatched.
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */
class CallDispatchException extends Exception {
}//end class
