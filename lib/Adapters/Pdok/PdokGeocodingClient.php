<?php

/**
 * Abstract PDOK Geocoding adapter (Locatieserver v3.1).
 *
 * Defines the polymorphic surface — `suggest()`, `lookup()`, `reverse()`,
 * `flavour()` — that the {@see PdokGeocodingClientMock} (deterministic, no
 * network) and {@see PdokGeocodingClientHttp} (real outbound calls) flavours
 * implement. DI in {@see \OCA\OpenConnector\AppInfo\Application::register()}
 * resolves the abstract to one of those concrete implementations, gated on the
 * `pdok.feature_flag` app-config flag.
 *
 * @category Adapter
 * @package  OCA\OpenConnector\Adapters\Pdok
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

namespace OCA\OpenConnector\Adapters\Pdok;

/**
 * Polymorphic base for the PDOK geocoding adapter family.
 *
 * Concrete subclasses live next to this file:
 *  - {@see PdokGeocodingClientMock} — deterministic canned PostalAddress.
 *  - {@see PdokGeocodingClientHttp} — real Locatieserver v3.1 calls.
 *
 * The shape returned by `suggest()`/`lookup()`/`reverse()` is the normalised
 * PostalAddress documented in
 * `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.
 */
abstract class PdokGeocodingClient
{
    /**
     * Free-text autocomplete suggest call.
     *
     * @param string $query Free-text query (street, postcode, place).
     * @param int    $rows  Maximum rows to return (PDOK Locatieserver default: 10).
     *
     * @return array<int,array<string,mixed>> Normalised PostalAddress entries.
     */
    abstract public function suggest(string $query, int $rows=10): array;

    /**
     * Lookup a specific PDOK identifier.
     *
     * Resolves `adres-…` / `weg-…` / `woonplaats-…` ids to a single canonical
     * PostalAddress.
     *
     * @param string $pdokId PDOK Locatieserver id.
     *
     * @return array<string,mixed>|null Normalised PostalAddress, or null if not found.
     */
    abstract public function lookup(string $pdokId): ?array;

    /**
     * Reverse-geocode a lat/lng pair to candidate addresses.
     *
     * @param float $latitude  Latitude (WGS84).
     * @param float $longitude Longitude (WGS84).
     *
     * @return array<int,array<string,mixed>> Normalised candidate PostalAddress entries.
     */
    abstract public function reverse(float $latitude, float $longitude): array;

    /**
     * Flavour identifier (mock | http) — surfaced in the source descriptor
     * and logged on every dormant Source-facade call.
     *
     * @return string
     */
    abstract public function flavour(): string;

}//end class
