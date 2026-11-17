<?php

/**
 * Example data for dossiq's connections page (DqInstellingen): four of
 * dossiq's connections name the source they read from. StUF-ZKN's status
 * is runtime state the connection check writes, not example data.
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
	 * job calls a made-up address; StUF-ZKN's change sets its source only.
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

		// StUF-ZKN's status is runtime state: integriq's connection check writes it on every save ("Not checked
		// yet" for a source that is switched off), so a declared failure read as "written but not kept" on every
		// refresh. The set declares only its source. (BRP's declared "simulated" is what the check itself writes.)
		$this->assertSame(expected: ['source'], actual: array_keys(array_column($set['changes'], null, 'key')['dossiq-stuf-bron']['set']));
	}//end testDossiqConnectionsNameTheirSource()
}//end class
