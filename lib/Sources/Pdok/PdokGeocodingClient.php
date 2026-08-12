<?php

/**
 * OpenConnector PDOK Geocoding Source Client (dormant).
 *
 * Source-pattern facade over the lower-level abstract
 * `\OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClient` family. Ships
 * dormant: every call logs the intent and returns a deterministic canned
 * response (one Conduction HQ entry — Lauriergracht, Amsterdam) so
 * consuming apps (procest `gis-integration`, zaakportaal address
 * autocompletion, `migrate-pdok-to-openconnector` shim) can develop and
 * test against a stable surface without hitting the live PDOK
 * Locatieserver.
 *
 * Lives under `lib/Sources/Pdok/` so it can be discovered by the openconnector
 * Source registry (Source row `pdok-geocoding`, category=`geo`). The active
 * HTTP implementation is
 * `\OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClientHttp`, which delegates
 * to the existing `\OCA\OpenConnector\Connectors\PdokConnector`. See
 * {@see \OCA\OpenConnector\Adapters\Pdok\PdokSourceAdapter} for the
 * `pdok.feature_flag` activation steps.
 *
 * @category Source
 * @package  OCA\OpenConnector\Sources\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.openconnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Sources\Pdok;

use OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClient as AdapterGeocodingClient;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant source adapter for the PDOK Locatieserver (adres → coords resolution).
 *
 * Registered as openconnector Source row id `pdok-geocoding`, category `geo`.
 * Until `pdok.feature_flag` is flipped to `1`, every method returns the canned
 * response below and logs a single debug entry so operators can verify the
 * wiring without hitting the upstream.
 *
 * The dormant `suggest()` / `lookup()` shape matches the normalized
 * PostalAddress shape documented in the
 * `hydra/shared-pdok-via-openconnector` design.md so downstream code can
 * exercise its branches off real data.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
final class PdokGeocodingClient {
	/**
	 * App id used for IAppConfig look-ups.
	 */
	public const APP_ID = 'openconnector';

	/**
	 * App-config key for the dormant-flag toggle (shared with WMS + WFS).
	 */
	public const FLAG_KEY = 'pdok.feature_flag';

	/**
	 * Canonical Source row id this adapter is registered under.
	 */
	public const SOURCE_ID = 'pdok-geocoding';

	/**
	 * Source category — `geo` per the connector-categories taxonomy.
	 */
	public const SOURCE_CATEGORY = 'geo';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App-config service (feature-flag check).
	 * @param LoggerInterface $logger Structured logger.
	 * @param AdapterGeocodingClient $geocodingClient Resolved geocoding client (mock or http).
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		private readonly AdapterGeocodingClient $geocodingClient,
	) {
	}//end __construct()

	/**
	 * Whether the live PDOK Locatieserver is enabled by the operator.
	 *
	 * @return bool True when `pdok.feature_flag` is `1`/`true`.
	 */
	public function isActive(): bool {
		$raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isActive()

	/**
	 * Free-text autocomplete suggest call.
	 *
	 * @param string $query Free-text query.
	 * @param int $rows Maximum rows.
	 *
	 * @return array<int,array<string,mixed>> Normalized PostalAddress entries.
	 */
	public function suggest(string $query, int $rows = 10): array {
		$this->logger->debug(
			'pdok-geocoding.suggest',
			[
				'source' => self::SOURCE_ID,
				'category' => self::SOURCE_CATEGORY,
				'query' => $query,
				'rows' => $rows,
				'active' => $this->isActive(),
				'flavour' => $this->geocodingClient->flavour(),
			]
		);

		return $this->geocodingClient->suggest($query, $rows);
	}//end suggest()

	/**
	 * Lookup a specific PDOK identifier.
	 *
	 * Resolves `adres-…` / `weg-…` / `woonplaats-…` ids to a single
	 * canonical PostalAddress.
	 *
	 * @param string $pdokId PDOK Locatieserver id.
	 *
	 * @return array<string,mixed>|null Normalized PostalAddress, or null if not found.
	 */
	public function lookup(string $pdokId): ?array {
		$this->logger->debug(
			'pdok-geocoding.lookup',
			[
				'source' => self::SOURCE_ID,
				'category' => self::SOURCE_CATEGORY,
				'pdokId' => $pdokId,
				'active' => $this->isActive(),
				'flavour' => $this->geocodingClient->flavour(),
			]
		);

		return $this->geocodingClient->lookup($pdokId);
	}//end lookup()

	/**
	 * Reverse-geocode a lat/lng pair to candidate addresses.
	 *
	 * @param float $latitude Latitude (WGS84).
	 * @param float $longitude Longitude (WGS84).
	 *
	 * @return array<int,array<string,mixed>> Normalized candidates.
	 */
	public function reverse(float $latitude, float $longitude): array {
		$this->logger->debug(
			'pdok-geocoding.reverse',
			[
				'source' => self::SOURCE_ID,
				'category' => self::SOURCE_CATEGORY,
				'lat' => $latitude,
				'lng' => $longitude,
				'active' => $this->isActive(),
				'flavour' => $this->geocodingClient->flavour(),
			]
		);

		return $this->geocodingClient->reverse($latitude, $longitude);
	}//end reverse()

	/**
	 * Source-registry descriptor for the openconnector Source row.
	 *
	 * @return array<string,mixed>
	 */
	public static function sourceDescriptor(): array {
		return [
			'id' => self::SOURCE_ID,
			'name' => 'PDOK Locatieserver',
			'description' => 'Publieke Dienstverlening Op de Kaart — adres → coordinaten resolutie (geocoding) via Locatieserver v3.1.',
			'category' => self::SOURCE_CATEGORY,
			'subCategory' => 'geocoding',
			'adapterClass' => self::class,
			'location' => 'https://api.pdok.nl/bzk/locatieserver/search/v3_1',
			'type' => 'rest',
			'auth' => 'none',
			'isEnabled' => false,
			'documentation' => 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/ui/',
			'reference' => 'pdok.feature_flag',
		];
	}//end sourceDescriptor()
}//end class
