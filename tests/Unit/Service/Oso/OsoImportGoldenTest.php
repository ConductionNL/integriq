<?php

/**
 * Golden test for OsoImportTranslator::translate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Oso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Oso;

use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Oso\OsoImportTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the import of every dossier shape and every refusal against a golden file.
 */
class OsoImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/oso/imports.json';

	/**
	 * The dossiers.
	 *
	 * @return array<string, string>
	 */
	private function dossiers(): array {
		$head = '<stuurgegevens><afzenderBrin> 12AB </afzenderBrin></stuurgegevens>';
		return [
			'full' => '<Dossier>'.$head.'<body><leerlingEckId> eck-1 </leerlingEckId><voornamen> Jan </voornamen><achternaam>Jansen</achternaam>'
				.'<geboortedatum>2015-01-02</geboortedatum><categories><category><name>zorg</name><included>true</included><data>{"a":1}</data></category>'
				.'<category><name>toets</name><included>TRUE</included><data>not json</data></category><category/></categories>'
				.'<attachmentRefs><ref>r-1</ref><ref> r-2 </ref></attachmentRefs></body></Dossier>',
			'minimal' => '<Dossier>'.$head.'<body><leerlingEckId>eck-2</leerlingEckId></body></Dossier>',
			'name-only' => '<Dossier>'.$head.'<body><leerlingEckId>eck-3</leerlingEckId><voornamen>Piet</voornamen><achternaam> </achternaam></body></Dossier>',
			'empty-lists' => '<Dossier>'.$head.'<body><leerlingEckId>eck-4</leerlingEckId><categories/><attachmentRefs/></body></Dossier>',
			'no-brin' => '<Dossier><stuurgegevens><afzenderBrin> </afzenderBrin></stuurgegevens><body><leerlingEckId>e</leerlingEckId></body></Dossier>',
			'no-body' => '<Dossier>'.$head.'</Dossier>',
			'no-eck' => '<Dossier>'.$head.'<body><voornamen>Jan</voornamen></body></Dossier>',
			'empty' => '   ',
			'malformed' => '<Dossier>',
		];

	}//end dossiers()

	/**
	 * Every dossier matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryDossierMatchesTheGoldenFile(): void {
		$translator = new OsoImportTranslator();
		$actual     = [];
		foreach ($this->dossiers() as $name => $xml) {
			try {
				$actual[$name] = $translator->translate($xml);
			} catch (OsoTranslationException $exception) {
				$actual[$name] = 'refused: '.$exception->getMessage();
			}
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryDossierMatchesTheGoldenFile()
}//end class
