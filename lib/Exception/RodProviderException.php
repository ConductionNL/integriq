<?php

/**
 * Integriq DUO ROD Provider Exception.
 *
 * Raised when a DUO ROD (Register Onderwijsdeelnemers) transport operation
 * cannot proceed: no active ROD source configured, an unreachable/erroring
 * Edukoppeling endpoint, a malformed response, or a missing/unresolvable
 * PKIoverheid certificate reference. Messages MUST stay secret-free — they
 * may name configuration keys and references, never certificate/key
 * material (mirrors IwmoIjwProviderException / DigitalPost's provider
 * exceptions).
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown on any ROD provider/transport or configuration failure.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md
 */
class RodProviderException extends Exception {
}//end class
