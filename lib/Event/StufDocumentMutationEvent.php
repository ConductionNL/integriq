<?php

/**
 * Integriq StUF Document Mutation Event.
 *
 * An edcLk01 a case system sent: create or change this document on a zaak. The
 * owning app accepts it with the document's identificatie, or refuses.
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
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

/**
 * A document kennisgeving (edcLk01).
 *
 * The constructor, getters and acceptance live in {@see StufMutationEvent}.
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
class StufDocumentMutationEvent extends StufMutationEvent {
}//end class
