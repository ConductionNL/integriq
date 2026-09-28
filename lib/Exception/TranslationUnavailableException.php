<?php

/**
 * Integriq Translation Unavailable Exception.
 *
 * Raised when no translation source is enabled, or the enabled one answers
 * without a translation. The caller (decidiq's LogTranslationAdapter) catches
 * it and keeps its own fallback, so an unconfigured instance never reports the
 * original text as a translation.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/translation-service/spec.md#requirement-an-unconfigured-instance-says-so-req-trl-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a text cannot be translated through a configured source.
 *
 * @spec openspec/specs/translation-service/spec.md#requirement-an-unconfigured-instance-says-so-req-trl-002
 */
class TranslationUnavailableException extends Exception {
}//end class
