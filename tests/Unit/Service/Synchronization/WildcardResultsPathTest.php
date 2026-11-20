<?php

/**
 * Unit tests for WildcardResultsPath.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\WildcardResultsPath;
use PHPUnit\Framework\TestCase;

/**
 * Pins which objects a `*` or `**` resultsPosition reaches, and in what order.
 *
 * @covers \OCA\Integriq\Service\Synchronization\WildcardResultsPath
 *
 * @spec openspec/changes/native-data-gathering-provider/tasks.md
 */
class WildcardResultsPathTest extends TestCase {

	/**
	 * A law whose articles sit in every chapter, one chapter holding a single article.
	 *
	 * @return array The decoded document.
	 */
	private function law(): array {
		return [
			'wet' => [
				'hoofdstuk' => [
					['nr' => '1', 'artikel' => [['nr' => '1.1'], ['nr' => '1.2']]],
					['nr' => '2', 'artikel' => ['nr' => '2.1']],
					['nr' => '3'],
				],
			],
		];
	}//end law()

	/**
	 * Only `*` and `**` segments make a path a wildcard path.
	 *
	 * @return void
	 */
	public function testOnlyStarSegmentsMakeAWildcard(): void {
		$path = new WildcardResultsPath();

		$this->assertTrue($path->isWildcard('wet.hoofdstuk.*.artikel'));
		$this->assertTrue($path->isWildcard('**.artikel'));
		$this->assertFalse($path->isWildcard('channel.item'));
		$this->assertFalse($path->isWildcard('a*b.item'));
	}//end testOnlyStarSegmentsMakeAWildcard()

	/**
	 * `*` takes each element of a list, and a single object reached is one object.
	 *
	 * @return void
	 */
	public function testAStarTakesEachElementAndFlattensTheirLists(): void {
		$objects = (new WildcardResultsPath())->objects($this->law(), 'wet.hoofdstuk.*.artikel');

		$this->assertSame([['nr' => '1.1'], ['nr' => '1.2'], ['nr' => '2.1']], $objects);
	}//end testAStarTakesEachElementAndFlattensTheirLists()

	/**
	 * `*` over a single object, where a list could be, reads it as a one-element list.
	 *
	 * @return void
	 */
	public function testAStarOverASingleObjectReadsItAsOne(): void {
		$data = ['wet' => ['hoofdstuk' => ['nr' => '1', 'artikel' => [['nr' => '1.1']]]]];

		$objects = (new WildcardResultsPath())->objects($data, 'wet.hoofdstuk.*.artikel');

		$this->assertSame([['nr' => '1.1']], $objects);
	}//end testAStarOverASingleObjectReadsItAsOne()

	/**
	 * `**` finds the key at any depth, each match once, in document order.
	 *
	 * @return void
	 */
	public function testADoubleStarFindsTheKeyAtAnyDepthOnce(): void {
		$data = [
			'deel' => [
				['artikel' => ['nr' => 'A'], 'hoofdstuk' => ['afdeling' => ['artikel' => [['nr' => 'B'], ['nr' => 'C']]]]],
				['hoofdstuk' => [['artikel' => ['nr' => 'D', 'lid' => ['artikel' => ['nr' => 'D.ref']]]]]],
			],
		];

		$objects = (new WildcardResultsPath())->objects($data, '**.artikel');

		$this->assertSame(
			[['nr' => 'A'], ['nr' => 'B'], ['nr' => 'C'], ['nr' => 'D', 'lid' => ['artikel' => ['nr' => 'D.ref']]]],
			$objects
		);
	}//end testADoubleStarFindsTheKeyAtAnyDepthOnce()

	/**
	 * `**` matches zero levels too: a key at the top is found.
	 *
	 * @return void
	 */
	public function testADoubleStarMatchesZeroLevels(): void {
		$objects = (new WildcardResultsPath())->objects(['item' => [['id' => 1]]], '**.item');

		$this->assertSame([['id' => 1]], $objects);
	}//end testADoubleStarMatchesZeroLevels()

	/**
	 * A path that reaches nothing, or only scalars, gives no objects.
	 *
	 * @return void
	 */
	public function testAPathReachingNothingOrScalarsGivesNoObjects(): void {
		$path = new WildcardResultsPath();

		$this->assertSame([], $path->objects($this->law(), 'wet.bijlage.*.artikel'));
		$this->assertSame([], $path->objects($this->law(), 'wet.hoofdstuk.*.nr'));
	}//end testAPathReachingNothingOrScalarsGivesNoObjects()
}//end class
