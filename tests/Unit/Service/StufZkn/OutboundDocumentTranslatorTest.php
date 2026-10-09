<?php

/**
 * Tests for the StUF-ZDS outbound document translator (genereerDocumentIdentificatie, voegZaakdocumentToe).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\OutboundDocumentTranslator;
use OCA\Integriq\Service\StufZkn\StufZknNamespaces;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/connectors-case-system-document-delivery/specs/case-system-document-delivery/spec.md#requirement-a-filinq-delivery-becomes-a-document-in-the-case-system-req-csd-002
 */
class OutboundDocumentTranslatorTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../../fixtures/stuf-zds/';

	/**
	 * A redacted copy as the delivery mapping produces it.
	 *
	 * @var array<string,string>
	 */
	private const DOCUMENT = [
		'titel' => 'Besluit bezwaar (geanonimiseerd)',
		'beschrijving' => 'Geanonimiseerde kopie van 0363-DOC-2026-000017',
		'creatiedatum' => '2026-10-05',
		'taal' => 'dut',
		'auteur' => 'Team Bezwaar',
		'vertrouwelijkheidaanduiding' => 'openbaar',
		'status' => 'definitief',
	];

	/**
	 * XPath over a rendered envelope with the zkn and StUF prefixes registered.
	 *
	 * @param string $xml The envelope.
	 *
	 * @return DOMXPath
	 */
	private function xpath(string $xml): DOMXPath {
		$document = new DOMDocument();
		$this->assertTrue($document->loadXML($xml));
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('zkn', StufZknNamespaces::ZKN);
		$xpath->registerNamespace('StUF', StufZknNamespaces::STUF);
		$xpath->registerNamespace('soap', StufZknNamespaces::SOAP);
		return $xpath;
	}//end xpath()

	/**
	 * The text of the one node an expression selects.
	 *
	 * @param DOMXPath $xpath      The xpath.
	 * @param string   $expression The expression.
	 *
	 * @return string
	 */
	private function text(DOMXPath $xpath, string $expression): string {
		$nodes = $xpath->query($expression);
		$this->assertSame(1, $nodes->length, $expression);
		return (string)$nodes->item(0)->textContent;
	}//end text()

	/**
	 * Build the voegZaakdocumentToe message for the redacted copy.
	 *
	 * @param array $document The mapped document.
	 *
	 * @return array{referentienummer:string,xml:string}
	 */
	private function documentMessage(array $document=self::DOCUMENT): array {
		return (new OutboundDocumentTranslator())->documentMessage(
			document: $document,
			documentId: '0363-DOC-2026-000042',
			zaakIdentificatie: '0363-ZAAK-2026-0099',
			documenttype: 'Besluit',
			file: ['content' => 'ANON!', 'filename' => 'besluit-geanonimiseerd.pdf', 'mimeType' => 'application/pdf'],
			zender: ['organisatie' => '0363', 'applicatie' => 'integriq'],
			ontvanger: ['organisatie' => '0363', 'applicatie' => 'ZSH']
		);
	}//end documentMessage()

	/**
	 * The identificatie request is a Di02 vrij bericht with the genereerDocumentidentificatie function.
	 *
	 * @return void
	 */
	public function testTheIdentificationRequestIsADi02(): void {
		$message = (new OutboundDocumentTranslator())->identificationRequest(
			zender: ['organisatie' => '0363', 'applicatie' => 'integriq'],
			ontvanger: ['organisatie' => '0363', 'applicatie' => 'ZSH']
		);

		$xpath = $this->xpath($message['xml']);
		$root  = '/soap:Envelope/soap:Body/zkn:genereerDocumentIdentificatie_Di02/zkn:stuurgegevens';
		$this->assertSame('Di02', $this->text($xpath, $root . '/StUF:berichtcode'));
		$this->assertSame('0363', $this->text($xpath, $root . '/StUF:zender/StUF:organisatie'));
		$this->assertSame('integriq', $this->text($xpath, $root . '/StUF:zender/StUF:applicatie'));
		$this->assertSame('ZSH', $this->text($xpath, $root . '/StUF:ontvanger/StUF:applicatie'));
		$this->assertSame($message['referentienummer'], $this->text($xpath, $root . '/StUF:referentienummer'));
		$this->assertSame('genereerDocumentidentificatie', $this->text($xpath, $root . '/StUF:functie'));
	}//end testTheIdentificationRequestIsADi02()

	/**
	 * The identificatie is read from the case system's Du02 answer.
	 *
	 * @return void
	 */
	public function testTheIdentificatieIsReadFromTheDu02Answer(): void {
		$answer = (string)file_get_contents(self::FIXTURES . 'genereerDocumentIdentificatie_Du02.xml');

		$this->assertSame('0363-DOC-2026-000042', (new OutboundDocumentTranslator())->identificationFromAnswer(xml: $answer));
	}//end testTheIdentificatieIsReadFromTheDu02Answer()

	/**
	 * An answer without a document identificatie is refused, not turned into an empty id.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAnIdentificatieIsRefused(): void {
		$answer = (string)file_get_contents(self::FIXTURES . 'voegZaakdocumentToe_Bv03.xml');

		$this->expectException(StufZknTranslationException::class);
		$this->expectExceptionMessage('genereerDocumentIdentificatie');
		(new OutboundDocumentTranslator())->identificationFromAnswer(xml: $answer);
	}//end testAnAnswerWithoutAnIdentificatieIsRefused()

	/**
	 * The document message is an edcLk01 with the EDC fields in ZDS order, the file inline and the case relation.
	 *
	 * @return void
	 */
	public function testTheDocumentMessageCarriesTheFileAndTheCase(): void {
		$message = $this->documentMessage();
		$xpath   = $this->xpath($message['xml']);

		$lk01 = '/soap:Envelope/soap:Body/zkn:edcLk01';
		$this->assertSame('Lk01', $this->text($xpath, $lk01 . '/zkn:stuurgegevens/StUF:berichtcode'));
		$this->assertSame('EDC', $this->text($xpath, $lk01 . '/zkn:stuurgegevens/StUF:entiteittype'));
		$this->assertSame($message['referentienummer'], $this->text($xpath, $lk01 . '/zkn:stuurgegevens/StUF:referentienummer'));
		$this->assertSame('T', $this->text($xpath, $lk01 . '/zkn:parameters/StUF:mutatiesoort'));

		$object = $xpath->query($lk01 . '/zkn:object')->item(0);
		$this->assertInstanceOf(DOMElement::class, $object);
		$this->assertSame('EDC', $object->getAttributeNS(StufZknNamespaces::STUF, 'entiteittype'));
		$this->assertSame('T', $object->getAttributeNS(StufZknNamespaces::STUF, 'verwerkingssoort'));

		$order = [];
		foreach ($object->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$order[] = $child->localName;
			}
		}

		$this->assertSame(
			['identificatie', 'dct.omschrijving', 'creatiedatum', 'ontvangstdatum', 'titel', 'beschrijving', 'formaat', 'taal', 'versie', 'status', 'verzenddatum', 'vertrouwelijkAanduiding', 'auteur', 'link', 'inhoud', 'isRelevantVoor'],
			$order
		);

		$edc = $lk01 . '/zkn:object';
		$this->assertSame('0363-DOC-2026-000042', $this->text($xpath, $edc . '/zkn:identificatie'));
		$this->assertSame('Besluit', $this->text($xpath, $edc . '/zkn:dct.omschrijving'));
		$this->assertSame('20261005', $this->text($xpath, $edc . '/zkn:creatiedatum'));
		$this->assertSame('Besluit bezwaar (geanonimiseerd)', $this->text($xpath, $edc . '/zkn:titel'));
		$this->assertSame('application/pdf', $this->text($xpath, $edc . '/zkn:formaat'));
		$this->assertSame('OPENBAAR', $this->text($xpath, $edc . '/zkn:vertrouwelijkAanduiding'));
		$this->assertSame('true', $xpath->query($edc . '/zkn:ontvangstdatum')->item(0)->getAttributeNS(StufZknNamespaces::XSI, 'nil'));

		$content = $xpath->query($edc . '/zkn:inhoud')->item(0);
		$this->assertSame(base64_encode('ANON!'), $content->textContent);
		$this->assertSame('besluit-geanonimiseerd.pdf', $content->getAttributeNS(StufZknNamespaces::STUF, 'bestandsnaam'));
		$this->assertSame('application/pdf', $content->getAttributeNS(StufZknNamespaces::XMIME, 'contentType'));

		$relation = $xpath->query($edc . '/zkn:isRelevantVoor')->item(0);
		$this->assertSame('EDCZAK', $relation->getAttributeNS(StufZknNamespaces::STUF, 'entiteittype'));
		$case = $xpath->query($edc . '/zkn:isRelevantVoor/zkn:gerelateerde')->item(0);
		$this->assertSame('ZAK', $case->getAttributeNS(StufZknNamespaces::STUF, 'entiteittype'));
		$this->assertSame('I', $case->getAttributeNS(StufZknNamespaces::STUF, 'verwerkingssoort'));
		$this->assertSame('0363-ZAAK-2026-0099', $this->text($xpath, $edc . '/zkn:isRelevantVoor/zkn:gerelateerde/zkn:identificatie'));
	}//end testTheDocumentMessageCarriesTheFileAndTheCase()

	/**
	 * A document without a title never becomes XML.
	 *
	 * @return void
	 */
	public function testADocumentWithoutATitleIsRefusedBeforeAnyXml(): void {
		$document = self::DOCUMENT;
		unset($document['titel']);

		$this->expectException(StufZknTranslationException::class);
		$this->expectExceptionMessage('titel');
		$this->documentMessage(document: $document);
	}//end testADocumentWithoutATitleIsRefusedBeforeAnyXml()
}//end class
