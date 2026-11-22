<?php

/**
 * Golden characterization of SloCurriculumTreeWalker::walk(): every node it
 * emits, in order, and every counter, for the shipped set profiles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Slo;

use OCA\Integriq\Adapters\Slo\JsonTagReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumClientMock;
use OCA\Integriq\Adapters\Slo\SloCurriculumNodeReader;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloCurriculumTreeWalker;
use PHPUnit\Framework\TestCase;

/**
 * The walk output for each shipped profile matches the golden file.
 */
class SloCurriculumTreeWalkGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/slo/tree-walk.json';

	/**
	 * Walk every case.
	 *
	 * @return array<string, array<string, mixed>> The output per case.
	 */
	private function actual(): array {
		$client   = new SloCurriculumClientMock();
		$registry = new SloCurriculumPresetRegistry();
		$walker   = new SloCurriculumTreeWalker(new JsonTagReader(), new SloCurriculumNodeReader(new JsonTagReader()));

		$listing = $walker->fetchJson($client, 'kerndoel_vakleergebied/', ['page' => 0, 'perPage' => 1000]);
		$kerndoelen = array_map(static fn (array $item): array => $walker->fetchTree($client, $item['uuid']), $listing['data']);

		$cases = [
			'fo-kerndoelen' => [[$walker->fetchTree($client, '612afa33-c49c-4b12-a7d1-7e44f2d69d25')], 'fo-kerndoelen', false],
			'kerndoelen-2006-po' => [$kerndoelen, 'kerndoelen-2006-po', true],
			'examenprogramma' => [[$walker->fetchTree($client, '43beb4d1-9950-4e88-b18e-0dee930169fe')], 'examenprogramma', false],
			'leerdoelenkaarten' => [[$walker->fetchTree($client, '9f638551-cd79-439a-a41e-b11e29899164')], 'leerdoelenkaarten', false],
		];

		$out = [];
		foreach ($cases as $name => [$roots, $set, $rootsAreNodes]) {
			$result = $walker->walk($roots, $registry->set($set), $client, $rootsAreNodes);
			$out[$name] = [
				'stats' => $result['stats'],
				'nodes' => array_map(
					static fn (array $node): array => [
						'sloUuid' => $node['sloUuid'],
						'sloType' => $node['sloType'],
						'parentSloUuid' => $node['parentSloUuid'],
						'order' => $node['order'],
						'isLeaf' => $node['isLeaf'],
					],
					$result['nodes']
				),
				'sha1' => sha1(json_encode($result['nodes'])),
			];
		}

		return $out;

	}//end actual()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryProfileWalksAsTheGoldenFile(): void {
		$actual = $this->actual();
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);

	}//end testEveryProfileWalksAsTheGoldenFile()
}//end class
