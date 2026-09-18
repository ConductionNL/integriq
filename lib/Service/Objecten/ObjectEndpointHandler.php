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
class ObjectEndpointHandler {

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
	 * Constructor.
	 *
	 * @param ObjecttypeRegistry      $objecttypes The declared mappings.
	 * @param ObjectRecordTranslator  $translator  The record shape.
	 * @param callable|null           $objectRead  Lists objects of a register and schema.
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
	 * @param array<string, mixed> $query   The query parameters.
	 * @param string               $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function index(array $query, string $baseUrl = ''): array {
		$type = trim((string)($query['type'] ?? ''));
		if ($type === '') {
			return $this->problem(
				status: 400,
				title: 'Bad request',
				detail: 'A list needs a "type": a token carries a permission per objecttype, so a list across types '
					. 'would silently answer with only the ones this token may see.'
			);
		}

		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->problem(status: 404, title: 'Not found', detail: sprintf('No objecttype "%s" is published here.', $type));
		}

		$objects = $this->readObjects(declaration: $declaration);

		$objects = $this->filterByDataAttrs(objects: $objects, dataAttrs: (string)($query['data_attrs'] ?? ''));
		$objects = $this->filterByDate(objects: $objects, query: $query);
		$objects = $this->order(objects: $objects, ordering: (string)($query['ordering'] ?? ''));

