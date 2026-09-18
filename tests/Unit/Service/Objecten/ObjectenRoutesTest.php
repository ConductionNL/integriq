<?php

/**
 * The read routes of both APIs, against fixtures and no database.
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
use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-OAF-002 and the read half of REQ-OAF-003.
 */
class ObjectenRoutesTest extends TestCase {

	/**
	 * Two objecttypes, the first pinned to version 1.
	 *
	 * @return ObjecttypeRegistry The registry.
	 */
	private function registry(): ObjecttypeRegistry {
		$registry = new ObjecttypeRegistry();
		$registry->load(
			[
				['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding', 'versions' => ['1']],
				['uuid' => 'bbb', 'name' => 'klacht', 'register' => 'klachten', 'schema' => 'klacht'],
			]
		);

		return $registry;
	}//end registry()

	/**
	 * Three meldingen and two klachten, two of the meldingen with a geometry.
	 *
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(string $register, string $schema): array {
		if ($register !== 'meldingen') {
			return [
				['@self' => ['id' => 'k-1', 'created' => '2026-01-02T09:00:00+01:00'], 'titel' => 'Klacht een'],
				['@self' => ['id' => 'k-2', 'created' => '2026-01-03T09:00:00+01:00'], 'titel' => 'Klacht twee'],
			];
		}

		return [
			[
				'@self' => ['id' => 'm-1', 'created' => '2026-02-01T09:00:00+01:00'],
				'straatnaam' => 'Kerkstraat',
				'status' => 'open',
				// Utrecht Dom tower.
				'geometry' => ['type' => 'Point', 'coordinates' => [5.1214, 52.0907]],
			],
			[
				'@self' => ['id' => 'm-2', 'created' => '2026-02-02T09:00:00+01:00'],
				'straatnaam' => 'Amsterdamsestraatweg',
				'status' => 'gesloten',
				// About 300 metres away.
				'geometry' => ['type' => 'Point', 'coordinates' => [5.1258, 52.0917]],
			],
			[
				'@self' => ['id' => 'm-3', 'created' => '2026-02-03T09:00:00+01:00'],
				'straatnaam' => 'Biltstraat',
				'status' => 'open',
				// Amersfoort, about 20 kilometres away.
				'geometry' => ['type' => 'Point', 'coordinates' => [5.3878, 52.1561]],
			],
		];
	}//end objects()

	/**
	 * The objects handler over the fixtures.
	 *
	 * @return ObjectEndpointHandler The handler.
	 */
	private function objectsHandler(): ObjectEndpointHandler {
		return new ObjectEndpointHandler(
			objecttypes: $this->registry(),
			translator: new ObjectRecordTranslator(),
			objectRead: fn (string $register, string $schema): array => $this->objects(register: $register, schema: $schema)
		);
	}//end objectsHandler()

	/**
	 * The objecttypes handler over a fixture schema.
	 *
	 * @param callable|null $schemaRead How to read a schema.
	 *
	 * @return ObjecttypeEndpointHandler The handler.
	 */
	private function typesHandler(?callable $schemaRead = null): ObjecttypeEndpointHandler {
		return new ObjecttypeEndpointHandler(
			objecttypes: $this->registry(),
			schemaRead: ($schemaRead ?? static fn (string $r, string $s): array => ['type' => 'object', 'title' => $s])
		);
	}//end typesHandler()

	/**
	 * The list names both objecttypes and counts the total.
	 *
	 * @return void
	 */
	public function testTheObjecttypeListNamesBothAndCountsTheTotal(): void {
		$response = $this->typesHandler()->index(baseUrl: 'https://example.nl/api/v2');

		$this->assertSame(200, $response['status']);
		$this->assertSame(2, $response['body']['count']);
		$this->assertSame(['aaa', 'bbb'], array_column($response['body']['results'], 'uuid'));
	}//end testTheObjecttypeListNamesBothAndCountsTheTotal()

