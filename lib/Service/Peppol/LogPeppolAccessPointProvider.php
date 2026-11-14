<?php

/**
 * Integriq Log Peppol Access Point Provider.
 *
 * Sandbox/mock binding for {@see PeppolAccessPointProviderInterface}: performs
 * no real network call, answers lookups from `configuration.mockParticipants`,
 * and returns a synthetic `MOCK-PEPPOL-<n>` transmission id from
 * `submitDocument`. It MUST NOT read any secret. It is the default for dev/CI
 * and mirrors the `source-management` mock-mode convention.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Peppol
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
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-log-provider-transmits-without-a-network-call-or-secret
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Peppol;

/**
 * Sandbox Peppol Access Point provider: canned lookups, synthetic transmission ids.
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-log-provider-transmits-without-a-network-call-or-secret
 */
class LogPeppolAccessPointProvider implements PeppolAccessPointProviderInterface {

	/**
	 * Document types returned for a recognised mock participant.
	 *
	 * @var string[]
	 */
	private const MOCK_SUPPORTED_DOC_TYPES = ['ubl-invoice-2.1', 'ubl-order-3.0'];

	/**
	 * Per-process counter for synthetic transmission ids (`MOCK-PEPPOL-<n>`).
	 *
	 * A per-process, in-memory counter is sufficient for a sandbox binding:
	 * ids only need to be locally unique for the duration of one request/job
	 * run so submissions within it are visibly distinct; global uniqueness
	 * across processes is not required for a mock id.
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * The inbound UBL invoice the sandbox answers to every fetch.
	 *
	 * @var string
	 */
	public const FIXTURE_INVOICE = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
  xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
  xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0</cbc:CustomizationID>
  <cbc:ProfileID>urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</cbc:ProfileID>
  <cbc:ID>LV-2026-0001</cbc:ID>
  <cbc:IssueDate>2026-09-28</cbc:IssueDate>
  <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
  <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cbc:EndpointID schemeID="0106">12345678</cbc:EndpointID>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>Leverancier Voorbeeld B.V.</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  <cac:TaxTotal>
    <cbc:TaxAmount currencyID="EUR">210.00</cbc:TaxAmount>
  </cac:TaxTotal>
  <cac:LegalMonetaryTotal>
    <cbc:LineExtensionAmount currencyID="EUR">1000.00</cbc:LineExtensionAmount>
    <cbc:TaxExclusiveAmount currencyID="EUR">1000.00</cbc:TaxExclusiveAmount>
    <cbc:TaxInclusiveAmount currencyID="EUR">1210.00</cbc:TaxInclusiveAmount>
    <cbc:PayableAmount currencyID="EUR">1210.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
XML;

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The Peppol source's `configuration` object (`mockParticipants`).
	 * @param string $peppolId The participant identifier, `scheme:identifier`.
	 *
	 * @return array{exists: bool, supportedDocTypes: string[]} The lookup result.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-peppol-participant-smp-lookup-endpoint-req-001
	 */
	public function lookupParticipant(array $sourceConfiguration, string $peppolId): array {
		$mockParticipants = ($sourceConfiguration['mockParticipants'] ?? []);
		if (is_array($mockParticipants) === false) {
			$mockParticipants = [];
		}

		if (in_array($peppolId, $mockParticipants, true) === true) {
			return [
				'exists' => true,
				'supportedDocTypes' => self::MOCK_SUPPORTED_DOC_TYPES,
			];
		}

		return [
			'exists' => false,
			'supportedDocTypes' => [],
		];

	}//end lookupParticipant()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The Peppol source's `configuration` object (unused — the log provider needs no secret).
	 * @param string $recipientPeppolId The recipient participant identifier, `scheme:identifier`.
	 * @param string $documentType The UBL document type slug.
	 * @param string $payload The UBL document.
	 *
	 * @return string The synthetic `MOCK-PEPPOL-<n>` transmission id.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-log-provider-transmits-without-a-network-call-or-secret
	 */
	public function submitDocument(
		array $sourceConfiguration,
		string $recipientPeppolId,
		string $documentType,
		string $payload,
	): string {
		self::$counter++;
		return 'MOCK-PEPPOL-' . self::$counter;
	}//end submitDocument()

	/**
	 * {@inheritDoc}
	 *
	 * The sandbox has no Access Point to fetch from: every reference answers
	 * the same fixture, a UBL invoice from supplier `0106:12345678`
	 * (Leverancier Voorbeeld B.V.) of EUR 1,210.00 including VAT.
	 *
	 * @param array $sourceConfiguration The Peppol source's `configuration` object (unused).
	 * @param string $reference The Access Point's reference (unused).
	 *
	 * @return string The fixture UBL invoice.
	 *
	 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-an-inbound-document-is-stored-where-the-receiving-app-can-read-it-req-009
	 */
	public function fetchInboundDocument(array $sourceConfiguration, string $reference): string {
		return self::FIXTURE_INVOICE;
	}//end fetchInboundDocument()
}//end class
