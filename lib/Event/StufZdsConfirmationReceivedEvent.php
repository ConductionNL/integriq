<?php

/**
 * Integriq StUF-ZDS Confirmation Received Event.
 *
 * Lets a sibling app hand integriq, in process, a confirmation envelope that
 * arrived on the sibling's own wire-contract URL (dossiq's /api/stuf/inbound
 * for one release, decision 193). Integriq's listener answers it with the
 * same status and body its own /api/stuf-zkn/confirmations route would give.
 * Unhandled (integriq disabled) means the sender should be told to retry.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * A confirmation envelope a sibling app received, for integriq to conclude.
 *
 * The envelope and the answer live in {@see StufEnvelopeReceivedEvent}.
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */
class StufZdsConfirmationReceivedEvent extends StufEnvelopeReceivedEvent {
}//end class
