<?php

/**
 * OpenConnector Tables Permission-Denied Exception.
 *
 * Raised when the Nextcloud Tables API returns 401/403 for a row write
 * (create/update/delete). Per `tables-bridge` REQ-006 / design.md Decision 8,
 * OpenConnector never pre-checks or re-implements Tables' own authorization —
 * Tables' response is the sole authority. This exception is deliberately
 * left UNCAUGHT by `TablesSyncAdapter`/`SynchronizationService` so it
 * propagates out of the per-object processing loop and aborts the rest of
 * the run (no partial writes), matching every other hard failure already
 * propagated from that loop (GuzzleException, LoaderError, etc.).
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
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-permission-denied-writes-fail-the-run-not-a-partial-subset-of-rows-req-006
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on a 401/403 from the Tables API during a row write; aborts the run.
 *
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-permission-denied-writes-fail-the-run-not-a-partial-subset-of-rows-req-006
 */
class TablesPermissionDeniedException extends Exception
{
    /**
     * Constructor.
     *
     * @param string $message    Human-readable message naming the table and identity.
     * @param int    $statusCode The upstream status code (401 or 403).
     */
    public function __construct(string $message, int $statusCode=403)
    {
        parent::__construct(message: $message, code: $statusCode);

    }//end __construct()
}//end class
