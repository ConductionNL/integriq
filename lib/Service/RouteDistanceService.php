<?php

/**
 * Integriq RouteDistanceService.
 *
 * The road distance between two Dutch postcodes: both are placed through
 * PDOK, then the route planner linked to the app's route planner connection
 * is asked for the fastest road route. The answer is the one-way distance in
 * kilometres with one decimal, and the planner that gave it. Every outcome
 * is an answer or a structured refusal.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Geocode two postcodes and ask a linked route planner how far apart they are by road.
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
 */
class RouteDistanceService {

	/**
	 * A postcode PDOK cannot place.
	 *
	 * @var string
	 */
	public const UNKNOWN_POSTCODE = 'unknown-postcode';

	/**
	 * A mode the planner has no profile for.
	 *
	 * @var string
	 */
	public const UNSUPPORTED_MODE = 'unsupported-mode';

	/**
	 * A linked source that is not a route planner this service knows.
	 *
	 * @var string
	 */
	public const UNSUPPORTED_PROVIDER = 'unsupported-provider';

	/**
	 * PDOK gave no answer at all.
	 *
	 * @var string
	 */
	public const GEOCODER_UNAVAILABLE = 'geocoder-unavailable';

	/**
	 * The planner answered without a route.
	 *
	 * @var string
	 */
	public const NO_ROUTE = 'no-route';

