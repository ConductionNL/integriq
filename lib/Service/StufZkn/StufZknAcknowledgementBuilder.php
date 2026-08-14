<?php

/**
 * OpenConnector StUF-ZKN Acknowledgement Builder.
 *
 * Builds the two StUF 3.01 core reply berichten every inbound `zakLk01`/
 * `edcLk01` kennisgeving gets: `Bv03Bericht` (bevestiging — success) or
 * `Fo03Bericht` (foutbericht — fault), both correlated back to the
 * inbound message's `stuurgegevens.referentienummer` via
 * `StUF:crossRefnummer`. ASSUMED SHAPE — no live StUF-ZKN endpoint was
 * available in this environment to verify the exact `Fo03` fault-code
 * catalogue against; see design.md "StUF element/attribute assumptions".
 *
 * FAULT SAFETY: {@see buildFo03()} NEVER includes internal exception
 * detail (stack traces, file paths, class names) in the wire response —
 * `omschrijving` is always one of a small, fixed, generic catalogue keyed
 * by a stable `$reason` string, mirroring `PeppolController::inbound()`'s
 * "undifferentiated error body" convention applied to StUF's own fault
 * shape instead of a bare HTTP 401.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\StufZkn
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-bv03-fo03-acknowledgement-shaping-req-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\StufZkn;

use DateTime;
use DOMDocument;
use DOMElement;

/**
 * Builds `Bv03Bericht`/`Fo03Bericht` StUF-ZKN reply envelopes.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-bv03-fo03-acknowledgement-shaping-req-004
 */
class StufZknAcknowledgementBuilder {

	/**
	 * Generic, secret-free fault catalogue — `$reason` key -> `[code, omschrijving]`.
	 * Never extend this with anything derived from a caught exception's own message.
	 *
	 * @var array<string, array{code: string, omschrijving: string}>
	 */
	private const FAULT_CATALOGUE = [
		'unrecognised_berichttype' => [
			'code' => 'StUF058',
			'omschrijving' => 'Het berichttype wordt niet ondersteund.',
		],
		'validation_failed' => [
			'code' => 'StUF063',
			'omschrijving' => 'Het bericht kon niet worden verwerkt: het bericht is onvolledig of onjuist.',
		],
		'not_configured' => [
			'code' => 'StUF055',
			'omschrijving' => 'De ontvangende applicatie is momenteel niet beschikbaar.',
		],
		'processing_failed' => [
			'code' => 'StUF055',
			'omschrijving' => 'Er is een fout opgetreden bij het verwerken van het bericht.',
		],
	];

	/**
	 * Build a `Bv03Bericht` (bevestiging) reply.
	 *
	 * @param string $crossRefnummer The inbound message's `stuurgegevens.referentienummer`.
	 * @param string $zenderOrganisation This bridge's own organisatie code (reply `zender`).
	 * @param string $ontvangerOrganisation The original sender's organisatie code (reply `ontvanger`).
	 *
	 * @return string The rendered `Bv03Bericht` SOAP envelope XML.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-successfully-processed-zaklk01-replies-with-a-bv03
	 */
	public function buildBv03(string $crossRefnummer, string $zenderOrganisation, string $ontvangerOrganisation): string {
		[$document, $body] = $this->skeleton();

		$message = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:Bv03Bericht');
		$body->appendChild($message);

		$this->appendStuurgegevens(
			document: $document,
			parent: $message,
			berichtcode: 'Bv03',
			crossRefnummer: $crossRefnummer,
			zenderOrganisation: $zenderOrganisation,
			ontvangerOrganisation: $ontvangerOrganisation
		);

		return (string)$document->saveXML();
	}//end buildBv03()

