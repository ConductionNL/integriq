<?php

/**
 * Integriq Message Parse Exception.
 *
 * Raised when an uploaded or polled mail message cannot be read into the
 * canonical `message` shape. Intake catches this and keeps the raw file as a
 * single attachment with a warning, so a message is never dropped for being
 * unreadable. Messages MUST stay secret-free: they may name the file and the
 * reason, never credentials.
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
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a mail message cannot be parsed.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */
class MessageParseException extends Exception {
}//end class
