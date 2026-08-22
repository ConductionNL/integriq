<?php

/**
 * Integriq Cardfeed Provider Exception.
 *
 * Raised when a corporate-card-feed provider operation cannot proceed: an
 * unknown or unusable source, an unreachable/erroring card provider, a `rest`
 * provider whose `credentialRef` cannot be brokered, or a provider
 * configuration error. Per REQ-005 / ADR-007 messages MUST stay secret-free —
 * they may name configuration keys and references, never API-key material.
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
 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-card-provider-credentials-brokered-never-plaintext-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown on any corporate-card-feed provider or configuration failure.
 *
 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-card-provider-credentials-brokered-never-plaintext-req-005
 */
class CardfeedProviderException extends Exception {
}//end class
