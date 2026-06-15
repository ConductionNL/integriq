<?php

/**
 * Abstract PDOK WFS adapter (OGC Web Feature Service).
 *
 * Defines the polymorphic surface — `getCapabilities()`, `describeFeatureType()`,
 * `getFeature()`, `flavour()` — for the PDOK WFS dataset family.
 *
 * DI in {@see \OCA\OpenConnector\AppInfo\Application::register()} resolves this
 * abstract to a concrete flavour, gated on `pdok.feature_flag`.
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
 * Polymorphic base for the PDOK WFS adapter family.
 *
 * Concrete subclasses:
 *  - {@see PdokWfsClientMock} — deterministic canned GML.
 *  - {@see PdokWfsClientHttp} — real OGC WFS calls against `service.pdok.nl`.
 *
 * The GetFeature response is returned as a parsed GeoJSON FeatureCollection
 * (the HTTP flavour requests `outputFormat=application/json` natively;
 * the mock flavour returns a hand-rolled minimal FeatureCollection).
 */
abstract class PdokWfsClient
{

    /**
     * Return the GetCapabilities XML for the requested dataset.
     *
     * @param string $dataset PDOK WFS dataset key (e.g. `bag`, `kadastralekaart`).
     *
     * @return string The raw XML capabilities document.
     */
    abstract public function getCapabilities(string $dataset): string;

    /**
     * Return the DescribeFeatureType XML for a specific feature type.
     *
     * @param string $dataset     PDOK WFS dataset key.
     * @param string $featureType Feature type name (per the dataset capabilities).
     *
     * @return string The raw XML feature-type schema.
     */
    abstract public function describeFeatureType(string $dataset, string $featureType): string;

    /**
     * Execute a GetFeature request and return the result as a GeoJSON FeatureCollection.
     *
     * @param string                                   $dataset       PDOK WFS dataset key.
     * @param string                                   $featureType   Feature type name.
     * @param array{0:float,1:float,2:float,3:float}|null $bbox         Optional bbox filter (minx,miny,maxx,maxy).
     * @param int                                      $count         Maximum features (default 10).
     * @param string                                   $srsName       CRS (default `EPSG:28992`).
     * @param array<string,string>                     $filterFields  Optional name=value filter pairs.
     *
     * @return array<string,mixed> Parsed GeoJSON FeatureCollection.
     */
    abstract public function getFeature(
        string $dataset,
        string $featureType,
        ?array $bbox=null,
        int $count=10,
        string $srsName='EPSG:28992',
        array $filterFields=[]
    ): array;

    /**
     * Flavour identifier (mock | http).
     *
     * @return string
     */
    abstract public function flavour(): string;

}//end class
