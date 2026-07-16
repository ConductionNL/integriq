<?php

/**
 * OpenConnector ZGW Version Translation Exception.
 *
 * Base exception for the zgw-version-translation change: raised for any
 * translation failure that is not more specifically an unknown-resource,
 * unknown-version, not-implemented-version, or literal-leak failure.
 * Messages MUST stay secret-free (no payload content beyond field names).
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
 * @spec openspec/specs/zgw-version-translation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Base exception for the ZGW version-translation shim.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md
 */
class ZgwVersionTranslationException extends Exception
{
}//end class
