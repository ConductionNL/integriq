<?php

/**
 * OpenConnector StUF-ZKN Provider Exception.
 *
 * Raised when a StUF-ZKN transport operation cannot proceed: no active
 * `type=stuf-zkn` source configured, an unreachable/erroring StUF consumer
 * endpoint, a malformed `Bv03`/`Fo03` response, or a provider configuration
 * error. Messages MUST stay secret-free (mirrors
 * `IwmoIjwProviderException`/`DsoProviderException`).
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any StUF-ZKN provider/transport or configuration failure.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md
 */
class StufZknProviderException extends Exception {
}//end class
