<?php

/**
 * OpenConnector Peppol Provider Exception.
 *
 * Raised when a Peppol Access Point provider operation cannot proceed: a
 * malformed participant identifier, an unreachable/erroring Access Point, a
 * `rest` provider whose `credentialRef` cannot be brokered, or a provider
 * configuration error. Per REQ-006 / ADR-007 messages MUST stay secret-free —
 * they may name configuration keys and references, never key material.
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
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any Peppol Access Point provider or configuration failure.
 *
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md
 */
class PeppolProviderException extends Exception
{
}//end class