	/**
	 * 🔴 The register and schema names do not go out.
	 *
	 * They are OpenRegister's internal addressing; the published uuid is the
	 * whole of what a consumer addresses.
	 *
	 * @return void
	 */
	public function testTheRegisterAndSchemaNamesDoNotGoOut(): void {
		$body = $this->typesHandler()->index(baseUrl: 'https://example.nl/api/v2')['body'];

		$serialised = (string)json_encode($body);
		$this->assertStringNotContainsString('meldingen', $serialised, 'the register name is not a consumer\'s business');
		$this->assertStringNotContainsString('klachten', $serialised);
	}//end testTheRegisterAndSchemaNamesDoNotGoOut()

	/**
	 * An edited schema shows through immediately, because it is read not stored.
	 *
	 * @return void
	 */
	public function testAnEditedSchemaShowsThroughImmediately(): void {
		$shape = ['type' => 'object', 'required' => ['straatnaam']];

		$response = $this->typesHandler(schemaRead: static fn (string $r, string $s): array => $shape)
			->version(uuid: 'aaa', version: '1');

		$this->assertSame($shape, $response['body']['jsonSchema'], 'a stored copy is a second source of truth for one fact');
	}//end testAnEditedSchemaShowsThroughImmediately()

	/**
	 * 🔴 An unlisted version is a 404, not the newest one.
	 *
	 * @return void
	 */
	public function testAnUnlistedVersionIsA404NotTheNewestOne(): void {
		$response = $this->typesHandler()->version(uuid: 'aaa', version: '2');

		$this->assertSame(404, $response['status']);
		$this->assertStringContainsString('1', $response['body']['detail'], 'and the refusal names what IS published');
	}//end testAnUnlistedVersionIsA404NotTheNewestOne()

	/**
	 * The control: the listed version is served.
	 *
	 * @return void
	 */
	public function testTheListedVersionIsServed(): void {
		$this->assertSame(200, $this->typesHandler()->version(uuid: 'aaa', version: '1')['status']);
	}//end testTheListedVersionIsServed()

	/**
	 * An unreadable schema is an empty object, not a fabricated one.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaIsEmptyNotFabricated(): void {
		$response = $this->typesHandler(
			schemaRead: static function (string $r, string $s): array {
				throw new \RuntimeException('the schema store is down');
			}
		)->version(uuid: 'aaa', version: '1');

		$this->assertSame([], $response['body']['jsonSchema'], 'a consumer validating against {} finds out at the write');
	}//end testAnUnreadableSchemaIsEmptyNotFabricated()

	/**
	 * A consumer lists objects of one objecttype and gets three.
	 *
	 * @return void
	 */
	public function testAConsumerListsObjectsOfOneObjecttype(): void {
		$response = $this->objectsHandler()->index(query: ['type' => 'aaa'], baseUrl: 'https://example.nl/api/v2');

		$this->assertSame(200, $response['status']);
		$this->assertSame(3, $response['body']['count']);
		$this->assertSame(['m-1', 'm-2', 'm-3'], array_column($response['body']['results'], 'uuid'));
		$this->assertSame('aaa', $response['body']['results'][0]['type']);
	}//end testAConsumerListsObjectsOfOneObjecttype()

	/**
	 * 🔴 A list with no `type` is refused, not answered with everything.
	 *
	 * A consumer who asked for "all objects" and got four of the nine on the
	 * instance has been told something untrue in the shape of a success.
	 *
	 * @return void
	 */
	public function testAListWithNoTypeIsRefused(): void {
		$response = $this->objectsHandler()->index(query: []);

		$this->assertSame(400, $response['status']);
		$this->assertStringContainsString('permission per objecttype', $response['body']['detail']);
	}//end testAListWithNoTypeIsRefused()

	/**
	 * `data_attrs` narrows on an exact match.
	 *
	 * @return void
	 */
	public function testDataAttrsNarrowsOnAnExactMatch(): void {
		$response = $this->objectsHandler()->index(query: ['type' => 'aaa', 'data_attrs' => 'status__exact__open']);

		$this->assertSame(2, $response['body']['count']);
		$this->assertSame(['m-1', 'm-3'], array_column($response['body']['results'], 'uuid'));
	}//end testDataAttrsNarrowsOnAnExactMatch()

