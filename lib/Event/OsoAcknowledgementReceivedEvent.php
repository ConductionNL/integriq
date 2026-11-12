<?php

/**
 * Integriq OsoAcknowledgementReceived Event.
 *
 * Dispatched whenever an OSO export acknowledgement/retour is received and
 * translated, mirroring RodAcknowledgementReceivedEvent /
 * VerzuimloketAcknowledgementReceivedEvent for the OSO export leg.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * An OSO export acknowledgement, translated and ready for a listener to act on.
 *
 * The kenmerk, signaalcode, description and acceptance live in {@see AcknowledgementReceivedEvent}.
 *
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
 */
class OsoAcknowledgementReceivedEvent extends AcknowledgementReceivedEvent {
}//end class
