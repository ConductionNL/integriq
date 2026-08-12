<?php

/**
 * OpenConnector Peppol Access Point Provider Interface.
 *
 * Narrow domain seam through which every Peppol participant lookup and
 * document transmission occurs. A new Access Point (AP) vendor is added by
 * implementing this interface, never by editing PeppolTransmissionService or
 * PeppolController — see design.md "Provider seam vs category
 * IntegrationProvider".
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Peppol
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Peppol;

use OCA\OpenConnector\Exception\PeppolProviderException;

/**
 * A Peppol Access Point binding: SMP/directory lookup + document submission.
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-access-point-provider-abstraction-with-log-and-generic-rest-bindings-req-002
 */
interface PeppolAccessPointProviderInterface {
	/**
	 * Resolve a Peppol participant against the Access Point's SMP/directory.
	 *
	 * @param array $sourceConfiguration The Peppol source's `configuration` object
	 *                                   (`provider`, `baseUrl`, `mockParticipants`,
	 *                                   `authentication.credentialRef`).
	 * @param string $peppolId The participant identifier, `scheme:identifier`
	 *                         (e.g. `0192:1234567890`). Already shape-validated by the caller.
	 *
	 * @return array{exists: bool, supportedDocTypes: string[]} The lookup result.
	 *
	 * @throws PeppolProviderException When the Access Point is unreachable or errors.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-peppol-participant-smp-lookup-endpoint-req-001
	 */
	public function lookupParticipant(array $sourceConfiguration, string $peppolId): array;

	/**
	 * Submit a UBL document to a Peppol participant via the Access Point.
	 *
	 * @param array $sourceConfiguration The Peppol source's `configuration` object.
	 * @param string $recipientPeppolId The recipient participant identifier, `scheme:identifier`.
	 * @param string $documentType The UBL document type slug (e.g. `ubl-invoice-2.1`).
	 * @param string $payload The UBL payload (or a reference to it — see PeppolTransmissionService).
	 *
	 * @return string The Access Point-assigned transmission id.
	 *
	 * @throws PeppolProviderException When the submission fails (unreachable AP, AP-side rejection, brokering failure).
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-event-driven-outbound-transmission-with-status-lifecycle-req-003
	 */
	public function submitDocument(
		array $sourceConfiguration,
		string $recipientPeppolId,
		string $documentType,
		string $payload,
	): string;
}//end interface