	/**
	 * Build a `Fo03Bericht` (foutbericht) reply — never leaks internal exception detail.
	 *
	 * @param string $reason A stable key into {@see FAULT_CATALOGUE} (NEVER a raw
	 *                       exception message). Falls back to `processing_failed`
	 *                       when unrecognised.
	 * @param string $crossRefnummer The inbound message's `stuurgegevens.referentienummer`,
	 *                               or an empty string when the inbound envelope itself
	 *                               could not be correlated (e.g. malformed XML).
	 * @param string $zenderOrganisation This bridge's own organisatie code (reply `zender`).
	 * @param string $ontvangerOrganisation The original sender's organisatie code, or an empty string
	 *                                     when unknown.
	 *
	 * @return string The rendered `Fo03Bericht` SOAP envelope XML.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-an-unprocessable-message-replies-with-a-fo03-and-leaks-no-internal-detail
	 */
	public function buildFo03(
		string $reason,
		string $crossRefnummer,
		string $zenderOrganisation,
		string $ontvangerOrganisation,
	): string {
		$fault = (self::FAULT_CATALOGUE[$reason] ?? self::FAULT_CATALOGUE['processing_failed']);

		[$document, $body] = $this->skeleton();

		$message = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:Fo03Bericht');
		$body->appendChild($message);

		$this->appendStuurgegevens(
			document: $document,
			parent: $message,
			berichtcode: 'Fo03',
			crossRefnummer: $crossRefnummer,
			zenderOrganisation: $zenderOrganisation,
			ontvangerOrganisation: $ontvangerOrganisation
		);

		$faultBody = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:body');
		$message->appendChild($faultBody);
		$this->appendText(document: $document, parent: $faultBody, name: 'code', value: $fault['code']);
		$this->appendText(document: $document, parent: $faultBody, name: 'plek', value: 'server');
		$this->appendText(document: $document, parent: $faultBody, name: 'omschrijving', value: $fault['omschrijving']);

		return (string)$document->saveXML();
	}//end buildFo03()

	/**
	 * Build the shared SOAP envelope/body skeleton.
	 *
	 * @return array{0: DOMDocument, 1: DOMElement} The document and body (the envelope root is
	 *                                              already appended to the document; callers only ever need the body to append into).
	 */
	private function skeleton(): array {
		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$envelope = $document->createElementNS(StufZknNamespaces::SOAP, 'soap:Envelope');
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:StUF', StufZknNamespaces::STUF);
		$document->appendChild($envelope);

		$body = $document->createElementNS(StufZknNamespaces::SOAP, 'soap:Body');
		$envelope->appendChild($body);

		return [$document, $body];
	}//end skeleton()

	/**
	 * Append the reply `stuurgegevens` block, correlated via `crossRefnummer`.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $parent The `Bv03Bericht`/`Fo03Bericht` element.
	 * @param string $berichtcode `Bv03` or `Fo03`.
	 * @param string $crossRefnummer The inbound message's referentienummer (may be empty).
	 * @param string $zenderOrganisation This bridge's own organisatie code.
	 * @param string $ontvangerOrganisation The original sender's organisatie code (may be empty).
	 *
	 * @return void
	 */
	private function appendStuurgegevens(
		DOMDocument $document,
		DOMElement $parent,
		string $berichtcode,
		string $crossRefnummer,
		string $zenderOrganisation,
		string $ontvangerOrganisation,
	): void {
		$stuurgegevens = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:stuurgegevens');
		$parent->appendChild($stuurgegevens);

		$this->appendText(document: $document, parent: $stuurgegevens, name: 'berichtcode', value: $berichtcode);

		$zender = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:zender');
		$stuurgegevens->appendChild($zender);
		$this->appendText(document: $document, parent: $zender, name: 'organisatie', value: $zenderOrganisation);

		$ontvanger = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:ontvanger');
		$stuurgegevens->appendChild($ontvanger);
		$this->appendText(document: $document, parent: $ontvanger, name: 'organisatie', value: $ontvangerOrganisation);

		$this->appendText(
			document: $document,
			parent: $stuurgegevens,
			name: 'referentienummer',
			value: strtoupper($berichtcode) . '-' . bin2hex(random_bytes(6))
		);
		$this->appendText(
			document: $document,
			parent: $stuurgegevens,
			name: 'tijdstipBericht',
			value: (new DateTime())->format('YmdHis')
		);

		if ($crossRefnummer !== '') {
			$this->appendText(
				document: $document,
				parent: $stuurgegevens,
				name: 'crossRefnummer',
				value: $crossRefnummer
			);
		}

	}//end appendStuurgegevens()

	/**
	 * Append a StUF-namespaced text-valued child element.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The child element local name.
	 * @param string $value The text value.
	 *
	 * @return void
	 */
	private function appendText(DOMDocument $document, DOMElement $parent, string $name, string $value): void {
		$parent->appendChild(
			$document->createElementNS(StufZknNamespaces::STUF, 'StUF:' . $name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES))
		);

	}//end appendText()
}//end class
