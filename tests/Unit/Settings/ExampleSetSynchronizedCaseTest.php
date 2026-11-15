<?php

/**
 * Integriq's part of the Zuiddrecht example set records the synchronization
 * that brought dossiq's Lindelaan melding in, as IqGesynchroniseerdVan draws
 * the case's provenance: the source, the synchronization, the contract that
 * targets the case, and its recent log, while nothing can run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the shipped example-set fragment from disk.
 */
class ExampleSetSynchronizedCaseTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json';

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed> The fragment
	 */
	private static function fragment(): array {
		self::assertFileExists(filename: self::FRAGMENT);
		return json_decode((string)file_get_contents(self::FRAGMENT), true);
	}//end fragment()

	/**
	 * The contract targets dossiq's melding, found by its title, after dossiq loads.
	 *
	 * @return void
	 */
	public function testTheContractTargetsTheDossiqCase(): void {
		$fragment = self::fragment();
		$objects  = array_column($fragment['objects'], null, 'key');
		$contract = $objects['contract-melding-afval-lindelaan'];

		$this->assertContains(needle: 'dossiq', haystack: $fragment['after']);
		$this->assertSame(expected: '{{lookup:case}}', actual: $contract['data']['targetId']);
		$this->assertSame(
			expected: ['register' => 'dossiq', 'schema' => 'case', 'where' => ['title' => 'Melding afval Lindelaan']],
			actual: $contract['lookups']['case']
		);
		$this->assertSame(expected: '{{object:sync-zaken-naar-register.id}}', actual: $contract['data']['synchronization']);
	}//end testTheContractTargetsTheDossiqCase()

	/**
	 * The source is off and the synchronization names a source record, not a
	 * register, so no object event and no job can run it.
	 *
	 * @return void
	 */
	public function testNothingCanRun(): void {
		$objects = array_column(self::fragment()['objects'], null, 'key');

		$this->assertFalse(condition: $objects['source-zaaksysteem-afval']['data']['isEnabled']);
		$this->assertSame(expected: '{{object:source-zaaksysteem-afval.id}}', actual: $objects['sync-zaken-naar-register']['data']['sourceId']);
		$this->assertSame(expected: [], actual: array_filter(self::fragment()['objects'], fn (array $o): bool => $o['schema'] === 'job'));
	}//end testNothingCanRun()

	/**
	 * The contract has its recent log.
	 *
	 * @return void
	 */
	public function testTheContractHasItsLog(): void {
		$logs = array_filter(
			self::fragment()['objects'],
			fn (array $o): bool => $o['schema'] === 'synchronization_contract_log'
				&& $o['data']['synchronization_contract'] === '{{object:contract-melding-afval-lindelaan.id}}'
		);

		$this->assertCount(expectedCount: 3, haystack: $logs);
	}//end testTheContractHasItsLog()
}//end class
