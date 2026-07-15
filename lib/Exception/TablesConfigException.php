<?php

/**
 * OpenConnector Tables Configuration Exception.
 *
 * Raised for hard configuration errors on a `nextcloud-table` source/target
 * that are caller/request-scoped (bad `sourceId`/`tableId`, ambiguous
 * column-title resolution passed up from `TablesSyncAdapter`) rather than a
 * per-row data problem. Discovery-endpoint callers map this to HTTP 400.
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
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on a bad request-shaped `nextcloud-table` configuration.
 *
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */
class TablesConfigException extends Exception
{
    /**
     * Constructor.
     *
     * @param string $message    Human-readable, secret-free message.
     * @param int    $statusCode The status code callers should map this to (default 400).
     */
    public function __construct(string $message, int $statusCode=400)
    {
        parent::__construct(message: $message, code: $statusCode);

    }//end __construct()
}//end class
