<?php

/**
 * OpenConnector StUF-ZKN Translation Exception.
 *
 * Raised when a StUF-ZKN envelope (inbound `zakLk01`/`edcLk01`, or an
 * outbound kennisgeving translation) cannot be translated: a required
 * `stuurgegevens`/object field is missing or empty, an unrecognised
 * `entiteittype`/`verwerkingssoort`/berichtcode is encountered, the XML is
 * malformed, or the rendered envelope still carries an unresolved literal
 * marker. Messages MUST stay secret-free (mirrors
 * `IwmoIjwTranslationException`/`DsoTranslationException`).
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
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any StUF-ZKN inbound/outbound translation failure.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md
 */
class StufZknTranslationException extends Exception
{
}//end class
