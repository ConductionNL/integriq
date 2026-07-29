<?php

/**
 * OpenConnector Forms Permission-Denied Exception.
 *
 * Raised when the Nextcloud Forms API returns 401/403 for a request. Per
 * `tables-bridge` Decision 8's precedent (mirrored here — design.md Decision
 * 1), OpenConnector never pre-checks or re-implements Forms' own
 * authorization — Forms' response is the sole authority.
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
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on a 401/403 from the Forms API.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
 */
class FormsPermissionDeniedException extends Exception
{
    /**
     * Constructor.
     *
     * @param string $message    Human-readable message naming the form/submission and identity.
     * @param int    $statusCode The upstream status code (401 or 403).
     */
    public function __construct(string $message, int $statusCode=403)
    {
        parent::__construct(message: $message, code: $statusCode);

    }//end __construct()
}//end class
