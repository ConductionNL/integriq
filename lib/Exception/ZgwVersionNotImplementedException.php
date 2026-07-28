<?php

/**
 * OpenConnector ZGW Version Not Implemented Exception.
 *
 * Raised by {@see \OCA\OpenConnector\Service\ZgwVersionNegotiationService}
 * when a caller declares the `"2.0"` next-generation ZGW placeholder
 * version — a version this shim RECOGNISES (it is not an unknown value,
 * see {@see ZgwUnknownVersionException}) but does not yet translate,
 * because no stable next-generation OAS exists to translate against (see
 * design.md "Open Questions" #1). Distinguishing "known but unimplemented"
 * from "unknown" lets a caller tell the difference between a typo and a
 * genuinely unsupported-yet target.
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
 * Thrown when a recognised-but-unimplemented ZGW version is targeted.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-version-negotiation-with-passthrough-default-req-002
 */
class ZgwVersionNotImplementedException extends ZgwVersionTranslationException
{
}//end class
