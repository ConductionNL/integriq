<?php

/**
 * Integriq UwlrEduVAcknowledgementReceived Event.
 *
 * Dispatched whenever a UWLR, Edu-V, Basispoort or Entree-content
 * acknowledgement/retour is received and translated, mirroring
 * OsoAcknowledgementReceivedEvent for this adapter's shared
 * acknowledgement leg.
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * A UWLR/Edu-V/Basispoort/Entree-content acknowledgement, translated and
 * ready for a listener to act on.
 *
 * The kenmerk, signaalcode, description and acceptance live in {@see AcknowledgementReceivedEvent}.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */
class UwlrEduVAcknowledgementReceivedEvent extends AcknowledgementReceivedEvent {
}//end class
