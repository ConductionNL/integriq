<?php

/**
 * Golden test pinning the five education export envelope translators.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\UwlrEduV;

use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Oso\OsoExportEnvelopeTranslator;
use OCA\Integriq\Service\UwlrEduV\BasispoortSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\EduVExportEnvelopeTranslator;
use OCA\Integriq\Service\UwlrEduV\EntreeContentSyncTranslator;
use OCA\Integriq\Service\UwlrEduV\UwlrExportEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the rendered XML (with the message timestamp masked) and every refusal of the
 * OSO, Basispoort, Entree content, Edu-V and UWLR export envelope translators.
 */
class ExportEnvelopeGoldenTest extends TestCase {

	/**
	 * Mask the `tijdstipBericht` value so the golden does not depend on the clock.
	 *
	 * @param string $xml The rendered envelope.
	 *
	 * @return string The envelope with the timestamp replaced by `TS`.
	 */
	private function masked(string $xml): string {
		$count = 0;
		$result = (string)preg_replace(
			'#<tijdstipBericht>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d</tijdstipBericht>#',
			'<tijdstipBericht>TS</tijdstipBericht>',
			$xml,
			-1,
			$count
		);
		$this->assertSame(1, $count);

		return $result;

	}//end masked()

	/**
	 * Run a callable and return the class and message of what it threw.
	 *
	 * @param callable $call The translation call.
	 *
	 * @return string `<class>: <message>`, or `none` when nothing was thrown.
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (\Exception $exception) {
			$this->assertNull($exception->getPrevious());
			return $exception::class . ': ' . $exception->getMessage();
		}

		return 'none';

	}//end refusal()

	/**
	 * The OSO export envelope, with categories, flags, data and attachment refs.
	 *
	 * @return void
	 */
	public function testOsoEnvelope(): void {
		$xml = (new OsoExportEnvelopeTranslator())->translate(
			'k-1',
			[
				'learnerEckId' => 'eck & <1>',
				'targetSchoolBrin' => 12,
				'categories' => [
					['category' => 'zorg', 'included' => true, 'data' => ['a' => 'b"c']],
					['category' => 'taal', 'included' => 'yes'],
					['included' => false, 'data' => []],
				],
				'attachmentRefs' => ['att-1', 7],
			]
		);

		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<OsoOverstapdossier><stuurgegevens><kenmerk>k-1</kenmerk><tijdstipBericht>TS</tijdstipBericht></stuurgegevens>'
			. '<body><learnerEckId>eck &amp; &lt;1&gt;</learnerEckId><targetSchoolBrin>12</targetSchoolBrin>'
			. '<categories><category><name>zorg</name><included>true</included><data>{"a":"b\\"c"}</data></category>'
			. '<category><name>taal</name><included>false</included><data>[]</data></category>'
			. '<category><name/><included>false</included><data>[]</data></category></categories>'
			. '<attachmentRefs><ref>att-1</ref><ref>7</ref></attachmentRefs></body></OsoOverstapdossier>' . "\n",
			$this->masked($xml)
		);

		$noRefs = (new OsoExportEnvelopeTranslator())->translate(
			'k-2',
			['learnerEckId' => 'e', 'targetSchoolBrin' => 'b', 'categories' => [['category' => 'c']], 'attachmentRefs' => []]
		);
		$this->assertStringNotContainsString('attachmentRefs', $noRefs);

