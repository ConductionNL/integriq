<?php

/**
 * Golden test for VerzuimloketEnvelopeTranslator::translate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Verzuimloket
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Verzuimloket;

use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEnvelopeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Pins every melding envelope and every refusal against a golden file, with
 * the tijdstipBericht masked so the golden does not depend on the clock.
 */
class VerzuimloketEnvelopeGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/verzuimloket/envelopes.json';

	/**
	 * Every melding matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryMeldingMatchesTheGoldenFile(): void {
		$window = ['bsn' => '111222333', 'windowStart' => '2026-01-01', 'windowEnd' => '2026-01-31', 'metricValue' => 16];
		$cases  = [
			'eerste' => ['eerste-melding', 'k-1', $window],
			'eerste-optional' => ['eerste-melding', 'k-2', $window + ['breachingRecords' => [['dag' => '2026-01-02']], 'interventions' => [], 'extra' => 'x']],
			'herhaal' => ['herhaalmelding', 'k-3', ['metricValue' => 0, 'interventions' => ['gesprek & brief']] + $window],
			'lrv' => ['langdurig-relatief-verzuim', 'k-4', ['bsn' => '111222333', 'startDate' => '2026-02-01', 'breachingRecords' => '<x>']],
			'unknown' => ['tweede-melding', 'k-5', $window],
			'blank-kenmerk' => ['eerste-melding', '  ', $window],
			'missing' => ['langdurig-relatief-verzuim', 'k-6', ['bsn' => '111222333', 'startDate' => ' ']],
			'null-field' => ['eerste-melding', 'k-7', ['windowEnd' => null] + $window],
			'placeholder' => ['eerste-melding', 'k-8', ['bsn' => '{{bsn}}'] + $window],
		];

		$translator = new VerzuimloketEnvelopeTranslator();
		$actual     = [];
		foreach ($cases as $name => [$kind, $kenmerk, $payload]) {
			try {
				$xml           = $translator->translate($kind, $kenmerk, $payload);
				$actual[$name] = (string) preg_replace('#<tijdstipBericht>[^<]*</tijdstipBericht>#', '<tijdstipBericht>TIME</tijdstipBericht>', $xml);
			} catch (VerzuimloketTranslationException $exception) {
				$actual[$name] = 'refused: '.$exception->getMessage();
			}
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryMeldingMatchesTheGoldenFile()
}//end class
