<?php

/**
 * The job and synchronization flow generators chain their nodes the same way.
 *
 * Pins the private edgesFor() of JobToFlowGenerator and
 * SynchronizationFlowGenerator: one `from-to` edge per consecutive pair, in
 * array order, keyed or not, and none for fewer than two nodes, so the shared
 * chaining can move without changing a generated flow.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\JobToFlowGenerator;
use OCA\Integriq\Service\SynchronizationFlowGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for chaining a generated flow's nodes.
 */
final class PipelineEdgesGoldenTest extends TestCase {

	/**
	 * Node lists and the edges each yields, for both generators.
	 *
	 * @return array<string, array{0: class-string, 1: array, 2: array}>
	 */
	public static function chains(): array {
		$cases = [
			'none' => [[], []],
			'one' => [[['id' => 'trigger']], []],
			'three, keyed' => [
				['a' => ['id' => 'trigger'], 'b' => ['id' => 'run'], 'c' => ['id' => 7]],
				[
					['id' => 'trigger-run', 'from' => 'trigger', 'to' => 'run'],
					['id' => 'run-7', 'from' => 'run', 'to' => '7'],
				],
			],
		];
		$rows = [];
		foreach ([JobToFlowGenerator::class, SynchronizationFlowGenerator::class] as $class) {
			foreach ($cases as $name => [$nodes, $edges]) {
				$rows[$class . ' ' . $name] = [$class, $nodes, $edges];
			}
		}

		return $rows;

	}//end chains()

	/**
	 * The edges a node list yields.
	 *
	 * @param class-string $class The generator.
	 * @param array        $nodes The nodes.
	 * @param array        $edges The edges they yield.
	 *
	 * @return void
	 */
	#[DataProvider('chains')]
	public function testTheEdgesANodeListYields(string $class, array $nodes, array $edges): void {
		$reflection = new ReflectionClass($class);

		$this->assertSame($edges, $reflection->getMethod('edgesFor')->invoke($reflection->newInstanceWithoutConstructor(), $nodes));

	}//end testTheEdgesANodeListYields()
}//end class
