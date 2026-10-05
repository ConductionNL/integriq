<?php

/**
 * Integriq StUF-ZDS Outbound Document Translator.
 *
 * Builds the two ZDS 1.2 messages that put a document into a StUF-ZDS case
 * system: `genereerDocumentIdentificatie_Di02` (the case system hands out the
 * document's identificatie) and `voegZaakdocumentToe_Lk01` (an `edcLk01`
 * kennisgeving that adds the document, with its file inline, to a case). It
 * also reads the identificatie from the `genereerDocumentIdentificatie_Du02`
 * answer.
 *
 * The input is the delivery as the document mapping produced it, in ZGW
 * vocabulary (`titel`, `creatiedatum`, `vertrouwelijkheidaanduiding`, ...),
 * so one mapping serves both legs. A missing required field raises
 * {@see StufZknTranslationException} before any XML is built, and the
 * rendered envelope is scanned for unresolved template markers, as the
 * `zakLk01` translator does.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;
use OCA\Integriq\Service\Stuf\StufXmlParser;

/**
 * Delivery -> ZDS 1.2 document messages.
 *
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class OutboundDocumentTranslator {

	/**
	 * Constructor.
	 *
	 * @param StufLiteralLeakGuard $leakGuard Shared literal-leak scan.
	 * @param StufXmlParser        $xmlParser Shared XXE-hardened XML parser.
	 */
	public function __construct(
		private readonly StufLiteralLeakGuard $leakGuard = new StufLiteralLeakGuard(),
		private readonly StufXmlParser $xmlParser = new StufXmlParser(),
	) {

	}//end __construct()

	/**
	 * Build the `genereerDocumentIdentificatie_Di02` request.
	 *
	 * @param array{organisatie:string,applicatie:string}  $zender    This bridge as sender.
	 * @param array{organisatie:string,applicatie?:string} $ontvanger The case system.
	 *
	 * @return array{referentienummer: string, xml: string}
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function identificationRequest(array $zender, array $ontvanger): array {
		$referenceNumber = 'ZDS-' . bin2hex(random_bytes(8));
		[$document, $body] = $this->envelope();

		$request = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:genereerDocumentIdentificatie_Di02');
		$body->appendChild($request);
		$this->appendStuurgegevens(
			document: $document,
			parent: $request,
			berichtcode: 'Di02',
			referenceNumber: $referenceNumber,
			zender: $zender,
			ontvanger: $ontvanger,
			tail: ['functie' => 'genereerDocumentidentificatie']
		);

		return ['referentienummer' => $referenceNumber, 'xml' => $this->render(document: $document)];
	}//end identificationRequest()

	/**
	 * Read the document identificatie from a `genereerDocumentIdentificatie_Du02` answer.
	 *
	 * @param string $xml The answer envelope.
	 *
	 * @return string The identificatie the case system handed out.
	 *
	 * @throws StufZknTranslationException When the answer carries no identificatie.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function identificationFromAnswer(string $xml): string {
		$parsed = $this->xmlParser->parse(xml: $xml);
		if ($parsed !== null) {
			$found = $parsed->xpath('//*[local-name()="genereerDocumentIdentificatie_Du02"]/*[local-name()="document"]/*[local-name()="identificatie"]');
			if (is_array($found) === true && $found !== []) {
				$value = trim((string)$found[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		throw new StufZknTranslationException(
			message: 'The case system answered genereerDocumentIdentificatie without a document identificatie.'
		);
	}//end identificationFromAnswer()

	/**
	 * Build the `voegZaakdocumentToe_Lk01` kennisgeving (`edcLk01`).
	 *
	 * @param array                                                $document              The mapped delivery (ZGW field names).
	 * @param string                                               $documentIdentificatie The identificatie from genereerDocumentIdentificatie.
	 * @param string                                               $zaakIdentificatie     The case the document belongs to.
	 * @param string                                               $documenttype          The case type's document type description (`dct.omschrijving`).
	 * @param array{content:string,filename:string,mimeType:string} $file                  The file, sent inline.
	 * @param array{organisatie:string,applicatie:string}          $zender                This bridge as sender.
	 * @param array{organisatie:string,applicatie?:string}         $ontvanger             The case system.
	 *
	 * @return array{referentienummer: string, xml: string}
	 *
	 * @throws StufZknTranslationException When a required value is missing.
	 *
	 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
	 */
	public function documentMessage(
		array $document,
		string $documentIdentificatie,
		string $zaakIdentificatie,
		string $documenttype,
		array $file,
		array $zender,
		array $ontvanger,
	): array {
		$required = [
			'identificatie' => $documentIdentificatie,
			'zaakIdentificatie' => $zaakIdentificatie,
			'documenttype' => $documenttype,
			'titel' => trim((string)($document['titel'] ?? '')),
		];
		foreach ($required as $name => $value) {
			if (trim($value) === '') {
				throw new StufZknTranslationException(
					message: 'A voegZaakdocumentToe message needs a ' . $name . '; refusing to send without one.'
				);
			}
		}

		$referenceNumber = 'ZDS-' . bin2hex(random_bytes(8));
		[$xml, $body] = $this->envelope();

		$lk01 = $xml->createElementNS(StufZknNamespaces::ZKN, 'zkn:edcLk01');
		$body->appendChild($lk01);
		$this->appendStuurgegevens(
			document: $xml,
			parent: $lk01,
			berichtcode: 'Lk01',
			referenceNumber: $referenceNumber,
			zender: $zender,
			ontvanger: $ontvanger,
			tail: ['entiteittype' => 'EDC']
		);

		$parameters = $xml->createElementNS(StufZknNamespaces::ZKN, 'zkn:parameters');
		$lk01->appendChild($parameters);
		$this->appendText(document: $xml, parent: $parameters, name: 'StUF:mutatiesoort', value: 'T');
		$this->appendText(document: $xml, parent: $parameters, name: 'StUF:indicatorOvername', value: 'V');

		$object = $xml->createElementNS(StufZknNamespaces::ZKN, 'zkn:object');
		$object->setAttributeNS(StufZknNamespaces::STUF, 'StUF:entiteittype', 'EDC');
		$object->setAttributeNS(StufZknNamespaces::STUF, 'StUF:verwerkingssoort', 'T');
		$lk01->appendChild($object);

		// The EDC element order of the ZDS 1.2 zkn0310 entity schema.
		$fields = [
			'identificatie' => $documentIdentificatie,
			'dct.omschrijving' => $documenttype,
			'creatiedatum' => $this->stufDate(value: (string)($document['creatiedatum'] ?? '')),
			'ontvangstdatum' => $this->stufDate(value: (string)($document['ontvangstdatum'] ?? '')),
			'titel' => $required['titel'],
			'beschrijving' => (string)($document['beschrijving'] ?? ''),
			'formaat' => (string)($document['formaat'] ?? $file['mimeType']),
			'taal' => (string)($document['taal'] ?? ''),
			'versie' => (string)($document['versie'] ?? ''),
			'status' => (string)($document['status'] ?? ''),
			'verzenddatum' => $this->stufDate(value: (string)($document['verzenddatum'] ?? '')),
			'vertrouwelijkAanduiding' => strtoupper((string)($document['vertrouwelijkheidaanduiding'] ?? '')),
			'auteur' => (string)($document['auteur'] ?? ''),
			'link' => (string)($document['link'] ?? ''),
		];
		foreach ($fields as $name => $value) {
			$this->appendZknField(document: $xml, parent: $object, name: $name, value: $value);
		}

		$content = $xml->createElementNS(StufZknNamespaces::ZKN, 'zkn:inhoud', base64_encode($file['content']));
		$content->setAttributeNS(StufZknNamespaces::STUF, 'StUF:bestandsnaam', $file['filename']);
		$content->setAttributeNS(StufZknNamespaces::XMIME, 'xmime:contentType', $file['mimeType']);
		$object->appendChild($content);

		$relation = $xml->createElementNS(StufZknNamespaces::ZKN, 'zkn:isRelevantVoor');
		$relation->setAttributeNS(StufZknNamespaces::STUF, 'StUF:entiteittype', 'EDCZAK');
		$relation->setAttributeNS(StufZknNamespaces::STUF, 'StUF:verwerkingssoort', 'T');
		$object->appendChild($relation);
		$case = $xml->createElementNS(StufZknNamespaces::ZKN, 'zkn:gerelateerde');
		$case->setAttributeNS(StufZknNamespaces::STUF, 'StUF:entiteittype', 'ZAK');
		$case->setAttributeNS(StufZknNamespaces::STUF, 'StUF:verwerkingssoort', 'I');
		$relation->appendChild($case);
		$this->appendText(document: $xml, parent: $case, name: 'zkn:identificatie', value: $zaakIdentificatie);

		return ['referentienummer' => $referenceNumber, 'xml' => $this->render(document: $xml)];
	}//end documentMessage()

	/**
	 * A SOAP envelope with the StUF, zkn, xsi and xmime prefixes declared.
	 *
	 * @return array{0: DOMDocument, 1: DOMElement} The document and its soap:Body.
	 */
	private function envelope(): array {
		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$envelope = $document->createElementNS(StufZknNamespaces::SOAP, 'soap:Envelope');
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:StUF', StufZknNamespaces::STUF);
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:zkn', StufZknNamespaces::ZKN);
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', StufZknNamespaces::XSI);
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xmime', StufZknNamespaces::XMIME);
		$document->appendChild($envelope);

		$body = $document->createElementNS(StufZknNamespaces::SOAP, 'soap:Body');
		$envelope->appendChild($body);

		return [$document, $body];
	}//end envelope()

	/**
	 * Append `stuurgegevens` (berichtcode, zender, ontvanger, referentienummer, tijdstipBericht, then the tail).
	 *
	 * @param DOMDocument          $document        The owning document.
	 * @param DOMElement           $parent          The message element.
	 * @param string               $berichtcode     Di02 or Lk01.
	 * @param string               $referenceNumber The message's referentienummer.
	 * @param array                $zender          `organisatie` and `applicatie` of this bridge.
	 * @param array                $ontvanger       `organisatie` and optional `applicatie` of the case system.
	 * @param array<string,string> $tail            Elements after tijdstipBericht (functie or entiteittype).
	 *
	 * @return void
	 */
	private function appendStuurgegevens(
		DOMDocument $document,
		DOMElement $parent,
		string $berichtcode,
		string $referenceNumber,
		array $zender,
		array $ontvanger,
		array $tail,
	): void {
		$stuurgegevens = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:stuurgegevens');
		$parent->appendChild($stuurgegevens);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'StUF:berichtcode', value: $berichtcode);

		foreach (['zender' => $zender, 'ontvanger' => $ontvanger] as $role => $system) {
			$element = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:' . $role);
			$stuurgegevens->appendChild($element);
			foreach (['organisatie', 'applicatie'] as $part) {
				$value = trim((string)($system[$part] ?? ''));
				if ($value !== '') {
					$this->appendText(document: $document, parent: $element, name: 'StUF:' . $part, value: $value);
				}
			}
		}

		$this->appendText(document: $document, parent: $stuurgegevens, name: 'StUF:referentienummer', value: $referenceNumber);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'StUF:tijdstipBericht', value: (new DateTime())->format('YmdHis'));
		foreach ($tail as $name => $value) {
			$this->appendText(document: $document, parent: $stuurgegevens, name: 'StUF:' . $name, value: $value);
		}
	}//end appendStuurgegevens()

	/**
	 * Append a zkn field, or an explicitly empty one (`StUF:noValue` + `xsi:nil`) when there is no value.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement  $parent   The EDC object.
	 * @param string      $name     The field's local name.
	 * @param string      $value    The value, '' for none.
	 *
	 * @return void
	 */
	private function appendZknField(DOMDocument $document, DOMElement $parent, string $name, string $value): void {
		if (trim($value) !== '') {
			$this->appendText(document: $document, parent: $parent, name: 'zkn:' . $name, value: $value);
			return;
		}

		$field = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:' . $name);
		$field->setAttributeNS(StufZknNamespaces::STUF, 'StUF:noValue', 'geenWaarde');
		$field->setAttributeNS(StufZknNamespaces::XSI, 'xsi:nil', 'true');
		$parent->appendChild($field);
	}//end appendZknField()

	/**
	 * Append a text element; the prefix of `$name` picks the namespace.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement  $parent   The parent element.
	 * @param string      $name     `StUF:<name>` or `zkn:<name>`.
	 * @param string      $value    The text.
	 *
	 * @return void
	 */
	private function appendText(DOMDocument $document, DOMElement $parent, string $name, string $value): void {
		$namespace = StufZknNamespaces::ZKN;
		if (str_starts_with($name, 'StUF:') === true) {
			$namespace = StufZknNamespaces::STUF;
		}

		$element = $document->createElementNS($namespace, $name);
		$element->appendChild($document->createTextNode($value));
		$parent->appendChild($element);
	}//end appendText()

	/**
	 * A ZGW date (`2026-10-05`, or a date-time) as a StUF date (`20261005`); '' stays ''.
	 *
	 * @param string $value The ZGW date.
	 *
	 * @return string
	 */
	private function stufDate(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		return str_replace('-', '', substr($value, 0, 10));
	}//end stufDate()

	/**
	 * Render the envelope, refusing one that still carries a template marker.
	 *
	 * @param DOMDocument $document The envelope.
	 *
	 * @return string
	 *
	 * @throws StufZknTranslationException When a marker survived.
	 */
	private function render(DOMDocument $document): string {
		$xml = (string)$document->saveXML();
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new StufZknTranslationException(
				message: 'Rendered StUF-ZDS message still contains an unresolved template marker; refusing to send.'
			);
		}

		return $xml;
	}//end render()
}//end class