	/**
	 * Each planner's profile per transport mode.
	 *
	 * @var array<string,array<string,string>>
	 */
	public const PROFILES = [
		'openrouteservice' => [
			'car' => 'driving-car',
			'bicycle' => 'cycling-regular',
			'e-bike' => 'cycling-electric',
			'walking' => 'foot-walking',
		],
		'tomtom' => [
			'car' => 'car',
			'bicycle' => 'bicycle',
			'e-bike' => 'bicycle',
			'walking' => 'pedestrian',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ConnectionCallService $connections Finds the linked planner and calls it.
	 * @param PdokGeocodingClient   $geocoder    Places a postcode.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function __construct(
		private readonly ConnectionCallService $connections,
		private readonly PdokGeocodingClient $geocoder,
	) {
	}//end __construct()

	/**
	 * The one-way road distance between two postcodes.
	 *
	 * @param string $app           The calling app id.
	 * @param string $fromPostcode  Where the route starts.
	 * @param string $toPostcode    Where the route ends.
	 * @param string $mode          `car`, `bicycle`, `e-bike` or `walking`.
	 * @param string $connectionKey The app's route planner connection.
	 *
	 * @return array<string,mixed> `distanceKmOneWay`, `routeProvider`, `fromMunicipality`, `toMunicipality`; or `refusal`.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function distance(
		string $app,
		string $fromPostcode,
		string $toPostcode,
		string $mode = 'car',
		string $connectionKey = 'route-planner',
	): array {
		$linked = $this->connections->linkedSource(app: $app, connectionKey: $connectionKey);
		if (isset($linked['refusal']) === true) {
			return $linked;
		}

		$provider = self::providerOf(source: $linked['source']);
		if ($provider === null) {
			return self::refusal(
				code: self::UNSUPPORTED_PROVIDER,
				reason: sprintf('The connection "%s" is not linked to a route planner.', $linked['title'])
			);
		}

		$profile = (self::PROFILES[$provider][$mode] ?? null);
		if ($profile === null) {
			return self::refusal(
				code: self::UNSUPPORTED_MODE,
				reason: sprintf('The route planner has no profile for the mode "%s".', $mode)
			);
		}

		$from = $this->place(postcode: $fromPostcode);
		if (isset($from['refusal']) === true) {
			return $from;
		}

		$to = $this->place(postcode: $toPostcode);
		if (isset($to['refusal']) === true) {
			return $to;
		}

		$request = self::plannerRequest(provider: $provider, profile: $profile, from: $from, to: $to);
		$answer  = $this->connections->call(
			app: $app,
			connectionKey: $connectionKey,
			method: 'GET',
			path: $request['path'],
			query: $request['query']
		);
		if (isset($answer['refusal']) === true) {
			return $answer;
		}

		$meters = null;
		if ($answer['status'] >= 200 && $answer['status'] < 300) {
			$meters = self::metersOf(provider: $provider, body: $answer['body']);
		}

		if ($meters === null) {
			return self::refusal(
				code: self::NO_ROUTE,
				reason: sprintf('The route planner gave no route (HTTP %d).', $answer['status'])
			);
		}

		return [
			'distanceKmOneWay' => round($meters / 1000, 1),
			'routeProvider' => $provider,
			'fromMunicipality' => $from['municipality'],
			'toMunicipality' => $to['municipality'],
		];
	}//end distance()

	/**
	 * A postcode as `1234 AB`, or null when it is not a Dutch postcode.
	 *
	 * @param string $postcode The postcode as given.
	 *
	 * @return string|null The normalised postcode.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public static function normalisePostcode(string $postcode): ?string {
		$compact = strtoupper((string)preg_replace('/\s+/', '', $postcode));
		if (preg_match('/^[1-9][0-9]{3}[A-Z]{2}$/', $compact) !== 1) {
			return null;
		}

		return substr($compact, 0, 4) . ' ' . substr($compact, 4);
	}//end normalisePostcode()

	/**
	 * Place a postcode through PDOK.
	 *
	 * @param string $postcode The postcode as given.
	 *
	 * @return array{longitude:float,latitude:float,municipality:string}|array{refusal:array{code:string,reason:string}}
	 */
	private function place(string $postcode): array {
		$normalised = self::normalisePostcode(postcode: $postcode);
		$unknown    = self::refusal(
			code: self::UNKNOWN_POSTCODE,
			reason: sprintf('The postcode %s cannot be placed.', trim($postcode))
		);
		if ($normalised === null) {
			return $unknown;
		}

		$entries = $this->geocoder->find(query: 'postcode:' . str_replace(' ', '', $normalised), rows: 5);
		if ($entries === null) {
			return self::refusal(
				code: self::GEOCODER_UNAVAILABLE,
				reason: sprintf('PDOK did not answer for the postcode %s.', $normalised)
			);
		}

		foreach ($entries as $entry) {
			$coordinates = ($entry['location']['coordinates'] ?? null);
			if (self::normalisePostcode(postcode: (string)($entry['postalCode'] ?? '')) !== $normalised
				|| is_array($coordinates) === false
				|| is_numeric($coordinates[0] ?? null) === false
				|| is_numeric($coordinates[1] ?? null) === false
			) {
				continue;
			}

			return [
				'longitude' => (float)$coordinates[0],
				'latitude' => (float)$coordinates[1],
				'municipality' => (string)($entry['addressLocality'] ?? ''),
			];
		}

		return $unknown;
	}//end place()

	/**
	 * Which planner a source is: its `configuration.routeProvider`, else its slug.
	 *
	 * @param ObjectEntity $source The linked source.
	 *
	 * @return string|null `openrouteservice`, `tomtom` or null.
	 */
	private static function providerOf(ObjectEntity $source): ?string {
		$data     = $source->getObject();
		$provider = strtolower((string)($data['configuration']['routeProvider'] ?? ''));
		if (isset(self::PROFILES[$provider]) === true) {
			return $provider;
		}

		$slug = strtolower((string)($data['slug'] ?? ''));
		foreach (array_keys(self::PROFILES) as $known) {
			if (str_contains($slug, $known) === true) {
				return $known;
			}
		}

		return null;
	}//end providerOf()

	/**
	 * The planner request for a route between two points.
	 *
	 * @param string                                  $provider The planner.
	 * @param string                                  $profile  The planner's profile for the mode.
	 * @param array{longitude:float,latitude:float}   $from     The start.
	 * @param array{longitude:float,latitude:float}   $to       The end.
	 *
	 * @return array{path:string,query:array<string,string>}
	 */
	private static function plannerRequest(string $provider, string $profile, array $from, array $to): array {
		if ($provider === 'tomtom') {
			return [
				'path' => sprintf(
					'/1/calculateRoute/%.6F,%.6F:%.6F,%.6F/json',
					$from['latitude'],
					$from['longitude'],
					$to['latitude'],
					$to['longitude']
				),
				'query' => ['travelMode' => $profile, 'routeType' => 'fastest'],
			];
		}

		return [
			'path' => '/v2/directions/' . $profile,
			'query' => [
				'start' => sprintf('%.6F,%.6F', $from['longitude'], $from['latitude']),
				'end' => sprintf('%.6F,%.6F', $to['longitude'], $to['latitude']),
			],
		];
	}//end plannerRequest()

	/**
	 * The route length in metres from a planner's answer, or null without one.
	 *
	 * @param string $provider The planner.
	 * @param mixed  $body     The decoded answer.
	 *
	 * @return float|null The length in metres.
	 */
	private static function metersOf(string $provider, mixed $body): ?float {
		if (is_array($body) === false) {
			return null;
		}

		$meters = ($body['routes'][0]['summary']['lengthInMeters'] ?? null);
		if ($provider === 'openrouteservice') {
			$meters = ($body['features'][0]['properties']['summary']['distance'] ?? $body['routes'][0]['summary']['distance'] ?? null);
		}

		if (is_numeric($meters) === false || (float)$meters < 0) {
			return null;
		}

		return (float)$meters;
	}//end metersOf()

	/**
	 * A structured refusal.
	 *
	 * @param string $code   The code.
	 * @param string $reason The reason.
	 *
	 * @return array{refusal:array{code:string,reason:string}}
	 */
	private static function refusal(string $code, string $reason): array {
		return ['refusal' => ['code' => $code, 'reason' => $reason]];
	}//end refusal()
}//end class
