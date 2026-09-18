<?php

/**
 * Integriq Mailbox Transport Exception.
 *
 * Raised when a mailbox cannot be read: the protocol binding is missing on
 * this instance, the credentials are refused, or the mail server answers an
 * error. Messages MUST stay secret-free: they may name the mailbox, the
 * folder and the reason, never credentials.
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
 * Thrown when a mailbox cannot be polled.
 *
 * @spec openspec/changes/mail-intake-creates-cases/specs/mail-intake/spec.md
 */
class MailboxTransportException extends Exception {
}//end class
