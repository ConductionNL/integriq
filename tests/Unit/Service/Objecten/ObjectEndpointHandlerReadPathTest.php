<?php

/**
 * Unit tests for ObjectEndpointHandler's read path when the object service is missing or misbehaves.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectPage;
use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins that an unwired, failing or malformed read answers an empty list, and that a malformed point never matches a search.
 */
#[CoversClass(ObjectEndpointHandler::class)]
#[UsesClass(ObjecttypeRegistry::class)]
#[UsesClass(ObjectRecordTranslator::class)]
#[UsesClass(ObjectPage::class)]
class ObjectEndpointHandlerReadPathTest extends TestCase {

	/**
	 * The handler over one objecttype with the given read path.
	 *
	 * @param callable|null $objectRead The read path.
	 *
	 * @return ObjectEndpointHandler
	 */
	private function handler(?callable $objectRead): ObjectEndpointHandler {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']]);

		return new ObjectEndpointHandler(objecttypes: $registry, translator: new ObjectRecordTranslator(), objectRead: $objectRead);
	}//end handler()

	/**
	 * Read paths that yield nothing usable.
	 *
	 * @return array<string, array{0: callable|null}>
	 */
	public static function emptyReads(): array {
		return [
			'not wired' => [null],
			'throws' => [static function (): array {
				throw new RuntimeException('register offline');
			}],
			'not an array' => [static fn (): string => 'oops'],
		];
	}//end emptyReads()

	/**
	 * A list over an unusable read path is an empty first page.
	 *
	 * @param callable|null $objectRead The read path.
	 *
	 * @return void
	 */
	#[DataProvider('emptyReads')]
	public function testAnUnusableReadListsNothing(?callable $objectRead): void {
		$answer = $this->handler($objectRead)->index(query: ['type' => 'aaa']);

		$this->assertSame(200, $answer['status']);
		$this->assertSame(0, $answer['body']['count']);
		$this->assertSame([], $answer['body']['results']);
		$this->assertNull($answer['body']['next']);
	}//end testAnUnusableReadListsNothing()

	/**
	 * An unusable read path makes every object a 404.
	 *
	 * @param callable|null $objectRead The read path.
	 *
	 * @return void
	 */
	#[DataProvider('emptyReads')]
	public function testAnUnusableReadShowsNothing(?callable $objectRead): void {
		$answer = $this->handler($objectRead)->show(type: 'aaa', uuid: 'u1');

		$this->assertSame(404, $answer['status']);
		$this->assertSame('No object "u1" of this objecttype.', $answer['body']['detail']);
	}//end testAnUnusableReadShowsNothing()

	/**
	 * Entries of a read that are not objects are dropped.
	 *
	 * @return void
	 */
	public function testNonObjectEntriesAreDropped(): void {
		$read = static fn (): array => ['junk', ['@self' => ['id' => 'u1'], 'a' => 1], 7];

		$this->assertSame(1, $this->handler($read)->index(query: ['type' => 'aaa'])['body']['count']);
	}//end testNonObjectEntriesAreDropped()

	/**
	 * In a search, an object whose point lacks a coordinate never matches; a full point inside the circle does.
	 *
	 * @return void
	 */
	public function testAPointWithTooFewCoordinatesNeverMatches(): void {
		$read = static fn (): array => [
			['@self' => ['id' => 'short'], 'geometry' => ['type' => 'Point', 'coordinates' => [5.0]]],
			['@self' => ['id' => 'none'], 'geometry' => ['type' => 'Point']],
			['@self' => ['id' => 'full'], 'geometry' => ['type' => 'Point', 'coordinates' => [5.0, 52.0]]],
		];
		$body = ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.0, 52.0], 'radius' => 10]]];

		$answer = $this->handler($read)->search(type: 'aaa', body: $body);

		$this->assertSame(200, $answer['status']);
		$this->assertSame(1, $answer['body']['count']);
		$this->assertSame('full', $answer['body']['results'][0]['uuid']);
	}//end testAPointWithTooFewCoordinatesNeverMatches()
}//end class
