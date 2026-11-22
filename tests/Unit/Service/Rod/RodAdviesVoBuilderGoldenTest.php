<?php

/**
 * Golden test for RodAdviesVoBuilder::append.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Rod
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Rod;

use DOMDocument;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\Rod\RodAdviesVoBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins the AanleverenAdviesVO request body for every payload shape and every refusal.
 */
class RodAdviesVoBuilderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/rod/advies-vo.json';

	/**
	 * Every payload matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryPayloadMatchesTheGoldenFile(): void {
		$base  = ['adviesvolgnummer' => 1, 'vestigingscode' => 'AB12CD', 'adviesjaar' => 2026];
		$cases = [
			'both' => $base + ['advies1' => 'HAVO_VWO', 'advies1Datum' => '2026-02-01', 'advies2' => 'VWO', 'advies2Datum' => '2026-05-01', 'onderwijsaanbieder' => '123A456', 'onderwijslocatie' => '123X456'],
			'first' => $base + ['advies1' => 'VMBO_GL_TL', 'advies1Datum' => '2026-02-01', 'onderwijsaanbieder' => '', 'onderwijslocatie' => null],
			'second' => $base + ['advies2' => 'HAVO', 'advies2Datum' => '2026-05-01', 'onderwijslocatie' => '999X001'],
			'none' => $base,
			'bad-aanbieder' => $base + ['advies1' => 'VWO', 'advies1Datum' => '2026-02-01', 'onderwijsaanbieder' => '12AB'],
			'bad-advies' => $base + ['advies1' => 'havo & vwo', 'advies1Datum' => '2026-02-01'],
		];

		$builder = new RodAdviesVoBuilder();
		$actual  = [];
		foreach ($cases as $name => $payload) {
			$document = new DOMDocument('1.0', 'UTF-8');
			$body     = $document->createElement('body');
			$document->appendChild($body);
			$number = $document->createElement('persoonsgebondenNummer');
			$number->appendChild($document->createElement('bsn', '111222333'));
			try {
				$builder->append($document, $body, $payload, $number);
				$actual[$name] = (string) $document->saveXML($body);
			} catch (RodTranslationException $exception) {
				$actual[$name] = 'refused: '.$exception->getMessage();
			}
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryPayloadMatchesTheGoldenFile()
}//end class
