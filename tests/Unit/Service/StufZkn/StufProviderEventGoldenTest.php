<?php

/**
 * Golden test for StufProviderRequestReader::event.
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

use OCA\Integriq\Event\StufCaseMutationEvent;
use OCA\Integriq\Event\StufCaseQueryEvent;
use OCA\Integriq\Event\StufDocumentMutationEvent;
use OCA\Integriq\Event\StufPersonQueryEvent;
use OCA\Integriq\Service\StufZkn\StufProviderRequestReader;
use PHPUnit\Framework\TestCase;

/**
 * Pins the typed event built for every provider message kind against a golden file.
 */
class StufProviderEventGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/stuf-provider/events.json';

	/**
	 * Every message's event matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryEventMatchesTheGoldenFile(): void {
		$ns   = 'xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:StUF="http://www.egem.nl/StUF/StUF0301" xmlns:BG="http://www.egem.nl/StUF/sector/bg/0310"';
		$zaak = '<ZKN:identificatie>Z-1</ZKN:identificatie><ZKN:omschrijving> Kap </ZKN:omschrijving><ZKN:toelichting></ZKN:toelichting>'
			.'<ZKN:isVan><ZKN:gerelateerde><ZKN:code>B1</ZKN:code><ZKN:omschrijving>Kapvergunning</ZKN:omschrijving></ZKN:gerelateerde></ZKN:isVan>';
		$messages = [
			'case-query' => '<ZKN:zakLv01 '.$ns.'><ZKN:gelijk><ZKN:identificatie>Z-1</ZKN:identificatie><ZKN:bronorganisatie>123</ZKN:bronorganisatie></ZKN:gelijk></ZKN:zakLv01>',
			'case-query-empty' => '<ZKN:zakLv01 '.$ns.'/>',
			'person-query' => '<BG:npsLv01 '.$ns.'><BG:gelijk><BG:inp.bsn> 111222333 </BG:inp.bsn></BG:gelijk></BG:npsLv01>',
			'person-query-bsn' => '<BG:npsLv01 '.$ns.'><BG:gelijk><BG:bsn>999</BG:bsn></BG:gelijk></BG:npsLv01>',
			'case-create' => '<ZKN:zakLk01 '.$ns.'><ZKN:parameters><StUF:mutatiesoort>T</StUF:mutatiesoort></ZKN:parameters><ZKN:object StUF:verwerkingssoort="T">'.$zaak.'</ZKN:object></ZKN:zakLk01>',
			'case-update-w' => '<ZKN:zakLk01 '.$ns.'><ZKN:object StUF:verwerkingssoort="W"><ZKN:identificatie>Z-1</ZKN:identificatie><ZKN:omschrijving>oud</ZKN:omschrijving></ZKN:object>'
				.'<ZKN:object StUF:verwerkingssoort="W">'.$zaak.'</ZKN:object></ZKN:zakLk01>',
			'case-no-object' => '<ZKN:zakLk01 '.$ns.'><ZKN:parameters><StUF:mutatiesoort>V</StUF:mutatiesoort></ZKN:parameters></ZKN:zakLk01>',
			'document' => '<ZKN:edcLk01 '.$ns.'><ZKN:parameters><StUF:mutatiesoort>T</StUF:mutatiesoort></ZKN:parameters><ZKN:object StUF:verwerkingssoort="T">'
				.'<ZKN:identificatie>D-1</ZKN:identificatie><ZKN:titel>Besluit</ZKN:titel><ZKN:inhoud>QUJD</ZKN:inhoud>'
				.'<ZKN:isRelevantVoor><ZKN:gerelateerde><ZKN:identificatie>Z-1</ZKN:identificatie></ZKN:gerelateerde></ZKN:isRelevantVoor></ZKN:object></ZKN:edcLk01>',
			'document-no-relation' => '<ZKN:edcLk01 '.$ns.'><ZKN:object StUF:verwerkingssoort="W"><ZKN:identificatie>D-2</ZKN:identificatie></ZKN:object></ZKN:edcLk01>',
		];

		$reader = new StufProviderRequestReader();
		$actual = [];
		foreach ($messages as $name => $xml) {
			$event  = $reader->event(new \SimpleXMLElement($xml), 'source-1', 'REF-1');
			$record = ['class' => (new \ReflectionClass($event))->getShortName(), 'sourceId' => $event->getSourceId(), 'referentienummer' => $event->getReferentienummer()];
			if ($event instanceof StufCaseQueryEvent) {
				$record['criteria'] = $event->getCriteria();
			} elseif ($event instanceof StufPersonQueryEvent) {
				$record['bsn'] = $event->getBsn();
			} elseif ($event instanceof StufDocumentMutationEvent || $event instanceof StufCaseMutationEvent) {
				$record += ['mutatiesoort' => $event->getMutatiesoort(), 'fields' => $event->getFields(), 'operation' => $event->getOperation()];
			}

			$actual[$name] = $record;
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryEventMatchesTheGoldenFile()
}//end class