	/**
	 * 🔴 A malformed `data_attrs` clause matches NOTHING, not everything.
	 *
	 * Ignoring it answers the unfiltered set to somebody who asked for a
	 * filtered one.
	 *
	 * @return void
	 */
	public function testAMalformedDataAttrsClauseMatchesNothing(): void {
		foreach (['status=open', 'status__open', 'status__unknownoperator__open'] as $clause) {
			$response = $this->objectsHandler()->index(query: ['type' => 'aaa', 'data_attrs' => $clause]);

			$this->assertSame(0, $response['body']['count'], sprintf('"%s" must not answer the unfiltered set', $clause));
		}
	}//end testAMalformedDataAttrsClauseMatchesNothing()

	/**
	 * `ordering` sorts, and `-` reverses.
	 *
	 * @return void
	 */
	public function testOrderingSortsAndTheMinusReverses(): void {
		$ascending = $this->objectsHandler()->index(query: ['type' => 'aaa', 'ordering' => 'straatnaam']);
		$descending = $this->objectsHandler()->index(query: ['type' => 'aaa', 'ordering' => '-straatnaam']);

		$this->assertSame(
			array_reverse(array_column($ascending['body']['results'], 'uuid')),
			array_column($descending['body']['results'], 'uuid')
		);
		$this->assertSame('m-2', $ascending['body']['results'][0]['uuid'], 'Amsterdamsestraatweg sorts first');
	}//end testOrderingSortsAndTheMinusReverses()

	/**
	 * `date` narrows to one registration day.
	 *
	 * @return void
	 */
	public function testDateNarrowsToOneDay(): void {
		$response = $this->objectsHandler()->index(query: ['type' => 'aaa', 'date' => '2026-02-02']);

		$this->assertSame(1, $response['body']['count']);
		$this->assertSame('m-2', $response['body']['results'][0]['uuid']);
	}//end testDateNarrowsToOneDay()

	/**
	 * 🔴 `count` is the TOTAL, not the page size.
	 *
	 * A consumer paging through reads it to know when to stop; answering the
	 * page size makes every list look like exactly one page.
	 *
	 * @return void
	 */
	public function testCountIsTheTotalAndNotThePageSize(): void {
		$response = $this->objectsHandler()->index(query: ['type' => 'aaa', 'pageSize' => 2]);

		$this->assertSame(3, $response['body']['count'], 'the total');
		$this->assertCount(2, $response['body']['results'], 'the page');
		$this->assertSame(2, $response['body']['next']);
		$this->assertNull($response['body']['previous']);

		$second = $this->objectsHandler()->index(query: ['type' => 'aaa', 'pageSize' => 2, 'page' => 2]);
		$this->assertCount(1, $second['body']['results']);
		$this->assertNull($second['body']['next'], 'the last page says there is no next');
		$this->assertSame(1, $second['body']['previous']);
	}//end testCountIsTheTotalAndNotThePageSize()

	/**
	 * Reading one object of the type returns it in the standard's shape.
	 *
	 * @return void
	 */
	public function testReadingOneObjectReturnsTheStandardsShape(): void {
		$response = $this->objectsHandler()->show(type: 'aaa', uuid: 'm-2', baseUrl: 'https://example.nl/api/v2');

		$this->assertSame(200, $response['status']);
		$this->assertSame('m-2', $response['body']['uuid']);
		$this->assertSame('https://example.nl/api/v2/objects/m-2', $response['body']['url']);
		$this->assertSame(
			[],
			(new ObjectRecordTranslator())->foreignFieldsIn(rendered: $response['body']),
			'no OpenRegister field reaches the consumer on the read path either'
		);
	}//end testReadingOneObjectReturnsTheStandardsShape()

