<?php

/**
 * Golden test for ChangeSetBuilder::build.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\ChangeSetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins whole change sets — created, changed, removed, unchanged, counts, cut,
 * and the exact fingerprint — for a mixed run, with and without removals, and
 * for a run past the cut.
 */
class ChangeSetBuilderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../fixtures/synchronization/change-sets.json';

	/**
	 * The runs, rendered by the current code.
	 *
	 * @return array<string, array>
	 */
	private function changeSets(): array {
		$entries = [
			['originId' => 'b', 'targetId' => 't-b', 'mapped' => ['name' => 'B2', 'tags' => ['y' => 1, 'x' => 2], 'id' => 'ignored'], 'existing' => ['name' => 'B', 'tags' => ['x' => 2, 'y' => 1], 'id' => 't-b']],
			['originId' => 'a', 'targetId' => null, 'mapped' => ['name' => 'A'], 'existing' => null],
			['originId' => 3, 'targetId' => 't-3', 'mapped' => ['name' => 'C', 'uuid' => 'u'], 'existing' => ['name' => 'C']],
			['originId' => 'd', 'targetId' => 't-d', 'mapped' => ['name' => 'D', 'extra' => null], 'existing' => ['name' => 'D']],
			['originId' => 'c', 'targetId' => 't-c', 'mapped' => ['list' => [2, 1]], 'existing' => ['list' => [1, 2]]],
		];
		$removed = [['originId' => 'z', 'targetId' => 9], ['originId' => 'y', 'targetId' => 't-y']];

		$large = [];
		for ($i = 0; $i < 502; $i++) {
			$large[] = ['originId' => sprintf('o-%04d', 501 - $i), 'targetId' => null, 'mapped' => ['n' => $i], 'existing' => null];
		}

		$builder = new ChangeSetBuilder();
		$out     = [
			'mixed' => $builder->build($entries, $removed, true),
			'mixed-no-removals' => $builder->build($entries, $removed, false),
			'empty' => $builder->build([], [], true),
		];
		$cut = $builder->build($large, [], true);
		$out['large'] = [
			'counts' => $cut['counts'],
			'truncated' => $cut['truncated'],
			'limit' => $cut['limit'],
			'first' => $cut['created'][0],
			'last' => $cut['created'][499],
			'listed' => count($cut['created']),
			'fingerprint' => $cut['fingerprint'],
		];

		return $out;

	}//end changeSets()

	/**
	 * Every change set matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryChangeSetMatchesTheGoldenFile(): void {
		$actual = $this->changeSets();
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), json_decode((string) json_encode($actual), true));
		$this->assertSame(array_keys($actual['mixed']), ['created', 'changed', 'removed', 'unchanged', 'counts', 'truncated', 'limit', 'fingerprint']);

	}//end testEveryChangeSetMatchesTheGoldenFile()
}//end class
