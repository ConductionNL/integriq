<?php

/**
 * OpenConnector ZGW Unknown Resource Exception.
 *
 * Raised by {@see \OCA\OpenConnector\Service\ZgwVersionTranslationService}
 * when a caller declares a `resource` outside the 7 fleet ZGW resources
 * this change translates (`zaak`, `zaaktype`,
 * `enkelvoudiginformatieobject`, `besluit`, `rol`, `status`, `resultaat`).
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
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

/**
 * Thrown when a declared ZGW resource has no registered translator.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class ZgwUnknownResourceException extends ZgwVersionTranslationException
{
}//end class
