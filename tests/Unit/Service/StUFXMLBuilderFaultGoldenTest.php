<?php

/**
 * Golden master of the StUF foutberichten and stuurgegevens StUFXMLBuilder writes.
 *
 * Every case records the complete XML, with the random referentienummer and
 * the clock-read tijdstipBericht replaced by placeholders. Set RECORD_GOLDEN=1
 * to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\StUFXMLBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\StUFXMLBuilder
 */
class StUFXMLBuilderFaultGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/../../fixtures/golden/stuf-xml-builder-faults.json';

	/**
	 * The XML with its random reference and its timestamp replaced.
	 *
	 * @param string $xml The XML.
	 *
	 * @return array<int, string> The normalised lines.
	 */
	private static function normalise(string $xml): array {
		$xml = (string)preg_replace('#<StUF:referentienummer>[0-9a-f-]{36}</StUF:referentienummer>#', '<StUF:referentienummer>UUID</StUF:referentienummer>', $xml);
		$xml = (string)preg_replace('#<StUF:tijdstipBericht>\d{14}000</StUF:tijdstipBericht>#', '<StUF:tijdstipBericht>TIMESTAMP</StUF:tijdstipBericht>', $xml);

		return explode("\n", $xml);
	}

	/**
	 * Every case against the recorded fixture.
	 *
	 * @return void
	 */
	public function testTheFaultsMatchTheRecording(): void {
		$builder = new StUFXMLBuilder();
		$full = ['zenderOrganisatie' => 'Gemeente', 'zenderApplicatie' => 'Integriq', 'ontvangerOrganisatie' => 'Leverancier', 'ontvangerApplicatie' => 'ZS', 'crossRefnummer' => 'ref-42'];
		$found = [
			'fo01-default-client' => $builder->buildFo01('StUF046', 'Niet gevonden <leeg>', []),
			'fo01-server' => $builder->buildFo01('StUF058', 'Interne fout', $full, 'server'),
			'fo01-other-plek' => $builder->buildFo01('StUF001', 'Onbekend', ['crossRefnummer' => 7], 'ergens'),
			'fo02-default-server' => $builder->buildFo02('StUF055', 'Geen antwoord', $full),
			'fo02-client' => $builder->buildFo02('StUF010', 'Fout verzoek', [], 'client'),
			'fo03' => $builder->buildFo03('StUF999', 'Zaak fout', $full),
			'fo03-defaults' => $builder->buildFo03('', '', []),
			'bv03' => $builder->buildBv03('ZAAK-1', $full),
			'nps-lv01' => $builder->buildNpsLv01(['bsn' => '123', 'geslachtsnaam' => 'Vries'], ['zenderOrganisatie' => 'G'], 5),
		];
		$found = array_map(static fn (string $xml): array => self::normalise(xml: $xml), $found);

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], 'case ' . $name);
		}
	}
}
