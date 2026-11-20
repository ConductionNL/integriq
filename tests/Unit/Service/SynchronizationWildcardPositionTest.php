<?php

/**
 * Tests for a wildcard resultsPosition in SynchronizationService::getAllObjectsFromArray().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
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

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins that a `*` / `**` resultsPosition reaches every repeated container,
 * and that a fixed dot path still reads exactly as before.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Service\Synchronization\WildcardResultsPath
 *
 * @spec openspec/changes/native-data-gathering-provider/tasks.md
 */
class SynchronizationWildcardPositionTest extends TestCase {

	/**
	 * The objects the service finds in a body at a position.
	 *
	 * @param array  $body     The decoded body.
	 * @param string $position The resultsPosition.
	 *
	 * @return array The objects.
	 */
	private function objects(array $body, string $position): array {
		$service = (new ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();

		return $service->getAllObjectsFromArray($body, ['sourceConfig' => ['resultsPosition' => $position]]);
	}//end objects()

	/**
	 * A wildcard position joins the objects of every chapter.
	 *
	 * @return void
	 */
	public function testAWildcardPositionJoinsEveryContainer(): void {
		$body = ['wet' => ['hoofdstuk' => [['artikel' => [['nr' => '1']]], ['artikel' => ['nr' => '2']]]]];

		$this->assertSame([['nr' => '1'], ['nr' => '2']], $this->objects($body, 'wet.hoofdstuk.*.artikel'));
		$this->assertSame([['nr' => '1'], ['nr' => '2']], $this->objects($body, '**.artikel'));
	}//end testAWildcardPositionJoinsEveryContainer()

	/**
	 * A fixed dot path returns the value at that place, unchanged.
	 *
	 * @return void
	 */
	public function testAFixedPathReadsAsBefore(): void {
		$body = ['channel' => ['item' => ['title' => 'one']]];

		$this->assertSame(['title' => 'one'], $this->objects($body, 'channel.item'));
		$this->assertSame([], $this->objects($body, 'channel.missing'));
	}//end testAFixedPathReadsAsBefore()
}//end class
