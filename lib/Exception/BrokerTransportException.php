<?php

/**
 * Integriq BrokerTransport Exception.
 *
 * Raised when no transport answers to a broker id. The message names the id,
 * because a failure that does not say which broker was asked for is a failure
 * nobody can configure away. Messages MUST stay secret-free.
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
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when no broker transport answers to a broker id.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md
 */
class BrokerTransportException extends Exception {
}//end class
