<?php

/**
 * Integriq IntakeChannel Exception.
 *
 * Raised when a channel cannot handle a payload: no adapter answers to the
 * channel id, or the payload is not the shape that channel delivers. The
 * message names the channel id, because a failure that does not say which
 * channel refused is a failure nobody can configure away. Messages MUST stay
 * secret-free.
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
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when an intake channel cannot handle a payload.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */
class IntakeChannelException extends Exception {
}//end class
