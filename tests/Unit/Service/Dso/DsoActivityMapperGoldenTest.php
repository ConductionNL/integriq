<?php

/**
 * Golden test for DsoActivityMapper::mapRequest.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoActivityMapper;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use PHPUnit\Framework\TestCase;

/**
 * Pins the mapped fields of verzoeken that are fully, partly and not mapped,
 * against a golden file.
 */
class DsoActivityMapperGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/dso/activity-mappings.json';

	/**
	 * Every verzoek matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryVerzoekMatchesTheGoldenFile(): void {
		$rows  = [
			['id' => 1, 'imowId' => 'nl.imow-gm0001.activiteit.A', 'activityId' => 'ACT-A', 'samenloopStrategy' => 'gecombineerd', 'caseTypes' => [['reference' => 'ZT-1', 'title' => 'Bouw', 'department' => 'VTH'], ['reference' => ' '], 'x', ['reference' => '42']]],
			['id' => 2, 'imowId' => '', 'activityId' => 'ACT-B', 'samenloopStrategy' => 'apart', 'caseTypes' => [['reference' => 'ZT-2', 'variant' => 'licht'], ['reference' => 'ZT-1']]],
			['id' => 3, 'imowId' => 'nl.imow-gm0001.activiteit.A', 'activityId' => 'ACT-C', 'caseTypes' => []],
		];
		$table = $this->createMock(DsoActivityTable::class);
		$table->method('activeRows')->willReturn($rows);
		$mapper = new DsoActivityMapper($table);

		$verzoeken = [
			'mixed' => [
				['imowId' => ' nl.imow-gm0001.activiteit.A ', 'activityName' => 'Bouwen', 'volgnr' => 1],
				'not-an-array',
				['activityId' => 'ACT-X', 'activityName' => 'Onbekend', 'underlying' => ['activityId' => 'ACT-B', 'activityName' => 'Onder']],
				['activityId' => 'ACT-Y', 'underlying' => ['imowId' => ' ']],
				['activityId' => 'ACT-C', 'volgnr' => '', 'underlying' => 'text'],
			],
			'all-mapped' => [['activityId' => 'ACT-B'], ['imowId' => 'nl.imow-gm0001.activiteit.A']],
			'none-mapped' => [['activityId' => 'NOPE', 'activityName' => ['array']]],
			'empty' => [],
		];
		$actual = [];
		foreach ($verzoeken as $name => $activiteiten) {
			$actual[$name] = $mapper->mapRequest($activiteiten);
		}

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryVerzoekMatchesTheGoldenFile()
}//end class
