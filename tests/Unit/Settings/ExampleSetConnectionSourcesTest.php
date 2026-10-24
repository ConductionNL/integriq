<?php

/**
 * Example data for dossiq's connections page (DqInstellingen): four of
 * dossiq's connections name the source they read from, and the StUF-ZKN
 * connection reports the failure the board draws.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The sources behind dossiq's connections.
 */
class ExampleSetConnectionSourcesTest extends TestCase {

	/**
	 * Every linked source is declared in the fragment and switched off, so no
	 * job calls a made-up address; StUF-ZKN reports its failure.
	 *
	 * @return void
	 */
	public function testDossiqConnectionsNameTheirSource(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$sources = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'source') {
				$sources[$object['key']] = $object['data'];
			}
		}

		$linked = [];
		foreach ($set['changes'] as $change) {
			$this->assertSame(expected: 'dossiq', actual: $change['where']['app']);
			$key = (string)preg_replace(pattern: '/^\{\{object:(.+)\.id\}\}$/', replacement: '$1', subject: $change['set']['source']);
			$this->assertArrayHasKey(key: $key, array: $sources);
			$this->assertFalse(condition: $sources[$key]['isEnabled'], message: $key);
			$linked[$change['where']['key']] = $sources[$key]['name'];
		}

		$this->assertSame(
			expected: [
				'brp'  => 'Testregister met fictieve personen',
				'kvk'  => 'Testregister met fictieve bedrijven',
				'pdok' => 'PDOK BAG',
				'stuf' => 'Zaakmagazijn Drechtsteden',
			],
			actual: $linked
		);

		$stuf = array_column($set['changes'], null, 'key')['dossiq-stuf-bron']['set'];
		$this->assertSame(expected: ['error', '2 berichten niet aangekomen'], actual: [$stuf['status'], $stuf['lastReport']['message']]);
	}//end testDossiqConnectionsNameTheirSource()
}//end class
