<?php

/**
 * Integriq — personal data masking of logged bodies.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Security;

use OCA\Integriq\Service\Security\PersonalDataMasker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * JSON, XML, query strings and arrays, matched by normalised name.
 */
#[CoversClass(PersonalDataMasker::class)]
class PersonalDataMaskerTest extends TestCase {

	/**
	 * The masker under test.
	 *
	 * @var PersonalDataMasker
	 */
	private PersonalDataMasker $masker;

	/**
	 * Build a masker over a short list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->masker = new PersonalDataMasker(names: ['bsn', 'inp.bsn', 'geslachtsnaam', 'address', 'e-mail', '', '---']);
	}//end setUp()

	/**
	 * Spellings that normalise to one name.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function spellings(): array {
		return [
			'namespace prefix and dot' => ['BG:inp.bsn', 'inpbsn'],
			'camel case' => ['inpBsn', 'inpbsn'],
			'hyphen' => ['E-Mail', 'email'],
			'underscore' => ['date_of_birth', 'dateofbirth'],
			'nothing left' => ['--', ''],
		];
	}//end spellings()

	/**
	 * A name is lower-cased, loses its prefix and every non-alphanumeric character.
	 *
	 * @param string $name     The spelling.
	 * @param string $expected The normalised name.
	 *
	 * @return void
	 */
	#[DataProvider('spellings')]
	public function testNormalise(string $name, string $expected): void {
		$this->assertSame($expected, PersonalDataMasker::normalise(name: $name));
	}//end testNormalise()

	/**
	 * Names match exactly after normalisation, never as a substring.
	 *
	 * @return void
	 */
	public function testNamesMatchExactlyNotAsSubstring(): void {
		$this->assertTrue($this->masker->isPersonalDataName(name: 'BSN'));
		$this->assertTrue($this->masker->isPersonalDataName(name: 'StUF:inp.bsn'));
		$this->assertTrue($this->masker->isPersonalDataName(name: 'email'));
		$this->assertFalse($this->masker->isPersonalDataName(name: 'bsnVerificatie'));
		$this->assertFalse($this->masker->isPersonalDataName(name: 'zaaktypeNaam'));
		$this->assertFalse($this->masker->isPersonalDataName(name: ''));
	}//end testNamesMatchExactlyNotAsSubstring()

	/**
	 * A JSON body is masked at any depth, an object value becomes one placeholder, and the rest is kept.
	 *
	 * @return void
	 */
	public function testJsonIsMaskedAtAnyDepth(): void {
		$body = '{"zaak":{"bsn":"999993653","address":{"street":"Dorpsstraat"},"omschrijving":"Melding/1"},"items":[{"e_mail":"a@b.nl"}],"leeg":{},"null":null,"geslachtsnaam":null}';

		$this->assertSame(
			'{"zaak":{"bsn":"[afgeschermd]","address":"[afgeschermd]","omschrijving":"Melding/1"},"items":[{"e_mail":"[afgeschermd]"}],"leeg":{},"null":null,"geslachtsnaam":null}',
			$this->masker->maskBody(body: $body)
		);
	}//end testJsonIsMaskedAtAnyDepth()

	/**
	 * JSON without personal data, or that is not JSON, is returned byte for byte.
	 *
	 * @return void
	 */
	public function testJsonWithoutPersonalDataKeepsItsLayout(): void {
		$pretty = "{\n  \"zaaktype\": \"https://example.invalid/1\"\n}";

		$this->assertSame($pretty, $this->masker->maskBody(body: $pretty));
		$this->assertSame('{not json', $this->masker->maskBody(body: '{not json'));
	}//end testJsonWithoutPersonalDataKeepsItsLayout()

	/**
	 * XML elements holding personal data are masked; attributes, namespaces and other elements stay.
	 *
	 * @return void
	 */
	public function testXmlTextElementsAreMasked(): void {
		$body = '<?xml version="1.0"?><BG:npsLk01 xmlns:BG="urn:bg"><BG:inp.bsn StUF:noValue="x">999993653</BG:inp.bsn>'
			. '<BG:geslachtsnaam>Jansen</BG:geslachtsnaam><BG:geslachtsnaam xsi:nil="true"/><BG:voornamen>Piet</BG:voornamen>'
			. '<bsn> </bsn></BG:npsLk01>';

		$this->assertSame(
			'<?xml version="1.0"?><BG:npsLk01 xmlns:BG="urn:bg"><BG:inp.bsn StUF:noValue="x">[afgeschermd]</BG:inp.bsn>'
			. '<BG:geslachtsnaam>[afgeschermd]</BG:geslachtsnaam><BG:geslachtsnaam xsi:nil="true"/><BG:voornamen>Piet</BG:voornamen>'
			. '<bsn> </bsn></BG:npsLk01>',
			$this->masker->maskBody(body: $body)
		);
	}//end testXmlTextElementsAreMasked()

	/**
	 * A URL's personal data query parameters are masked; a URL without them is unchanged.
	 *
	 * @return void
	 */
	public function testUrlQueryIsMasked(): void {
		$this->assertSame(
			'https://brp.example.invalid/personen?bsn=%5Bafgeschermd%5D&fields=naam',
			$this->masker->maskBody(body: 'https://brp.example.invalid/personen?bsn=999993653&fields=naam')
		);
		$this->assertSame('https://x.invalid/a?fields=naam', $this->masker->maskBody(body: 'https://x.invalid/a?fields=naam'));
		$this->assertSame('what? bsn=1', $this->masker->maskBody(body: 'what? bsn=1'));
		$this->assertSame('plain text', $this->masker->maskBody(body: 'plain text'));
	}//end testUrlQueryIsMasked()

	/**
	 * An array is walked by key; strings in it are read as bodies; other values stay.
	 *
	 * @return void
	 */
	public function testArraysAreWalked(): void {
		$masked = $this->masker->mask(
			value: [
				'url' => 'https://x.invalid/a?bsn=1',
				'json' => ['bsn' => 123, 'count' => 2, 'list' => ['geslachtsnaam' => 'Jansen']],
				'body' => '{"bsn":"1"}',
				'GESLACHTSNAAM' => ['Jansen'],
				7 => true,
			]
		);

		$this->assertSame(
			[
				'url' => 'https://x.invalid/a?bsn=%5Bafgeschermd%5D',
				'json' => ['bsn' => '[afgeschermd]', 'count' => 2, 'list' => ['geslachtsnaam' => '[afgeschermd]']],
				'body' => '{"bsn":"[afgeschermd]"}',
				'GESLACHTSNAAM' => '[afgeschermd]',
				7 => true,
			],
			$masked
		);
		$this->assertSame(42, $this->masker->mask(value: 42));
	}//end testArraysAreWalked()

	/**
	 * A masker without names leaves every body alone.
	 *
	 * @return void
	 */
	public function testAnEmptyListMasksNothing(): void {
		$this->assertSame('{"bsn":"1"}', (new PersonalDataMasker(names: []))->maskBody(body: '{"bsn":"1"}'));
	}//end testAnEmptyListMasksNothing()
}//end class
