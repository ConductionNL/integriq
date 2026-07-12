<?php

/**
 * OpenConnector PSD2 Provider Exception.
 *
 * Raised when a PSD2 AIS aggregator provider operation cannot proceed: an
 * unknown or unusable source, an unreachable/erroring aggregator, a `rest`
 * provider whose `credentialRef` cannot be brokered, or a provider
 * configuration error. Per REQ-006 / ADR-007 messages MUST stay secret-free —
 * they may name configuration keys and references, never token material.
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
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any PSD2 AIS aggregator provider or configuration failure.
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md
 */
class Psd2ProviderException extends Exception
{
}//end class
