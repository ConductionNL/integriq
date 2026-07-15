<?php

/**
 * OpenConnector Tables Not-Found Exception.
 *
 * Raised when the configured `Source`, table id, or column referenced by a
 * `nextcloud-table` operation does not exist (either not found locally, or
 * the Tables API itself returned a 404). Discovery-endpoint callers map this
 * to HTTP 404 (contract.md).
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
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown when a Source, table, or column referenced by a Tables operation is not found.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */
class TablesNotFoundException extends Exception
{
    /**
     * Constructor.
     *
     * @param string $message Human-readable, secret-free message.
     */
    public function __construct(string $message)
    {
        parent::__construct(message: $message, code: 404);

    }//end __construct()
}//end class
