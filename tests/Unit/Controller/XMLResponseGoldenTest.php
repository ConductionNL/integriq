<?php

/**
 * Golden test for XMLResponse::arrayToXml() over every shape the element
 * builder distinguishes.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\XMLResponse;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact XML one mixed document renders to.
 */
class XMLResponseGoldenTest extends TestCase {

	/**
	 * Attributes, text, lists of arrays, lists of scalars, numeric and @ keys,
	 * a non-array @attributes, objects, booleans and nulls render as before.
	 *
	 * @return void
	 */
	public function testMixedDocumentRendersAsBefore(): void {
		$data = [
			'@root' => 'doc',
			'@attributes' => ['id' => 7, 'lang' => 'nl'],
			'#text' => 'intro &amp; more',
			'zaak' => [
				['@attributes' => ['nr' => 1], 'titel' => 'A'],
				['titel' => 'B', '@nested' => ['x' => 'y']],
				'loose',
			],
			'tags' => ['een', 'twee'],
			'leeg' => [],
			3 => 'drie',
			'@attr' => 'at',
			'flag' => true,
			'off' => false,
			'niets' => null,
			'getal' => 1.5,
			'object' => new \ArrayObject([]),
			'tekst' => new class {
				/**
				 * The string form.
				 *
				 * @return string
				 */
				public function __toString(): string {
					return 'als tekst';
				}
			},
			'geen' => ['@attributes' => 'plain', '#text' => 5],
		];

		$xml = (new XMLResponse([]))->arrayToXml($data);

		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<doc id="7" lang="nl">intro &amp; more'
			. '<zaak nr="1"><titel>A</titel></zaak><zaak><titel>B</titel><nested><x>y</x></nested></zaak><zaak>loose</zaak>'
			. '<tags><item0>een</item0><item1>twee</item1></tags><leeg /><item3>drie</item3><attr>at</attr>'
			. '<flag>1</flag><off></off><niets></niets><getal>1.5</getal>'
			. '<object>[Object of class ArrayObject]</object><tekst>als tekst</tekst>'
			. '<geen>5<attributes>plain</attributes></geen></doc>' . "\n",
			$xml
		);

	}//end testMixedDocumentRendersAsBefore()
}//end class
