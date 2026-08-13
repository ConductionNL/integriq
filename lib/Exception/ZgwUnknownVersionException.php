<?php

/**
 * OpenConnector ZGW Unknown Version Exception.
 *
 * Raised by {@see \OCA\OpenConnector\Service\ZgwVersionNegotiationService}
 * when a caller declares a `fromVersion`/`toVersion` that is neither `1.0`
 * (the fleet's current shape), `1.6` (the implemented stability line), nor
 * `2.0` (the recognised-but-unimplemented next-generation placeholder —
 * see {@see ZgwVersionNotImplementedException} for that case).
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
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-version-negotiation-with-passthrough-default-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

/**
 * Thrown when a declared ZGW version is not recognised at all.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-version-negotiation-with-passthrough-default-req-002
 */
class ZgwUnknownVersionException extends ZgwVersionTranslationException {
}//end class
