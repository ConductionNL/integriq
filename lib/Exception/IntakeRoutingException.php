<?php

/**
 * Integriq IntakeRouting Exception.
 *
 * Raised when a routing rule or a submission mapping cannot be accepted: it
 * names a channel nothing answers to, or a target field the case type does
 * not have. It is thrown at configuration time, in front of the person who
 * wrote the rule, rather than at every submission afterwards.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when a routing rule or submission mapping cannot be accepted.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */
class IntakeRoutingException extends Exception {
}//end class