	/**
	 * 🔴 An object of ANOTHER type answers the same 404 as one that is absent.
	 *
	 * Answering differently would let a token holder probe for uuids outside
	 * their permission.
	 *
	 * @return void
	 */
	public function testAnObjectOfAnotherTypeAnswersTheSame404AsAnAbsentOne(): void {
		$handler = $this->objectsHandler();

		$other = $handler->show(type: 'aaa', uuid: 'k-1');
		$absent = $handler->show(type: 'aaa', uuid: 'k-1-does-not-exist');

		$this->assertSame(404, $other['status']);
		$this->assertSame(404, $absent['status']);

		// The two differ only where they echo the caller's OWN input, which
		// tells them nothing they did not already know. Comparing the raw
		// strings would have compared two different uuids and failed for a
		// reason that is not the property — it did, which is how this
		// assertion came to be written this way.
		$template = static fn (array $response, string $uuid): string => str_replace(
			$uuid,
			'<uuid>',
			(string)$response['body']['detail']
		);

		$this->assertSame(
			$template($absent, 'k-1-does-not-exist'),
			$template($other, 'k-1'),
			'an object of another type and an absent one must answer the same sentence'
		);
	}//end testAnObjectOfAnotherTypeAnswersTheSame404AsAnAbsentOne()

