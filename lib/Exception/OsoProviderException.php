<?php

/**
 * Integriq OSO Provider Exception.
 *
 * Raised when an OSO (Overstapservice Onderwijs, Kennisnet) export
 * transport operation cannot proceed: no active OSO source configured, an
 * unreachable/erroring endpoint, a malformed response, or a missing/
 * unresolvable PKIoverheid certificate reference. Messages MUST stay
 * secret-free (mirrors RodProviderException).
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown on any OSO provider/transport or configuration failure.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 */
class OsoProviderException extends Exception {
}//end class
