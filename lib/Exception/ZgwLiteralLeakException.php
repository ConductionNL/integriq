<?php

/**
 * OpenConnector ZGW Literal-Leak Exception.
 *
 * Raised by a {@see \OCA\OpenConnector\Service\ZgwVersion\ZgwResourceTranslatorInterface}
 * implementation when a payload cannot be safely translated: a required
 * field is missing, an enum value falls outside the resource's documented
 * value set, or a field structurally required to be an array carries a
 * bare scalar instead. The name mirrors this fleet's established
 * "literal-leak" defect class — an unresolved/malformed value passing
 * through untranslated instead of failing loudly.
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
 * Thrown when a translator's literal-leak guard rejects a payload.
 *
 * @spec openspec/specs/zgw-version-translation/spec.md#requirement-per-resource-translator-seam-with-a-literal-leak-guard-req-001
 */
class ZgwLiteralLeakException extends ZgwVersionTranslationException
{
}//end class
