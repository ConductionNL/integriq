<?php

/**
 * Characterisation test for ObjectEndpointHandler::search.
 *
 * Pins the allowed and refused geometry searches: the refusals (unknown type,
 * missing or unevaluable "within"), which objects a principal's read returns
 * inside the circle, and that a refused search never reads objects.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
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

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden record of the geometry search.
 */
class ObjectEndpointSearchGoldenTest extends TestCase {

	/**
	 * Every read and every answer, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The objects a principal may read.
	 *
	 * @param string $principal The principal.
	 *
	 * @return array<int, mixed>
	 */
	private function objectsFor(string $principal): array {
		if ($principal === 'boom') {
			throw new RuntimeException('read failed');
		}

		if ($principal !== 'alice') {
			return [];
		}

		$meta = static fn (string $id): array => ['id' => $id, 'created' => '2026-01-02T03:04:05+00:00', 'version' => 1];
		return [
			['@self' => $meta('near'), 'naam' => 'near', 'geometry' => ['type' => 'Point', 'coordinates' => [5.1215, 52.0910]]],
			['@self' => $meta('far'), 'naam' => 'far', 'geometry' => ['type' => 'Point', 'coordinates' => [4.8952, 52.3702]]],
			['@self' => $meta('none'), 'naam' => 'none'],
			['@self' => $meta('poly'), 'naam' => 'poly', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[5.12, 52.09]]]]],
			['@self' => $meta('text'), 'naam' => 'text', 'geometry' => ['type' => 'point', 'coordinates' => ['x', 52.09]]],
			['@self' => $meta('near2'), 'naam' => 'near2', 'geometry' => ['type' => 'POINT', 'coordinates' => [5.1220, 52.0912, 3]]],
			'not an object',
		];
	}//end objectsFor()

	/**
	 * The recorded outcomes.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$registry = new ObjecttypeRegistry();
		$registry->load([['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding']]);
		$handler = new ObjectEndpointHandler(
			objecttypes: $registry,
			translator: new ObjectRecordTranslator(),
			objectRead: function (string $register, string $schema, string $principal): array {
				$this->log[] = ['read' => [$register, $schema, $principal]];
				return $this->objectsFor(principal: $principal);
			}
		);

		$circle = ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1214, 52.0907], 'radius' => 500]]];
		$cases = [
			'unknown type' => ['zzz', $circle, 'alice'],
			'no geometry' => ['aaa', [], 'alice'],
			'within not an array' => ['aaa', ['geometry' => ['within' => 'circle']], 'alice'],
			'polygon within' => ['aaa', ['geometry' => ['within' => ['type' => 'Polygon', 'coordinates' => [[[5.1, 52.0]]]]]], 'alice'],
			'zero radius' => ['aaa', ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1, 52.0], 'radius' => 0]]], 'alice'],
			'text radius' => ['aaa', ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1, 52.0], 'radius' => 'far']]], 'alice'],
			'allowed' => ['aaa', $circle, 'alice'],
			'allowed by url' => ['https://example.test/api/v2/objecttypes/aaa', $circle, 'alice'],
			'allowed paged' => ['aaa', ($circle + ['pageSize' => 1, 'page' => 2]), 'alice'],
			'wide circle' => ['aaa', ['geometry' => ['within' => ['type' => 'point', 'coordinates' => ['5.12', '52.09'], 'radius' => '60000']]], 'alice'],
			'nothing readable' => ['aaa', $circle, 'bob'],
			'read throws' => ['aaa', $circle, 'boom'],
		];
		foreach ($cases as $name => [$type, $body, $principal]) {
			$this->log[] = [$name => $handler->search($type, $body, 'https://example.test', $principal)];
		}

		$path = __DIR__ . '/../../../fixtures/objecten/object-search.json';
		$actual = json_encode($this->log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