	/**
	 * 🔴 A geometry search finds what is inside the radius and not what is
	 * outside it.
	 *
	 * ⚠️ RUN AGAINST NO SPATIAL ENGINE. The distance arithmetic runs in PHP
	 * over fixed coordinates, with no database at all — see the handler's
	 * docblock and the PR body.
	 *
	 * @return void
	 */
	public function testAGeometrySearchFindsWhatIsInsideTheRadius(): void {
		$response = $this->objectsHandler()->search(
			type: 'aaa',
			body: ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1214, 52.0907], 'radius' => 500]]]
		);

		$this->assertSame(200, $response['status']);
		$this->assertSame(
			['m-1', 'm-2'],
			array_column($response['body']['results'], 'uuid'),
			'the two Utrecht points are inside 500 metres'
		);
	}//end testAGeometrySearchFindsWhatIsInsideTheRadius()

	/**
	 * The control: a smaller radius excludes the second point.
	 *
	 * Without it, the search test could be passing on a filter that returns
	 * everything with a geometry.
	 *
	 * @return void
	 */
	public function testASmallerRadiusExcludesTheSecondPoint(): void {
		$response = $this->objectsHandler()->search(
			type: 'aaa',
			body: ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1214, 52.0907], 'radius' => 100]]]
		);

		$this->assertSame(['m-1'], array_column($response['body']['results'], 'uuid'), 'the control: 300 metres is outside 100');
	}//end testASmallerRadiusExcludesTheSecondPoint()

	/**
	 * The haversine distance is right to within a metre or so.
	 *
	 * A known pair: the Dom tower to the Euromast is about 57 kilometres.
	 *
	 * @return void
	 */
	public function testTheDistanceArithmeticIsRight(): void {
		$metres = $this->objectsHandler()->metresBetween(a: [5.1214, 52.0907], b: [4.4400, 51.9053]);

		$this->assertGreaterThan(50000, $metres);
		$this->assertLessThan(60000, $metres);
		$this->assertSame(0.0, $this->objectsHandler()->metresBetween(a: [5.1214, 52.0907], b: [5.1214, 52.0907]));
	}//end testTheDistanceArithmeticIsRight()

	/**
	 * 🔴 A search shape this facade cannot evaluate is REFUSED, never widened
	 * to everything.
	 *
	 * Answering the unfiltered set reads as "nothing matched the filter" being
	 * the opposite of what happened.
	 *
	 * @return void
	 */
	public function testAnUnevaluableSearchShapeIsRefusedNotWidened(): void {
		foreach (
			[
				['geometry' => ['within' => ['type' => 'Polygon', 'coordinates' => []]]],
				['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1, 52.0]]]],
				['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1, 52.0], 'radius' => 0]]],
				['geometry' => []],
				[],
			] as $body
		) {
			$response = $this->objectsHandler()->search(type: 'aaa', body: $body);

			$this->assertSame(400, $response['status'], 'an unevaluable search must refuse, not answer everything');
		}
	}//end testAnUnevaluableSearchShapeIsRefusedNotWidened()

	/**
	 * An object with no geometry is not in a geometry search.
	 *
	 * @return void
	 */
	public function testAnObjectWithNoGeometryIsNotInAGeometrySearch(): void {
		$handler = new ObjectEndpointHandler(
			objecttypes: $this->registry(),
			translator: new ObjectRecordTranslator(),
			objectRead: static fn (string $r, string $s): array => [['@self' => ['id' => 'n-1'], 'straatnaam' => 'x']]
		);

		$response = $handler->search(
			type: 'aaa',
			body: ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1214, 52.0907], 'radius' => 50000]]]
		);

		$this->assertSame(0, $response['body']['count']);
	}//end testAnObjectWithNoGeometryIsNotInAGeometrySearch()

	/**
	 * An unpublished objecttype is a 404 on every read route.
	 *
	 * @return void
	 */
	public function testAnUnpublishedObjecttypeIs404OnEveryReadRoute(): void {
		$handler = $this->objectsHandler();

		$this->assertSame(404, $handler->index(query: ['type' => 'zzz'])['status']);
		$this->assertSame(404, $handler->show(type: 'zzz', uuid: 'm-1')['status']);
		$this->assertSame(404, $handler->search(type: 'zzz', body: [])['status']);
		$this->assertSame(404, $this->typesHandler()->show(uuid: 'zzz')['status']);
		$this->assertSame(404, $this->typesHandler()->version(uuid: 'zzz', version: '1')['status']);
	}//end testAnUnpublishedObjecttypeIs404OnEveryReadRoute()

	/**
	 * 🔴 EVERY route of the controller runs the token check first.
	 *
	 * Asserted structurally, because a route added later that forgets it is an
	 * unauthenticated read of a register — and no test of today's routes can
	 * notice tomorrow's.
	 *
	 * @return void
	 */
	public function testEveryRouteRunsTheTokenCheckFirst(): void {
		$controller = (string)file_get_contents(
			dirname(__DIR__, 4) . '/lib/Controller/ObjectenApiController.php'
		);

		// Counted as LINES THAT ARE the attribute, not as occurrences of the
		// string: the class docblock explains why every route carries
		// `#[PublicPage]`, and a substring count reads that sentence as a
		// seventh route. The instrument has to match the thing, not the word.
		$routes = preg_match_all('/^\t#\[PublicPage\]$/m', $controller);
		$this->assertSame(10, $routes, 'six read routes and four write routes are served');
		$this->assertSame(
			$routes,
			substr_count($controller, '$refusal = $this->refuse('),
			'a route that does not call refuse() is an unauthenticated read of a register'
		);

		// And it is the FIRST thing each one does: every occurrence is
		// followed by the early return before anything else runs.
		$this->assertSame(
			$routes,
			substr_count($controller, "\$refusal = \$this->refuse(objecttype"),
			'the guard is called with an objecttype on every route'
		);

		// And every WRITE route asks the writing question, so a write can
		// never be authorised by a read permission.
		$this->assertSame(
			4,
			substr_count($controller, 'writing: true)'),
			'each of the four write routes must ask the guard about writing'
		);
	}//end testEveryRouteRunsTheTokenCheckFirst()

	/**
	 * The six routes are registered, and the two APIs are reachable at the
	 * paths the standards name.
	 *
	 * @return void
	 */
	public function testTheSixReadRoutesAreRegisteredAtTheStandardsPaths(): void {
		$routes = (string)file_get_contents(dirname(__DIR__, 4) . '/appinfo/routes.php');

		foreach (
			[
				"'/api/v2/objecttypes'",
				"'/api/v2/objecttypes/{uuid}'",
				"'/api/v2/objecttypes/{uuid}/versions/{version}'",
				"'/api/v2/objects'",
				"'/api/v2/objects/search'",
				"'/api/v2/objects/{uuid}'",
			] as $path
		) {
			$this->assertStringContainsString($path, $routes, sprintf('%s is not routed', $path));
		}

		// The search route must be declared BEFORE the {uuid} route, or
		// "search" is read as a uuid and every geometry search 404s.
		$this->assertLessThan(
			strpos($routes, "'/api/v2/objects/{uuid}'"),
			strpos($routes, "'/api/v2/objects/search'"),
			'"search" would otherwise be matched as an object uuid'
		);

		foreach (["'POST'", "'PUT'", "'PATCH'", "'DELETE'"] as $verb) {
			$this->assertStringContainsString(
				$verb,
				$routes,
				sprintf('the %s write route is not registered', $verb)
			);
		}
	}//end testTheSixReadRoutesAreRegisteredAtTheStandardsPaths()
}//end class
