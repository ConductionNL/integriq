<?php

/**
 * Characterisation test for StufProviderOperationMapper::documentOperation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Service\StufZkn\StufProviderOperationMapper;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Golden record of the document operations.
 */
class StufProviderDocumentOperationGoldenTest extends TestCase {

	/**
	 * An edcLk01 with the given objects.
	 *
	 * @param string ...$objects The object element bodies.
	 *
	 * @return SimpleXMLElement
	 */
	private function message(string ...$objects): SimpleXMLElement {
		$body = '';
		foreach ($objects as $object) {
			$body .= '<ZKN:object>' . $object . '</ZKN:object>';
		}

		return new SimpleXMLElement(
			'<ZKN:edcLk01 xmlns:ZKN="http://www.egem.nl/StUF/sector/zkn/0310" xmlns:StUF="http://www.egem.nl/StUF/StUF0301">'
			. '<ZKN:stuurgegevens/>' . $body . '</ZKN:edcLk01>'
		);
	}//end message()

	/**
	 * The recorded operations.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$full = '<ZKN:identificatie>DOC-1</ZKN:identificatie>'
			. '<ZKN:dct.omschrijving>Besluit</ZKN:dct.omschrijving>'
			. '<ZKN:creatiedatum>20260102</ZKN:creatiedatum>'
			. '<ZKN:ontvangstdatum>20260203</ZKN:ontvangstdatum>'
			. '<ZKN:titel>Brief</ZKN:titel>'
			. '<ZKN:beschrijving>Een brief</ZKN:beschrijving>'
			. '<ZKN:formaat>pdf</ZKN:formaat>'
			. '<ZKN:taal>nld</ZKN:taal>'
			. '<ZKN:vertrouwelijkAanduiding>ZAAKVERTROUWELIJK BEPERKT</ZKN:vertrouwelijkAanduiding>'
			. '<ZKN:auteur>Jan</ZKN:auteur>'
			. '<ZKN:inhoud StUF:bestandsnaam="brief.pdf">' . chunk_split(base64_encode('hello document'), 8, "\n ") . '</ZKN:inhoud>'
			. '<ZKN:isRelevantVoor><ZKN:gerelateerde><ZKN:identificatie>ZAAK-9</ZKN:identificatie></ZKN:gerelateerde></ZKN:isRelevantVoor>';
		$cases = [
			'full create' => [$this->message($full), 'T'],
			'update is unsupported' => [$this->message($full), 'W'],
			'no object' => [$this->message(), 'T'],
			'last object wins' => [$this->message('<ZKN:titel>Oud</ZKN:titel>', '<ZKN:titel>Nieuw</ZKN:titel><ZKN:creatiedatum>2026</ZKN:creatiedatum>'), 'T'],
			'invalid content' => [$this->message('<ZKN:inhoud>not*base64!</ZKN:inhoud>'), 'T'],
			'empty content' => [$this->message('<ZKN:inhoud StUF:bestandsnaam="leeg.txt">  </ZKN:inhoud>'), 'T'],
			'bare object' => [$this->message(''), 'D'],
		];

		$mapper = new StufProviderOperationMapper();
		$record = [];
		foreach ($cases as $name => [$message, $mutatiesoort]) {
			$operation = $mapper->documentOperation($message, $mutatiesoort);
			if (isset($operation['content']) === true) {
				$operation['content'] = 'base64:' . base64_encode($operation['content']);
			}

			$record[$name] = $operation;
		}

		$path = __DIR__ . '/../../../fixtures/stuf-provider/document-operations.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
