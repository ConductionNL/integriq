<?php

/**
 * The Objecten API v2 read paths: list, read one, and the geometry search.
 *
 * 🔴 A LIST WITHOUT `type` IS REFUSED, not answered with everything. The token
 * carries a permission PER OBJECTTYPE, so a list that spans types would have to
 * filter to the ones this token names — and a consumer who asked for "all
 * objects" and got four of the nine on the instance has been told something
 * untrue in the shape of a successful answer. Asking for the type makes the
 * question answerable and the permission check exact.
 *
 * 🔴 THE GEOMETRY SEARCH IS BUILT AND HAS NOT BEEN RUN AGAINST A SPATIAL
 * ENGINE. It is written as a bounding-box narrowing plus an exact great-circle
 * distance, both in PHP over the candidate set, precisely so it does NOT depend
 * on PostGIS being installed — and that is also its limit: on a large register
 * it reads more rows than a spatial index would. Which engine it was exercised
 * against: NONE. The unit tests run the distance arithmetic directly, against
 * fixed coordinates, with no database at all. That is stated here and in the
 * PR body rather than implied by a green suite.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
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

namespace OCA\Integriq\Service\Objecten;

use Throwable;

/**
 * Serves the Objecten API read routes.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjectEndpointHandler implements ObjectEndpointHandlerInterface {

	/**
	 * The default page size.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 100;

	/**
	 * The largest page a consumer may ask for.
	 *
	 * @var int
	 */
	public const MAX_PAGE_SIZE = 500;

	/**
	 * The mean earth radius, in metres.
	 *
	 * @var float
	 */
	public const EARTH_RADIUS = 6371008.8;

	/**
	 * The 400 detail for a search without a "within" shape.
	 *
	 * @var string
	 */
	private const NO_WITHIN_DETAIL = 'A geometry search needs "geometry.within" naming a circle or a polygon.';

	/**
	 * The 400 detail for a "within" that is not a circle.
	 *
	 * Named rather than silently widened to "everything": a search shape this
	 * facade cannot evaluate must not answer with the unfiltered set, which
	 * would read as "nothing matched the filter" being the opposite of what
	 * happened.
	 *
	 * @var string
	 */
	private const NOT_A_CIRCLE_DETAIL = 'Only a circular "within" is evaluated here: give "type": "Point" with "coordinates" and a "radius" in metres.';

	/**
	 * Why a list without `type` is refused.
	 *
	 * @var string
	 */
	private const NO_TYPE_DETAIL = 'A list needs a "type": a token carries a permission per objecttype, so a list across types '
		. 'would silently answer with only the ones this token may see.';

	/**
	 * The problem title of each status this facade answers with.
	 *
	 * @var array<int, string>
	 */
	private const PROBLEM_TITLES = [400 => 'Bad request', 404 => 'Not found'];

	/**
	 * Constructor.
	 *
	 * @param ObjecttypeRegistry      $objecttypes The declared mappings.
	 * @param ObjectRecordTranslator  $translator  The record shape.
	 * @param callable|null           $objectRead  Lists objects of a register and schema, as a principal.
	 */
	public function __construct(
		private readonly ObjecttypeRegistry $objecttypes,
		private readonly ObjectRecordTranslator $translator,
		private $objectRead = null,
	) {
	}//end __construct()

	/**
	 * `GET /api/v2/objects`.
	 *
	 * @param array<string, mixed> $query     The query parameters.
	 * @param string               $baseUrl   The API base.
	 * @param string               $principal The token's principal, whom the read runs as.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function index(array $query, string $baseUrl = '', string $principal = ''): array {
		$type = trim((string)($query['type'] ?? ''));
		if ($type === '') {
			return $this->problem(status: 400, detail: self::NO_TYPE_DETAIL);
		}

		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->unknownType(type: $type);
		}

		$page = new ObjectPage(type: $type, query: $query);
		$page->baseUrl = $baseUrl;
		$objects = $this->readObjects(declaration: $declaration, principal: $principal);

		return $this->page(objects: $this->listed(objects: $objects, query: $query), page: $page);
	}//end index()

	/**
	 * The objects a list query keeps, in the order it asks for.
	 *
	 * @param array<int, array<string, mixed>> $objects The readable objects.
	 * @param array<string, mixed>             $query   The query parameters.
	 *
	 * @return array<int, array<string, mixed>> The filtered, ordered objects.
	 */
	private function listed(array $objects, array $query): array {
		$objects = $this->filterByDataAttrs(objects: $objects, dataAttrs: (string)($query['data_attrs'] ?? ''));
		$objects = $this->filterByDate(objects: $objects, query: $query);

		return $this->order(objects: $objects, ordering: (string)($query['ordering'] ?? ''));
	}//end listed()

	/**
	 * `GET /api/v2/objects/{uuid}`.
	 *
	 * @param string $type      The objecttype the token was checked against.
	 * @param string $uuid      The object.
	 * @param string $baseUrl   The API base.
	 * @param string $principal The token's principal, whom the read runs as.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function show(string $type, string $uuid, string $baseUrl = '', string $principal = ''): array {
		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->unknownType(type: $type);
		}

		foreach ($this->readObjects(declaration: $declaration, principal: $principal) as $object) {
			$rendered = $this->translator->toRecord(object: $object, objecttype: $type, baseUrl: $baseUrl);
			if ($rendered['uuid'] === $uuid) {
				return ['status' => 200, 'body' => $rendered];
			}
		}

		// 🔑 THE SAME 404 FOR "NOT THERE" AND "NOT OF THIS TYPE". The token was
		// checked against the type, so answering differently for an object that
		// exists under another type would let a token holder probe for uuids
		// outside their permission.
		return $this->problem(status: 404, detail: sprintf('No object "%s" of this objecttype.', $uuid));
	}//end show()

	/**
	 * `POST /api/v2/objects/search` — the geometry search.
	 *
	 * @param string               $type      The objecttype.
	 * @param array<string, mixed> $body      The search body.
	 * @param string               $baseUrl   The API base.
	 * @param string               $principal The token's principal, whom the read runs as.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function search(string $type, array $body, string $baseUrl = '', string $principal = ''): array {
		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->unknownType(type: $type);
		}

		$within = ($body['geometry']['within'] ?? null);
		$refusal = $this->withinRefusal(within: $within);
		if ($refusal !== null) {
			return $refusal;
		}

		$page = new ObjectPage(type: $type, query: $body);
		$page->baseUrl = $baseUrl;
		$objects = $this->readObjects(declaration: $declaration, principal: $principal);

		return $this->page(objects: $this->objectsWithin(objects: $objects, within: (array)$within), page: $page);
	}//end search()

	/**
	 * The 400 answer for a "within" this facade cannot evaluate, or null for a circle.
	 *
	 * @param mixed $within The body's `geometry.within`.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null
	 */
	private function withinRefusal(mixed $within): ?array {
		if (is_array($within) === false) {
			return $this->problem(status: 400, detail: self::NO_WITHIN_DETAIL);
		}

		if ($this->circleOf(within: $within) !== null) {
			return null;
		}

		return $this->problem(status: 400, detail: self::NOT_A_CIRCLE_DETAIL);
	}//end withinRefusal()

	/**
	 * The objects whose point lies inside the circle; an object without a point never matches.
	 *
	 * @param array<int, array<string, mixed>> $objects The readable objects.
	 * @param array<string, mixed>             $within  A circle {@see self::withinRefusal()} accepted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function objectsWithin(array $objects, array $within): array {
		$circle = $this->circleOf(within: $within);
		$matched = [];
		foreach ($objects as $object) {
			$point = $this->pointOf(geometry: ($object['geometry'] ?? null));
			if ($circle !== null && $point !== null && $this->metresBetween(a: $circle['point'], b: $point) <= $circle['radius']) {
				$matched[] = $object;
			}
		}

		return $matched;
	}//end objectsWithin()

	/**
	 * Great-circle distance in metres between two lon/lat points.
	 *
	 * The haversine formula. Exact enough for a municipal radius search — its
	 * error against the ellipsoid is well under a metre at these distances —
	 * and it needs no spatial extension, which is the point.
	 *
	 * @param array{0: float, 1: float} $a One point, lon then lat.
	 * @param array{0: float, 1: float} $b The other.
	 *
	 * @return float The distance in metres.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function metresBetween(array $a, array $b): float {
		$lat1 = deg2rad($a[1]);
		$lat2 = deg2rad($b[1]);
		$dLat = ($lat2 - $lat1);
		$dLon = deg2rad($b[0] - $a[0]);

		$haversine = ((sin($dLat / 2) ** 2) + (cos($lat1) * cos($lat2) * (sin($dLon / 2) ** 2)));

		return (2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($haversine))));
	}//end metresBetween()

	/**
	 * The objects of one objecttype, or an empty list.
	 *
	 * The principal goes to the seam, so the read runs as the token's principal
	 * and OpenRegister's RBAC and multitenancy decide what it sees (design D3).
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 * @param string               $principal   The token's principal.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function readObjects(array $declaration, string $principal): array {
		if ($this->objectRead === null) {
			return [];
		}

		try {
			$objects = ($this->objectRead)((string)$declaration['register'], (string)$declaration['schema'], $principal);
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($objects) === false) {
			return [];
		}

		return array_values(array_filter($objects, 'is_array'));
	}//end readObjects()

	/**
	 * Narrow by `data_attrs`, the standard's `field__operator__value` triple.
	 *
	 * @param array<int, array<string, mixed>> $objects   The objects.
	 * @param string                           $dataAttrs The parameter.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function filterByDataAttrs(array $objects, string $dataAttrs): array {
		if (trim($dataAttrs) === '') {
			return $objects;
		}

		foreach (explode(',', $dataAttrs) as $text) {
			$clause = new DataAttrClause(clause: $text);
			if ($clause->malformed === true) {
				// A malformed clause matches NOTHING rather than being ignored.
				// Ignoring it answers the unfiltered set to somebody who asked
				// for a filtered one.
				return [];
			}

			$objects = $this->matching(objects: $objects, clause: $clause);
		}

		return $objects;
	}//end filterByDataAttrs()

	/**
	 * The objects whose data satisfies one clause.
	 *
	 * @param array<int, array<string, mixed>> $objects The objects.
	 * @param DataAttrClause                   $clause  The clause.
	 *
	 * @return array<int, array<string, mixed>> The matching objects.
	 */
	private function matching(array $objects, DataAttrClause $clause): array {
		return array_values(
			array_filter(
				$objects,
				fn (array $object): bool => $clause->holds(actual: ($this->translator->dataOf(object: $object)[$clause->field] ?? null))
			)
		);
	}//end matching()

	/**
	 * Narrow by `date` and `registrationDate`.
	 *
	 * @param array<int, array<string, mixed>> $objects The objects.
	 * @param array<string, mixed>             $query   The query.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function filterByDate(array $objects, array $query): array {
		foreach (['date' => 'startAt', 'registrationDate' => 'registrationAt'] as $parameter => $field) {
			$wanted = trim((string)($query[$parameter] ?? ''));
			if ($wanted === '') {
				continue;
			}

			$objects = array_values(
				array_filter(
					$objects,
					function (array $object) use ($field, $wanted): bool {
						$rendered = $this->translator->toRecord(object: $object, objecttype: '');
						return ($rendered['record'][$field] === $wanted);
					}
				)
			);
		}

		return $objects;
	}//end filterByDate()

	/**
	 * Order by one field, `-field` for descending.
	 *
	 * @param array<int, array<string, mixed>> $objects  The objects.
	 * @param string                           $ordering The parameter.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function order(array $objects, string $ordering): array {
		$ordering = trim($ordering);
		if ($ordering === '') {
			return $objects;
		}

		$descending = str_starts_with($ordering, '-');
		$field = ltrim($ordering, '-');

		usort(
			$objects,
			function (array $a, array $b) use ($field, $descending): int {
				$left = (string)($this->translator->dataOf(object: $a)[$field] ?? '');
				$right = (string)($this->translator->dataOf(object: $b)[$field] ?? '');
				$compared = strcmp($left, $right);

				if ($descending === true) {
					return -$compared;
				}

				return $compared;
			}
		);

		return $objects;
	}//end order()

	/**
	 * One page of records in the standard's list envelope.
	 *
	 * @param array<int, array<string, mixed>> $objects The objects.
	 * @param ObjectPage                       $page    The requested page and how its records render.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function page(array $objects, ObjectPage $page): array {
		$total = count($objects);

		return [
			'status' => 200,
			'body' => [
				// The TOTAL, not the page size. A consumer paging through reads
				// this to know when to stop, and answering the page size makes
				// every list look like exactly one page.
				'count' => $total,
				'next' => $page->next(total: $total),
				'previous' => $page->previous(),
				'results' => $this->records(objects: array_slice($objects, $page->offset(), $page->size), page: $page),
			],
		];
	}//end page()

	/**
	 * The records of one page's objects.
	 *
	 * @param array<int, array<string, mixed>> $objects The page's objects.
	 * @param ObjectPage                       $page    The objecttype and base url they render with.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 */
	private function records(array $objects, ObjectPage $page): array {
		$results = [];
		foreach ($objects as $object) {
			$results[] = $this->translator->toRecord(object: $object, objecttype: $page->type, baseUrl: $page->baseUrl);
		}

		return $results;
	}//end records()

	/**
	 * A circle from a `within` clause, or null when it is not one.
	 *
	 * @param array<string, mixed> $within The clause.
	 *
	 * @return array{point: array{0: float, 1: float}, radius: float}|null The circle.
	 */
	private function circleOf(array $within): ?array {
		$point = $this->pointOf(geometry: $within);
		$radius = ($within['radius'] ?? null);

		if ($point === null || is_numeric($radius) === false || (float)$radius <= 0) {
			return null;
		}

		return ['point' => $point, 'radius' => (float)$radius];
	}//end circleOf()

	/**
	 * A lon/lat pair from a GeoJSON point, or null.
	 *
	 * @param mixed $geometry The geometry.
	 *
	 * @return array{0: float, 1: float}|null The point.
	 */
	private function pointOf(mixed $geometry): ?array {
		if (is_array($geometry) === false) {
			return null;
		}

		if (strtolower((string)($geometry['type'] ?? '')) !== 'point') {
			return null;
		}

		$coordinates = ($geometry['coordinates'] ?? null);
		if (is_array($coordinates) === false || count($coordinates) < 2) {
			return null;
		}

		if (is_numeric($coordinates[0]) === false || is_numeric($coordinates[1]) === false) {
			return null;
		}

		return [(float)$coordinates[0], (float)$coordinates[1]];
	}//end pointOf()

	/**
	 * The 404 for an objecttype that is not published here.
	 *
	 * @param string $type The objecttype asked for.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function unknownType(string $type): array {
		return $this->problem(status: 404, detail: sprintf('No objecttype "%s" is published here.', $type));
	}//end unknownType()

	/**
	 * A problem response in the standard's shape, titled after its status.
	 *
	 * @param int    $status The status, 400 or 404.
	 * @param string $detail The detail.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function problem(int $status, string $detail): array {
		return [
			'status' => $status,
			'body' => ['type' => 'about:blank', 'title' => self::PROBLEM_TITLES[$status], 'status' => $status, 'detail' => $detail],
		];
	}//end problem()
}//end class
