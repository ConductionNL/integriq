<?php

/**
 * Golden test for the iWmo/iJw InboundReturnTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\IwmoIjw;

use OCA\Integriq\Exception\IwmoIjwTranslationException;
use OCA\Integriq\Service\IwmoIjw\InboundReturnTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Pins the status update of every retour code and every refusal against a golden file.
 */
class InboundReturnGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/iwmo/retour-updates.json';

	/**
	 * One retour.
	 *
	 * @param string $code The berichtcode.
	 * @param string $body The body children.
	 *
	 * @return string The XML.
	 */
	private static function retour(string $code, string $body): string {
		return '<Retour><stuurgegevens><berichtcode>'.$code.'</berichtcode><kenmerk> K-1 </kenmerk></stuurgegevens><body>'.$body.'</body></Retour>';

	}//end retour()

	/**
	 * Every retour matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryRetourMatchesTheGoldenFile(): void {
		$all     = '<resultaat> Akkoord </resultaat><betaalstatus>AKKOORD</betaalstatus><startdatumWerkelijk> 2026-01-01 </startdatumWerkelijk>'
			.'<einddatumWerkelijk>2026-02-01</einddatumWerkelijk><betalingReferentie>PAY-1</betalingReferentie>';
		$retours = [];
		foreach (['WMO302', 'JW304', 'WMO305', 'WMO306', 'JW307', 'WMO308', 'WMO322'] as $code) {
			$retours[$code.'-all'] = self::retour($code, $all);
			$retours[$code.'-none'] = self::retour($code, '<resultaat>afgewezen</resultaat><betaalstatus> </betaalstatus><startdatumWerkelijk> </startdatumWerkelijk>');
		}

		$retours['no-body'] = '<Retour><stuurgegevens><berichtcode>WMO305</berichtcode><kenmerk>K</kenmerk></stuurgegevens></Retour>';
		$retours['no-code'] = '<Retour><stuurgegevens><kenmerk>K</kenmerk></stuurgegevens></Retour>';
		$retours['no-kenmerk'] = '<Retour><stuurgegevens><berichtcode>WMO305</berichtcode><kenmerk> </kenmerk></stuurgegevens></Retour>';
		$retours['unknown-code'] = self::retour('WMO301', $all);
		$retours['letters-only'] = self::retour('retour', $all);
		$retours['empty'] = ' ';
		$retours['malformed'] = '<Retour>';

		$translator = new InboundReturnTranslator();
		$actual     = [];
		foreach ($retours as $name => $xml) {
			try {
				$actual[$name] = $translator->translate($xml);
			} catch (IwmoIjwTranslationException $exception) {
				$actual[$name] = 'refused: '.$exception->getMessage();
			}
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryRetourMatchesTheGoldenFile()
}//end class
