<?php

/**
 * OpenConnector Open Formulieren Exception.
 *
 * Raised on any Open Formulieren submission-ingest or handoff-trigger
 * failure: an unknown form slug, no active `open-formulieren` source
 * configured, or a handoff execution failure surfaced from OpenRegister.
 * Messages MUST stay secret-free and MUST NOT include raw BSN/KvK values
 * (mirrors `SmsProviderException` / ADR-007).
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
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown on any Open Formulieren ingest or handoff-trigger failure.
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */
class OpenFormulierenException extends Exception {
}//end class
