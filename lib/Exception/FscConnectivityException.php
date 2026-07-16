<?php

/**
 * OpenConnector FSC Connectivity Exception.
 *
 * Raised when an FSC (Federatieve Service Connectiviteit) transport
 * operation cannot proceed: no active FSC source configured, an
 * unreachable/erroring directory or outway-fronted endpoint, a malformed
 * response, or a provider configuration error. Messages MUST stay
 * secret-free — they may name configuration keys, organisations, and
 * service ids, never token material (mirrors IwmoIjwProviderException /
 * KissProviderException / PeppolProviderException).
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
 * @spec openspec/specs/fsc-connectivity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any FSC provider/transport or configuration failure.
 *
 * @spec openspec/specs/fsc-connectivity/spec.md
 */
class FscConnectivityException extends Exception
{
}//end class
