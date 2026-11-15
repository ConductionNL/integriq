<?php

/**
 * Characterisation test for ConnectorTemplateLibrary.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Catalog
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalog;

use OCA\Integriq\Service\Catalog\ConnectorTemplateLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the connector template cards.
 */
class ConnectorTemplateLibraryGoldenTest extends TestCase {

	/**
	 * The recorded cards and payloads.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$dir = sys_get_temp_dir() . '/ctl-golden-' . getmypid();
		$files = [
			'b-zaken/zrc.json' => ['x-template' => ['slug' => 'zrc', 'system' => 'ZRC', 'category' => 'Zaken', 'standard' => 'ZGW', 'tier' => 'verified', 'verifiedAgainst' => 'v1.5', 'snapshotDate' => '2026-01-01'], 'source' => ['name' => 'Zaken API', 'description' => 'Zaakregistratie', 'type' => 'json']],
			'a-brp/brp.json' => ['x-template' => ['slug' => 'brp', 'system' => 'BRP'], 'source' => ['location' => 'https://brp.example.test']],
			'a-brp/bare.json' => ['x-template' => ['slug' => 'bare', 'snapshotDate' => ''], 'source' => []],
			'c/allow-list.json' => ['allowed' => ['zrc']],
			'c/no-slug.json' => ['x-template' => ['slug' => ''], 'source' => ['name' => 'x']],
			'c/no-source.json' => ['x-template' => ['slug' => 'nosource'], 'source' => 'text'],
			'c/meta-text.json' => ['x-template' => 'zrc', 'source' => []],
			'top.json' => ['x-template' => ['slug' => 'top'], 'source' => []],
		];
		foreach ($files as $name => $data) {
			if (is_dir(dirname($dir . '/' . $name)) === false) {
				mkdir(dirname($dir . '/' . $name), 0777, true);
			}

			file_put_contents($dir . '/' . $name, json_encode($data));
		}

		file_put_contents($dir . '/c/broken.json', '{not json');

		$library = new ConnectorTemplateLibrary($dir);
		$record = [
			'cards' => $library->cards(),
			'payload zrc' => $library->payload('zrc'),
			'payload bare' => $library->payload('bare'),
			'payload unknown' => $library->payload('nope'),
			'missing directory' => (new ConnectorTemplateLibrary($dir . '/missing'))->cards(),
		];

		foreach (array_merge(array_keys($files), ['c/broken.json']) as $name) {
			unlink($dir . '/' . $name);
		}

		$path = __DIR__ . '/../../../fixtures/catalog/connector-template-library.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
