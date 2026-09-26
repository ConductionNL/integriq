<?php

/**
 * Integriq UWLR/Edu-V Provider Exception.
 *
 * Raised when a UWLR, Edu-V, Basispoort or Entree-content transport
 * operation cannot proceed: no active source configured, an unreachable/
 * erroring endpoint, a malformed response, or a missing/unresolvable
 * PKIoverheid certificate reference. Messages MUST stay secret-free
 * (mirrors OsoProviderException).
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown on any UWLR/Edu-V/Basispoort/Entree-content provider/transport or
 * configuration failure.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md
 */
class UwlrEduVProviderException extends Exception {
}//end class
