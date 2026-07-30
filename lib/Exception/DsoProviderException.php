<?php

/**
 * OpenConnector DSO Provider Exception.
 *
 * Raised when a DSO (Digitaal Stelsel Omgevingswet) outbound transport
 * operation cannot proceed: no active `type=dso` source configured, an
 * unreachable/erroring DSO-LV endpoint, a malformed response, or a provider
 * configuration error. Messages MUST stay secret-free — they may name
 * configuration keys and references, never token/certificate material
 * (mirrors IwmoIjwProviderException / KissProviderException /
 * FscConnectivityException).
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
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any DSO provider/transport or configuration failure.
 *
 * @spec openspec/changes/dso-connector-adapter/specs/dso-connector-adapter/spec.md
 */
class DsoProviderException extends Exception
{
}//end class
