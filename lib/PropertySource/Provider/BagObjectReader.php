<?php

/**
 * One BAG object read by its identificatie, for a check that decides on the answer.
 *
 * @category Provider
 * @package  OCA\Integriq\PropertySource\Provider
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

namespace OCA\Integriq\PropertySource\Provider;

use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient;
use OCA\Integriq\Adapters\Pdok\PdokWfsClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether a pand, verblijfsobject or nummeraanduiding exists in the BAG.
 *
 * A pand and a verblijfsobject are read from PDOK's BAG WFS, a
 * nummeraanduiding from the PDOK Locatieserver, which is where PDOK publishes
 * it. Both are open data, so there is no setting behind the read.
 *
 * The answer tells three things apart that a guard must not confuse:
 *
 *  - `available` true, `found` true: the BAG holds the object.
 *  - `available` true, `found` false: the BAG answered and holds no such object.
 *  - `available` false: there is no answer, and `reason` says why. That never
 *    means "not found"; a caller that decides on it must treat it as unknown.
 *
 * `simulated` is true while integriq's PDOK clients run in their mock flavour
 * (`pdok.feature_flag` off). The answer then comes from a canned fixture and
 * says nothing about the real register.
 *
 * The answer is a plain array, so an app that reaches this class by name at
 * runtime needs none of integriq's types.
 *
 * @spec openspec/changes/bag-object-read/specs/registry-field-source/spec.md#requirement-a-bag-object-is-read-by-its-identificatie-and-an-unanswered-read-is-not-a-missing-object
 */
class BagObjectReader {
	/**
	 * The PDOK WFS dataset that holds pand and verblijfsobject.
	 */
	public const DATASET = 'bag';

	/**
	 * The object types this reader answers, with the two digits a BAG
	 * identificatie carries at positions 5 and 6 for that type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = ['pand' => '10', 'verblijfsobject' => '01', 'nummeraanduiding' => '20'];

	/**
	 * The identificatie is not a 16-digit BAG id of the asked type.
	 */
	public const REASON_INVALID_IDENTIFIER = 'invalid-identifier';

	/**
	 * The asked type is not one this reader answers.
	 */
	public const REASON_UNKNOWN_TYPE = 'unknown-type';

	/**
	 * PDOK did not answer.
	 */
	public const REASON_UNREACHABLE = 'unreachable';

	/**
	 * PDOK answered, but about another object.
	 */
	public const REASON_UNCLEAR = 'unclear-answer';

	/**
	 * Constructor.
	 *
	 * @param PdokWfsClient $wfs PDOK WFS client (pand, verblijfsobject).
	 * @param PdokGeocodingClient $geocoding PDOK Locatieserver client (nummeraanduiding).
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly PdokWfsClient $wfs,
		private readonly PdokGeocodingClient $geocoding,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read one BAG object by its identificatie.
	 *
	 * @param string $type One of `pand`, `verblijfsobject`, `nummeraanduiding`.
	 * @param string $identificatie The 16-digit BAG identificatie.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool,object:array<string,mixed>|null}
	 *
	 * @spec openspec/changes/bag-object-read/specs/registry-field-source/spec.md#requirement-a-bag-object-is-read-by-its-identificatie-and-an-unanswered-read-is-not-a-missing-object
	 */
	public function read(string $type, string $identificatie): array {
		if (isset(self::TYPES[$type]) === false) {
			return $this->notAvailable(type: $type, reason: self::REASON_UNKNOWN_TYPE, simulated: false);
		}

		if (preg_match('/^\d{4}' . self::TYPES[$type] . '\d{10}$/', $identificatie) !== 1) {
			return $this->notAvailable(type: $type, reason: self::REASON_INVALID_IDENTIFIER, simulated: false);
		}

		if ($type === 'nummeraanduiding') {
			return $this->readAddressDesignation(identificatie: $identificatie);
		}

		return $this->readFeature(type: $type, identificatie: $identificatie);
	}//end read()

