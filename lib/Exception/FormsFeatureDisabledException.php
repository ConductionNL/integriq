<?php

/**
 * OpenConnector Forms Feature-Disabled Exception.
 *
 * Raised when a `nextcloud-form` source/discovery/outbound-mapping operation
 * is attempted while the Nextcloud Forms app is not installed/enabled for
 * the acting user. Feature detection is via `IAppManager` only (never a
 * direct `OCA\Forms\*` reference) per `nextcloud-forms-connector` REQ-001;
 * this exception is the single signal both the synchronization engine
 * (run-abort), the discovery controller (409 response), and the outbound
 * `EventService::dispatchMappingAction()` (config-error, non-retryable) key
 * off, mirroring `TablesFeatureDisabledException` exactly.
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
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown when the Forms app is absent/disabled for a `nextcloud-form` operation.
 *
 * Callers map this to a 409-class response (`getCode()` is pre-set to 409).
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001
 */
class FormsFeatureDisabledException extends Exception
{
    /**
     * Constructor.
     *
     * @param string $message Human-readable message naming the missing dependency.
     */
    public function __construct(string $message='The Forms app is not enabled')
    {
        parent::__construct(message: $message, code: 409);

    }//end __construct()
}//end class