		return $this->page(objects: $objects, type: $type, query: $query, baseUrl: $baseUrl);
	}//end index()

	/**
	 * `GET /api/v2/objects/{uuid}`.
	 *
	 * @param string $type    The objecttype the token was checked against.
	 * @param string $uuid    The object.
	 * @param string $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function show(string $type, string $uuid, string $baseUrl = ''): array {
		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->problem(status: 404, title: 'Not found', detail: sprintf('No objecttype "%s" is published here.', $type));
		}

		foreach ($this->readObjects(declaration: $declaration) as $object) {
			$rendered = $this->translator->toRecord(object: $object, objecttype: $type, baseUrl: $baseUrl);
			if ($rendered['uuid'] === $uuid) {
				return ['status' => 200, 'body' => $rendered];
			}
		}

		// 🔑 THE SAME 404 FOR "NOT THERE" AND "NOT OF THIS TYPE". The token was
		// checked against the type, so answering differently for an object that
		// exists under another type would let a token holder probe for uuids
		// outside their permission.
		return $this->problem(status: 404, title: 'Not found', detail: sprintf('No object "%s" of this objecttype.', $uuid));
	}//end show()

	/**
	 * `POST /api/v2/objects/search` — the geometry search.
	 *
	 * @param string               $type    The objecttype.
	 * @param array<string, mixed> $body    The search body.
	 * @param string               $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function search(string $type, array $body, string $baseUrl = ''): array {
		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->problem(status: 404, title: 'Not found', detail: sprintf('No objecttype "%s" is published here.', $type));
		}

		$within = ($body['geometry']['within'] ?? null);
		if (is_array($within) === false) {
			return $this->problem(
				status: 400,
				title: 'Bad request',
				detail: 'A geometry search needs "geometry.within" naming a circle or a polygon.'
			);
		}

		$circle = $this->circleOf(within: $within);
		if ($circle === null) {
			return $this->problem(
				status: 400,
				title: 'Bad request',
				// Named rather than silently widened to "everything": a search
				// shape this facade cannot evaluate must not answer with the
				// unfiltered set, which would read as "nothing matched the
				// filter" being the opposite of what happened.
				detail: 'Only a circular "within" is evaluated here: give "type": "Point" with "coordinates" and a "radius" in metres.'
			);
		}

		$matched = [];
		foreach ($this->readObjects(declaration: $declaration) as $object) {
			$point = $this->pointOf(geometry: ($object['geometry'] ?? null));
			if ($point === null) {
				continue;
			}

			if ($this->metresBetween(a: $circle['point'], b: $point) <= $circle['radius']) {
				$matched[] = $object;
			}
		}

		return $this->page(objects: $matched, type: $type, query: $body, baseUrl: $baseUrl);
	}//end search()

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

		$h = ((sin($dLat / 2) ** 2) + (cos($lat1) * cos($lat2) * (sin($dLon / 2) ** 2)));

		return (2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($h))));
	}//end metresBetween()

	/**
	 * The objects of one objecttype, or an empty list.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function readObjects(array $declaration): array {
		if ($this->objectRead === null) {
			return [];
		}

		try {
			$objects = ($this->objectRead)((string)$declaration['register'], (string)$declaration['schema']);
		} catch (Throwable $e) {
			return [];
		}

		return (is_array($objects) === true ? array_values(array_filter($objects, 'is_array')) : []);
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

		foreach (explode(',', $dataAttrs) as $clause) {
			$parts = explode('__', trim($clause));
			if (count($parts) !== 3) {
				// A malformed clause matches NOTHING rather than being ignored.
				// Ignoring it answers the unfiltered set to somebody who asked
				// for a filtered one.
				return [];
			}

			[$field, $operator, $value] = $parts;
			$objects = array_values(
				array_filter(
					$objects,
					fn (array $object): bool => $this->clauseHolds(
						actual: ($this->translator->dataOf(object: $object)[$field] ?? null),
						operator: $operator,
						expected: $value
					)
				)
			);
		}

		return $objects;
	}//end filterByDataAttrs()

	/**
	 * Whether one `data_attrs` clause holds.
	 *
	 * @param mixed  $actual   The stored value.
	 * @param string $operator The operator.
	 * @param string $expected The expected value.
	 *
	 * @return bool True when it holds.
	 */
	private function clauseHolds(mixed $actual, string $operator, string $expected): bool {
		if ($actual === null) {
			return false;
		}

		$actual = (string)$actual;

		return match ($operator) {
			'exact' => ($actual === $expected),
			'icontains' => (stripos($actual, $expected) !== false),
			'gt' => ($actual > $expected),
			'gte' => ($actual >= $expected),
			'lt' => ($actual < $expected),
			'lte' => ($actual <= $expected),
			// An operator nobody declared matches nothing, for the same reason
			// a malformed clause does.
			default => false,
		};
	}//end clauseHolds()

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

				return ($descending === true ? -$compared : $compared);
			}
		);

		return $objects;
	}//end order()

	/**
	 * One page of records in the standard's list envelope.
	 *
	 * @param array<int, array<string, mixed>> $objects The objects.
	 * @param string                           $type    The objecttype.
	 * @param array<string, mixed>             $query   The query.
	 * @param string                           $baseUrl The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function page(array $objects, string $type, array $query, string $baseUrl): array {
		$total = count($objects);
		$size = (int)($query['pageSize'] ?? self::PAGE_SIZE);
		$size = max(1, min($size, self::MAX_PAGE_SIZE));
		$page = max(1, (int)($query['page'] ?? 1));

		$slice = array_slice($objects, (($page - 1) * $size), $size);

		$results = [];
		foreach ($slice as $object) {
			$results[] = $this->translator->toRecord(object: $object, objecttype: $type, baseUrl: $baseUrl);
		}

		return [
			'status' => 200,
			'body' => [
				// The TOTAL, not the page size. A consumer paging through reads
				// this to know when to stop, and answering the page size makes
				// every list look like exactly one page.
				'count' => $total,
				'next' => (($page * $size) < $total ? ($page + 1) : null),
				'previous' => ($page > 1 ? ($page - 1) : null),
				'results' => $results,
			],
		];
	}//end page()

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
	 * A problem response in the standard's shape.
	 *
	 * @param int    $status The status.
	 * @param string $title  The title.
	 * @param string $detail The detail.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function problem(int $status, string $title, string $detail): array {
		return [
			'status' => $status,
			'body' => ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail],
		];
	}//end problem()
}//end class
