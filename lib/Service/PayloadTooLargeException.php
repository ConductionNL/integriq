<?php

/**
 * Integriq Payload Too Large Exception.
 *
 * Raised when a downloaded body is larger than the allowed maximum, either by
 * its declared Content-Length or while it is streamed. Retrying does not help,
 * so the caller records the entry as `too-large` and stops.
 *
 * @category Exception
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-oversized-bijlage-rejected
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\DsoProviderException;

/**
 * Thrown when a downloaded body exceeds the configured maximum size.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-oversized-bijlage-rejected
 */
class PayloadTooLargeException extends DsoProviderException {
}//end class
