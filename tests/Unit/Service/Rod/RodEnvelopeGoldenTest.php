<?php

/**
 * Golden test for the ROD envelopes.
 *
 * Pins the whole envelope of every berichtsoort, and every refusal, with the
 * tijdstipBericht masked.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/rod-adapter/spec.md#scenario-a-complete-inschrijving-translates-to-a-valid-envelope
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Rod;

use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\Rod\RodEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Every envelope matches tests/fixtures/rod/envelopes.json.
 */
class RodEnvelopeGoldenTest extends TestCase {

	/**
	 * The golden file.
	 */
	private const GOLDEN = __DIR__ . '/../../../fixtures/rod/envelopes.json';

	/**
	 * The cases: berichtsoort, kenmerk, payload.
	 *
	 * @return array<string,array{0:string,1:string,2:array}>
	 */
	private function cases(): array {
		$pupil  = ['persoonsgebondenNummer' => '123456782', 'persoonsgebondenNummerType' => 'onderwijsnummer'];
		$advies = $pupil + [
			'adviesvolgnummer' => 'ADV2026001', 'onderwijsaanbieder' => '100A200', 'onderwijslocatie' => '100X200',
			'vestigingscode' => '12AB00', 'adviesjaar' => '2026', 'advies1' => 'VMBO_KB', 'advies1Datum' => '2026-01-20',
			'advies2' => 'VMBO_KB_TM_VMBO_GL/TL', 'advies2Datum' => '2026-05-15',
		];
		$in     = $pupil + ['inschrijvingsdatum' => '2026-08-20', 'leerjaar' => 3, 'groep' => '3B'];

		return [
			'inschrijving' => ['inschrijving', 'k-1', $in],
			'inschrijving-opp' => ['inschrijving', 'k-2', $in + ['oppStartdatum' => '2026-09-01', 'oppEinddatum' => '0', 'extra' => 'x']],
			'inschrijving-bsn' => ['inschrijving', 'k-3', ['bsn' => '111222333', 'inschrijvingsdatum' => '2026-08-20', 'leerjaar' => '1', 'groep' => 'A&B']],
			'uitschrijving' => ['uitschrijving', 'k-4', $pupil + ['uitschrijvingsdatum' => '2026-12-01', 'redenUitschrijving' => 'verhuizing']],
			'verblijfsgegevens' => ['verblijfsgegevens', 'k-5', $pupil + ['ingangsdatum' => '2026-10-01', 'leerjaar' => '2', 'groep' => '2A', 'oppEinddatum' => '2027-01-01']],
			'schooladvies' => ['schooladvies', 'k-6', $advies],
			'schooladvies-sparse' => ['schooladvies', 'k-7', ['advies2' => null, 'advies2Datum' => null, 'onderwijsaanbieder' => null] + $advies],
			'unknown-kind' => ['herinschrijving', 'k-8', $in],
			'blank-kenmerk' => ['inschrijving', ' ', $in],
			'no-number' => ['inschrijving', 'k-9', ['inschrijvingsdatum' => '2026-08-20', 'leerjaar' => '1', 'groep' => '1']],
			'bad-number' => ['inschrijving', 'k-10', ['persoonsgebondenNummer' => '12345'] + $in],
			'bad-type' => ['inschrijving', 'k-11', ['persoonsgebondenNummerType' => 'paspoort'] + $in],
			'missing-field' => ['uitschrijving', 'k-12', $pupil + ['uitschrijvingsdatum' => '2026-12-01']],
			'placeholder' => ['inschrijving', 'k-13', ['groep' => '{{groep}}'] + $in],
		];
	}//end cases()

	/**
	 * Every case, rendered by the current code.
	 *
	 * @return array<string,string>
	 */
	private function envelopes(): array {
		$translator = new RodEnvelopeTranslator();
		$out        = [];
		foreach ($this->cases() as $name => [$kind, $kenmerk, $payload]) {
			try {
				$xml        = $translator->translate($kind, $kenmerk, $payload);
				$out[$name] = (string)preg_replace('#<tijdstipBericht>[^<]*</tijdstipBericht>#', '<tijdstipBericht>TIME</tijdstipBericht>', $xml);
			} catch (RodTranslationException $exception) {
				$out[$name] = 'refused: ' . $exception->getMessage();
			}
		}

		return $out;
	}//end envelopes()

	/**
	 * Every envelope matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryEnvelopeMatchesTheGoldenFile(): void {
		$actual = $this->envelopes();
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testEveryEnvelopeMatchesTheGoldenFile()
}//end class