		$scalarCategory = (new OsoExportEnvelopeTranslator())->translate(
			'k-3',
			['learnerEckId' => 'e', 'targetSchoolBrin' => 'b', 'categories' => ['plain']]
		);
		$this->assertStringContainsString(
			'<categories><category><name/><included>false</included><data>[]</data></category></categories></body>',
			$scalarCategory
		);

	}//end testOsoEnvelope()

	/**
	 * Every OSO refusal, in the order the checks run.
	 *
	 * @return void
	 */
	public function testOsoRefusals(): void {
		$translator = new OsoExportEnvelopeTranslator();
		$valid = ['learnerEckId' => 'e', 'targetSchoolBrin' => 'b', 'categories' => [['category' => 'c']]];
		$oso = OsoTranslationException::class . ': ';

		$this->assertSame(
			$oso . 'An OSO export requires a non-empty kenmerk (correlation id).',
			$this->refusal(fn () => $translator->translate(' ', []))
		);
		$this->assertSame(
			$oso . 'Required field "learnerEckId" is missing or empty for an OSO export — refusing to build an envelope with unresolved data.',
			$this->refusal(fn () => $translator->translate('k', ['targetSchoolBrin' => ' ']))
		);
		$this->assertSame(
			$oso . 'Required field "targetSchoolBrin" is missing or empty for an OSO export — refusing to build an envelope with unresolved data.',
			$this->refusal(fn () => $translator->translate('k', ['learnerEckId' => 'e', 'targetSchoolBrin' => '  ']))
		);
		$this->assertSame(
			$oso . 'Required field "categories" is missing or empty — an OSO export needs at least one category.',
			$this->refusal(fn () => $translator->translate('k', ['learnerEckId' => 'e', 'targetSchoolBrin' => 'b', 'categories' => 'x']))
		);
		$this->assertSame(
			$oso . 'Required field "categories" is missing or empty — an OSO export needs at least one category.',
			$this->refusal(fn () => $translator->translate('k', ['learnerEckId' => 'e', 'targetSchoolBrin' => 'b']))
		);
		$this->assertSame(
			$oso . 'Rendered envelope still contains an unresolved template marker — refusing to send.',
			$this->refusal(fn () => $translator->translate('k', array_merge($valid, ['learnerEckId' => '{{ eck }}'])))
		);

	}//end testOsoRefusals()

	/**
	 * The Basispoort and Entree content sync envelopes.
	 *
	 * @return void
	 */
	public function testSyncEnvelopes(): void {
		$payload = ['eckId' => 'e<1>', 'schoolBrin' => 12, 'ssoAudience' => 'aud', 'ignored' => 'x'];

		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<BasispoortSync><stuurgegevens><kenmerk>k-1</kenmerk><tijdstipBericht>TS</tijdstipBericht></stuurgegevens>'
			. '<body><eckId>e&lt;1&gt;</eckId><schoolBrin>12</schoolBrin><ssoAudience>aud</ssoAudience></body></BasispoortSync>' . "\n",
			$this->masked((new BasispoortSyncTranslator())->translate('k-1', $payload))
		);
		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<EntreeContentSync><stuurgegevens><kenmerk>k-1</kenmerk><tijdstipBericht>TS</tijdstipBericht></stuurgegevens>'
			. '<body><eckId>e&lt;1&gt;</eckId><schoolBrin>12</schoolBrin><ssoAudience>aud</ssoAudience></body></EntreeContentSync>' . "\n",
			$this->masked((new EntreeContentSyncTranslator())->translate('k-1', $payload))
		);

	}//end testSyncEnvelopes()

	/**
	 * Every Basispoort and Entree content refusal.
	 *
	 * @return void
	 */
	public function testSyncRefusals(): void {
		$uwlr = UwlrEduVTranslationException::class . ': ';
		$cases = [
			'a Basispoort sync' => [new BasispoortSyncTranslator(), 'A Basispoort sync'],
			'an Entree content sync' => [new EntreeContentSyncTranslator(), 'An Entree content sync'],
		];
		foreach ($cases as $label => [$translator, $article]) {
			$this->assertSame(
				$uwlr . $article . ' requires a non-empty kenmerk (correlation id).',
				$this->refusal(fn () => $translator->translate('', []))
			);
			$this->assertSame(
				$uwlr . 'Required field "ssoAudience" is missing or empty for ' . $label . ' — refusing to build an envelope with unresolved data.',
				$this->refusal(fn () => $translator->translate('k', ['eckId' => 'e', 'schoolBrin' => 'b']))
			);
			$this->assertSame(
				$uwlr . 'Rendered envelope still contains an unresolved template marker — refusing to send.',
				$this->refusal(fn () => $translator->translate('k', ['eckId' => '%%UNRESOLVED%%', 'schoolBrin' => 'b', 'ssoAudience' => 'a']))
			);
		}

	}//end testSyncRefusals()

	/**
	 * The Edu-V and UWLR export envelopes carry their data service or subtype in the stuurgegevens.
	 *
	 * @return void
	 */
	public function testExportEnvelopes(): void {
		$payload = ['eckId' => 'e', 'schoolBrin' => 'b&'];

		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<EduVExport><stuurgegevens><kenmerk>k-1</kenmerk><targetSchema>EduV:Onderwijsgroepen</targetSchema>'
			. '<tijdstipBericht>TS</tijdstipBericht></stuurgegevens>'
			. '<body><eckId>e</eckId><schoolBrin>b&amp;</schoolBrin></body></EduVExport>' . "\n",
			$this->masked((new EduVExportEnvelopeTranslator())->translate('k-1', 'onderwijsgroepen', $payload))
		);
		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<UwlrExport><stuurgegevens><kenmerk>k-1</kenmerk><subtype>teacher</subtype>'
			. '<tijdstipBericht>TS</tijdstipBericht></stuurgegevens>'
			. '<body><eckId>e</eckId><schoolBrin>b&amp;</schoolBrin></body></UwlrExport>' . "\n",
			$this->masked((new UwlrExportEnvelopeTranslator())->translate('k-1', 'teacher', $payload))
		);

	}//end testExportEnvelopes()

	/**
	 * Every Edu-V and UWLR refusal, in the order the checks run.
	 *
	 * @return void
	 */
	public function testExportRefusals(): void {
		$uwlr = UwlrEduVTranslationException::class . ': ';
		$eduV = new EduVExportEnvelopeTranslator();
		$uwlrTranslator = new UwlrExportEnvelopeTranslator();
		$unresolved = ['eckId' => '{{x}}', 'schoolBrin' => 'b'];

		$this->assertSame($uwlr . 'An Edu-V export requires a non-empty kenmerk (correlation id).', $this->refusal(fn () => $eduV->translate('', 'nope', [])));
		$this->assertSame(
			$uwlr . 'Unknown Edu-V data service "nope" — expected one of: onderwijsdeelnemers, onderwijsgroepen, onderwijsmedewerkers.',
			$this->refusal(fn () => $eduV->translate('k', 'nope', []))
		);
		$this->assertSame(
			$uwlr . 'Required field "eckId" is missing or empty for an Edu-V export — refusing to build an envelope with unresolved data.',
			$this->refusal(fn () => $eduV->translate('k', 'onderwijsdeelnemers', ['eckId' => null]))
		);
		$this->assertSame(
			$uwlr . 'Rendered envelope still contains an unresolved template marker — refusing to send.',
			$this->refusal(fn () => $eduV->translate('k', 'onderwijsdeelnemers', $unresolved))
		);
		$this->assertSame($uwlr . 'A UWLR export requires a non-empty kenmerk (correlation id).', $this->refusal(fn () => $uwlrTranslator->translate(' ', 'nope', [])));
		$this->assertSame(
			$uwlr . 'Unknown UWLR subtype "nope" — expected one of: pupil, group, teacher.',
			$this->refusal(fn () => $uwlrTranslator->translate('k', 'nope', []))
		);
		$this->assertSame(
			$uwlr . 'Required field "schoolBrin" is missing or empty for a UWLR export — refusing to build an envelope with unresolved data.',
			$this->refusal(fn () => $uwlrTranslator->translate('k', 'pupil', ['eckId' => 'e']))
		);
		$this->assertSame(
			$uwlr . 'Rendered envelope still contains an unresolved template marker — refusing to send.',
			$this->refusal(fn () => $uwlrTranslator->translate('k', 'group', $unresolved))
		);

	}//end testExportRefusals()
}//end class
