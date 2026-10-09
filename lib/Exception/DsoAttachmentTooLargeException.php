<?php

/**
 * Integriq DSO Attachment Too Large Exception.
 *
 * Raised when a DSO bijlage is larger than the source's `maxFileSize`, either
 * by its declared Content-Length or while its body is streamed. Retrying does
 * not help, so the download job records the entry as `too-large` and stops.
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
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-oversized-bijlage-rejected
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

/**
 * Thrown when a bijlage exceeds the configured maximum file size.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-oversized-bijlage-rejected
 */
class DsoAttachmentTooLargeException extends DsoProviderException {
}//end class
