<?php

/**
 * Golden test for the StUF-ZKN InboundMessageTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\InboundMessageTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Pins every inbound bericht shape and every refusal against a golden file,
 * including the type error a bericht without its zaaktype or isRelevantVoor
 * block raises today.
 */
class InboundMessageGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/stuf-zkn/inbound-messages.json';

	/**
	 * One bericht.
	 *
	 * @param string $tag     zakLk01 or edcLk01 (or anything else).
	 * @param string $stuur   The stuurgegevens children.
	 * @param string $object  The object element, '' for none.
	 * @param bool   $wrapped Whether to wrap it in a SOAP envelope.
	 *
	 * @return string The XML.
	 */
	private static function bericht(string $tag, string $stuur, string $object, bool $wrapped=true): string {
		$ns      = 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:StUF="http://www.egem.nl/StUF/StUF0301" '
			.'xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"';
		$message = '<zkn:'.$tag.($wrapped === true ? '' : ' '.$ns).'><zkn:stuurgegevens>'.$stuur.'</zkn:stuurgegevens>'.$object.'</zkn:'.$tag.'>';
		if ($wrapped === false) {
			return $message;
		}

		return '<soap:Envelope '.$ns.'><soap:Body>'.$message.'</soap:Body></soap:Envelope>';

	}//end bericht()

	/**
	 * The berichten.
	 *
	 * @return array<string, string>
	 */
	private function berichten(): array {
		$stuur = '<StUF:zender><StUF:organisatie> Gemeente X </StUF:organisatie></StUF:zender><StUF:referentienummer> REF-1 </StUF:referentienummer>';
		$zaak  = '<zkn:identificatie> ZAAK-1 </zkn:identificatie><zkn:omschrijving>Kap</zkn:omschrijving><zkn:toelichting StUF:noValue="geenWaarde" xsi:nil="true"/>'
			.'<zkn:zaaktype><zkn:code>B1</zkn:code><zkn:omschrijving>Kapvergunning</zkn:omschrijving></zkn:zaaktype><zkn:registratiedatum>20260716</zkn:registratiedatum>'
			.'<zkn:startdatum>20260716</zkn:startdatum><zkn:einddatumGepland>20260801</zkn:einddatumGepland><zkn:einddatum></zkn:einddatum>'
			.'<zkn:archiefnominatie>J</zkn:archiefnominatie><zkn:betalingsIndicatie>N.v.t.</zkn:betalingsIndicatie>';
		$doc   = '<zkn:identificatie>DOC-1</zkn:identificatie><zkn:titel>Besluit</zkn:titel><zkn:formaat>application/pdf</zkn:formaat><zkn:language>nl</zkn:language>'
			.'<zkn:creatiedatum>20260716</zkn:creatiedatum><zkn:ontvangstdatum>20260717</zkn:ontvangstdatum><zkn:vertrouwelijkAanduiding>OPENBAAR</zkn:vertrouwelijkAanduiding>'
			.'<zkn:auteur>Jan</zkn:auteur><zkn:versie>1</zkn:versie><zkn:isRelevantVoor><zkn:gerelateerde><zkn:identificatie>ZAAK-1</zkn:identificatie></zkn:gerelateerde></zkn:isRelevantVoor>';
		$object = static fn (string $entity, string $kind, string $content): string => '<zkn:object StUF:entiteittype="'.$entity.'" StUF:verwerkingssoort="'.$kind.'">'.$content.'</zkn:object>';

		$out = [
			'zaak-T' => self::bericht('zakLk01', $stuur, $object('ZAK', 'T', $zaak)),
			'zaak-W' => self::bericht('zakLk01', $stuur, $object('ZAK', 'W', $zaak)),
			'zaak-I' => self::bericht('zakLk01', $stuur, $object('ZAK', 'I', $zaak)),
			'zaak-V-bare' => self::bericht('zakLk01', $stuur, $object('ZAK', ' V ', $zaak), false),
			'zaak-V-unwrapped' => '<Bericht xmlns:StUF="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
				.substr(self::bericht('zakLk01', $stuur, $object('ZAK', 'V', $zaak)), strlen('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:StUF="http://www.egem.nl/StUF/StUF0301" xmlns:zkn="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><soap:Body>'), -strlen('</soap:Body></soap:Envelope>'))
				.'</Bericht>',
			'zaak-minimal' => self::bericht('zakLk01', '<StUF:referentienummer>R</StUF:referentienummer>', $object('ZAK', 'T', '<zkn:identificatie>Z</zkn:identificatie>')),
			'document' => self::bericht('edcLk01', $stuur, $object('EDC', 'T', $doc)),
			'document-minimal' => self::bericht('edcLk01', $stuur, $object('EDC', 'W', '<zkn:identificatie>D</zkn:identificatie>')),
			'no-reference' => self::bericht('zakLk01', '<StUF:referentienummer> </StUF:referentienummer>', $object('ZAK', 'T', $zaak)),
			'no-object' => self::bericht('zakLk01', $stuur, ''),
			'bad-kind' => self::bericht('zakLk01', $stuur, $object('ZAK', 'X', $zaak)),
			'blank-id' => self::bericht('edcLk01', $stuur, $object('EDC', 'T', '<zkn:identificatie> </zkn:identificatie>')),
			'no-id' => self::bericht('zakLk01', $stuur, $object('ZAK', 'T', '<zkn:omschrijving>x</zkn:omschrijving>')),
			'unknown-type' => self::bericht('zakLa01', $stuur, $object('ZAK', 'T', $zaak)),
			'empty' => '',
			'malformed' => '<soap:Envelope>',
		];

		return $out;

	}//end berichten()

	/**
	 * Every bericht matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryBerichtMatchesTheGoldenFile(): void {
		$translator = new InboundMessageTranslator();
		$actual     = [];
		foreach ($this->berichten() as $name => $xml) {
			try {
				$actual[$name] = $translator->translate($xml);
			} catch (StufZknTranslationException $exception) {
				$actual[$name] = 'refused: '.$exception->getMessage();
			} catch (\TypeError $error) {
				$actual[$name] = 'type error';
			}
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryBerichtMatchesTheGoldenFile()
}//end class
