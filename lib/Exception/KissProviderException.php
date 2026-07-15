<?php

/**
 * OpenConnector KISS Provider Exception.
 *
 * Raised when a KISS (Klantinteractie Servicesysteem) / VNG Klantinteracties
 * provider operation cannot proceed: no active KISS source configured, an
 * unreachable/erroring KISS instance, a malformed response, or a provider
 * configuration error. Messages MUST stay secret-free — they may name
 * configuration keys and references, never token material (mirrors
 * PeppolProviderException / SmsProviderException).
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
 * @spec openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any KISS / VNG Klantinteracties provider or configuration failure.
 *
 * @spec openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md
 */
class KissProviderException extends Exception
{
}//end class
