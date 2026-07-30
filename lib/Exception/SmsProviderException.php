<?php

/**
 * OpenConnector SMS Provider Exception.
 *
 * Raised when an SMS channel provider operation cannot proceed: an invalid
 * E.164 recipient, an unreachable/erroring gateway, a missing/undecryptable
 * credential, or a provider configuration error. Messages MUST stay
 * secret-free — they may name configuration keys and references, never key
 * material (mirrors PeppolProviderException / ADR-007).
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any SMS channel provider or configuration failure.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */
class SmsProviderException extends Exception
{
}//end class
