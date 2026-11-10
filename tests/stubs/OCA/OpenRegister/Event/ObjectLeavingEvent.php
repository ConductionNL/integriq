<?php

/*
 * Copy of openregister lib/Event/ObjectLeavingEvent.php (development, 7 Nov 2026).
 * Loaded by tests/bootstrap.php only when OpenRegister is not installed. Its
 * @spec tags are dropped: they name OpenRegister specs.
 */

/**
 * OpenRegister ObjectLeavingEvent
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * Event dispatched before an object moves out of its register and schema.
 */
class ObjectLeavingEvent extends ObjectDeletingEvent {
}//end class
