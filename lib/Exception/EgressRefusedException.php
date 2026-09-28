<?php

/**
 * Integriq Egress Refused Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\Security\EgressGuard} when an outbound
 * URL may not be called: a scheme other than https, or a host that is, or
 * resolves to, a loopback, private, link-local, reserved or cloud metadata
 * address. The message names the rule and the host, never a credential or a
 * payload, so it is safe to return to the caller and to record on a delivery.
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
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Signals an outbound URL the egress guard refuses to call.
 *
 * @spec openspec/changes/events-async-api-products/design.md
 */
class EgressRefusedException extends Exception {
}//end class
