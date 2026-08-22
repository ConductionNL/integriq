<?php

/**
 * Integriq Invalid Message State Exception.
 *
 * Raised when a dead-letter verb (replay / discard) is attempted on an
 * event_message whose current status does not permit the transition. The
 * controller maps it to HTTP 409 Conflict.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-2
 */

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a replay/discard is attempted on a message in a non-eligible state.
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-2
 */
class InvalidMessageStateException extends Exception {

}//end class