	/**
	 * A pand or verblijfsobject from the BAG WFS.
	 *
	 * @param string $type `pand` or `verblijfsobject`.
	 * @param string $identificatie The BAG identificatie.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool,object:array<string,mixed>|null}
	 */
	private function readFeature(string $type, string $identificatie): array {
		$simulated = $this->wfs->flavour() === 'mock';

		try {
			$collection = $this->wfs->getFeature(
				dataset: self::DATASET,
				featureType: self::DATASET . ':' . $type,
				count: 2,
				filterFields: ['identificatie' => $identificatie]
			);
		} catch (Throwable) {
			return $this->notAvailable(type: $type, reason: self::REASON_UNREACHABLE, simulated: $simulated);
		}

		$features = ($collection['features'] ?? null);
		if (($collection['unanswered'] ?? false) === true || is_array($features) === false) {
			return $this->notAvailable(type: $type, reason: self::REASON_UNREACHABLE, simulated: $simulated);
		}

		if ($features === []) {
			return $this->answered(type: $type, simulated: $simulated, object: null);
		}

		foreach ($features as $feature) {
			$properties = [];
			if (is_array($feature) === true) {
				$properties = (array)($feature['properties'] ?? []);
			}

			if ((string)($properties['identificatie'] ?? '') === $identificatie) {
				return $this->answered(
					type: $type,
					simulated: $simulated,
					object: ['type' => $type, 'identificatie' => $identificatie, 'attributes' => $properties]
				);
			}
		}

		return $this->notAvailable(type: $type, reason: self::REASON_UNCLEAR, simulated: $simulated);
	}//end readFeature()

	/**
	 * A nummeraanduiding from the PDOK Locatieserver.
	 *
	 * @param string $identificatie The BAG identificatie.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool,object:array<string,mixed>|null}
	 */
	private function readAddressDesignation(string $identificatie): array {
		$type = 'nummeraanduiding';
		$simulated = $this->geocoding->flavour() === 'mock';

		try {
			$addresses = $this->geocoding->find('nummeraanduiding_id:' . $identificatie, 2);
		} catch (Throwable) {
			$addresses = null;
		}

		if ($addresses === null) {
			return $this->notAvailable(type: $type, reason: self::REASON_UNREACHABLE, simulated: $simulated);
		}

		if ($addresses === []) {
			return $this->answered(type: $type, simulated: $simulated, object: null);
		}

		foreach ($addresses as $address) {
			if ((string)($address['bagAddressId'] ?? '') === $identificatie) {
				return $this->answered(
					type: $type,
					simulated: $simulated,
					object: ['type' => $type, 'identificatie' => $identificatie, 'attributes' => $address]
				);
			}
		}

		return $this->notAvailable(type: $type, reason: self::REASON_UNCLEAR, simulated: $simulated);
	}//end readAddressDesignation()

	/**
	 * The answer for a question PDOK answered.
	 *
	 * @param string $type The asked type, for the log line.
	 * @param bool $simulated Whether the answer came from a mock client.
	 * @param array<string,mixed>|null $object The object, null when the BAG holds none.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool,object:array<string,mixed>|null}
	 */
	private function answered(string $type, bool $simulated, ?array $object): array {
		$outcome = 'not-found';
		if ($object !== null) {
			$outcome = 'found';
		}

		$this->logger->info('property-source.bag.object.read', ['type' => $type, 'outcome' => $outcome, 'simulated' => $simulated]);

		return ['available' => true, 'reason' => '', 'found' => $object !== null, 'simulated' => $simulated, 'object' => $object];
	}//end answered()

	/**
	 * The answer for a question that was not answered.
	 *
	 * @param string $type The asked type, for the log line.
	 * @param string $reason One of the REASON_ constants.
	 * @param bool $simulated Whether the client is a mock.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool,object:array<string,mixed>|null}
	 */
	private function notAvailable(string $type, string $reason, bool $simulated): array {
		$this->logger->info('property-source.bag.object.read', ['type' => $type, 'outcome' => $reason, 'simulated' => $simulated]);

		return ['available' => false, 'reason' => $reason, 'found' => false, 'simulated' => $simulated, 'object' => null];
	}//end notAvailable()
}//end class
