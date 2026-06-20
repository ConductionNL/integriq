<?php

/**
 * Abstract PDOK WMS adapter (OGC Web Map Service).
 *
 * Defines the polymorphic surface — `getCapabilities()`, `getMap()`,
 * `flavour()` — for the PDOK WMS dataset family. The mock flavour returns a
 * pre-recorded GetCapabilities document + a deterministic 1x1 PNG; the HTTP
 * flavour issues real outbound requests to `service.pdok.nl/...`.
 *
 * DI in {@see \OCA\OpenConnector\AppInfo\Application::register()} resolves this
 * abstract to the appropriate concrete flavour, gated on `pdok.feature_flag`.
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
 * Polymorphic base for the PDOK WMS adapter family.
 *
 * Concrete subclasses live next to this file:
 *  - {@see PdokWmsClientMock} — deterministic canned XML + 1x1 PNG.
 *  - {@see PdokWmsClientHttp} — real OGC WMS calls against `service.pdok.nl`.
 *
 * `getCapabilities()` returns the raw GetCapabilities XML document for the
 * requested dataset; `getMap()` returns the binary PNG/JPEG produced by the
 * GetMap request. The dataset key matches the PDOK service slug (e.g.
 * `bgt`, `bag`, `top10nl`).
 */
abstract class PdokWmsClient
{
    /**
     * Return the GetCapabilities XML for the requested dataset.
     *
     * @param string $dataset PDOK WMS dataset key (e.g. `bgt`, `bag`).
     *
     * @return string The raw XML capabilities document.
     */
    abstract public function getCapabilities(string $dataset): string;

    /**
     * Render a GetMap PNG for the requested dataset, bbox and crs.
     *
     * @param string                                 $dataset PDOK WMS dataset key.
     * @param string                                 $layer   Layer name (per the dataset capabilities).
     * @param array{0:float,1:float,2:float,3:float} $bbox    Bounding box (minx,miny,maxx,maxy).
     * @param string                                 $crs     CRS identifier (e.g. `EPSG:28992`).
     * @param int                                    $width   Pixel width.
     * @param int                                    $height  Pixel height.
     * @param string                                 $format  Image MIME type (default `image/png`).
     *
     * @return string Raw image bytes.
     */
    abstract public function getMap(
        string $dataset,
        string $layer,
        array $bbox,
        string $crs='EPSG:28992',
        int $width=512,
        int $height=512,
        string $format='image/png'
    ): string;

    /**
     * Flavour identifier (mock | http).
     *
     * @return string
     */
    abstract public function flavour(): string;
}//end class
