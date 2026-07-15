<?php

/**
 * OpenConnector Tables Feature-Disabled Exception.
 *
 * Raised when a `nextcloud-table` source/target/discovery operation is
 * attempted while the Nextcloud Tables app is not installed/enabled for the
 * acting user. Feature detection is via `IAppManager` only (never a direct
 * `OCA\Tables\*` reference) per `tables-bridge` REQ-004; this exception is
 * the single signal both the synchronization engine (run-abort) and the
 * discovery controller (409 response) key off.
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
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown when the Tables app is absent/disabled for a `nextcloud-table` operation.
 *
 * Callers map this to a 409-class response (`getCode()` is pre-set to 409).
 *
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004
 */
class TablesFeatureDisabledException extends Exception
{
    /**
     * Constructor.
     *
     * @param string $message Human-readable message naming the missing dependency.
     */
    public function __construct(string $message='The Tables app is not enabled')
    {
        parent::__construct(message: $message, code: 409);

    }//end __construct()
}//end class
