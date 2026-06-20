<?php

/**
 * OpenConnector PDOK WMS Source Adapter (dormant).
 *
 * Source-pattern facade over the lower-level `PdokWmsClient` family
 * (`lib/Adapters/Pdok/`). Ships dormant: every call logs the intent and
 * returns a deterministic canned response so consuming apps (procest
 * `gis-integration`, `archief-edepot-handover-04-document-export`,
 * `migrate-pdok-to-openconnector`, mandaat) can develop and test against
 * a stable surface without hitting the live PDOK WMS endpoint.
 *
 * Lives under `lib/Sources/Pdok/` so it can be discovered by the openconnector
 * Source registry (Source row `pdok-wms`, category=`geo`). The active HTTP
 * implementation is `\OCA\OpenConnector\Adapters\Pdok\PdokWmsClientHttp` —
 * see {@see \OCA\OpenConnector\Adapters\Pdok\PdokSourceAdapter} for the
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

use OCA\OpenConnector\Adapters\Pdok\PdokWmsClient;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant source adapter for the PDOK WMS (Web Map Service).
 *
 * Registered as openconnector Source row id `pdok-wms`, category `geo`.
 * Until the `pdok.feature_flag` app-config key is flipped to `1`, every
 * method returns the canned response below and logs a single debug entry
 * so operators can verify the wiring without burning quota on the real
 * upstream.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
final class PdokWmsSourceAdapter
{
    /**
     * App id used for IAppConfig look-ups.
     */
    public const APP_ID = 'openconnector';

    /**
     * App-config key for the dormant-flag toggle (shared with the other
     * PDOK adapters — flipping this turns the whole geo bundle live).
     */
    public const FLAG_KEY = 'pdok.feature_flag';

    /**
     * Canonical Source row id this adapter is registered under.
     */
    public const SOURCE_ID = 'pdok-wms';

    /**
     * Source category — `geo` per the connector-categories taxonomy
     * (Data Infrastructure Connectors / geo subcategory).
     */
    public const SOURCE_CATEGORY = 'geo';

    /**
     * Constructor.
     *
     * @param IAppConfig      $config    App-config service (feature-flag check).
     * @param LoggerInterface $logger    Structured logger.
     * @param PdokWmsClient   $wmsClient Resolved WMS client (mock or http).
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly LoggerInterface $logger,
        private readonly PdokWmsClient $wmsClient
    ) {
    }//end __construct()

    /**
     * Whether the live upstream WMS is enabled by the operator.
     *
     * @return bool True when `pdok.feature_flag` is `1`/`true`.
     */
    public function isActive(): bool
    {
        $raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
        return ($raw === '1' || strtolower($raw) === 'true');
    }//end isActive()

    /**
     * Fetch a WMS map tile for a layer + bounding box.
     *
     * In dormant mode returns the canned 1×1 transparent PNG below and logs
     * the requested parameters at debug level. In active mode delegates to
     * the resolved `PdokWmsClient` (HTTP variant).
     *
     * @param string               $layer  WMS layer name (e.g. `top10nl`, `2020_ortho25`).
     * @param array<int,float>     $bbox   `[minx, miny, maxx, maxy]` in EPSG:28992 metres.
     * @param int                  $width  Output width in pixels.
     * @param int                  $height Output height in pixels.
     * @param array<string,string> $extras Optional WMS overrides (`format`, `styles`, …).
     *
     * @return string Image bytes (PNG/JPEG).
     */
    public function getMap(
        string $layer,
        array $bbox,
        int $width=256,
        int $height=256,
        array $extras=[]
    ): string {
        $this->logger->debug(
            'pdok-wms.getMap',
            [
                'source'   => self::SOURCE_ID,
                'category' => self::SOURCE_CATEGORY,
                'layer'    => $layer,
                'bbox'     => $bbox,
                'width'    => $width,
                'height'   => $height,
                'extras'   => $extras,
                'active'   => $this->isActive(),
                'flavour'  => $this->wmsClient->flavour(),
            ]
        );

        return $this->wmsClient->getMap($layer, $bbox, $width, $height, $extras);
    }//end getMap()

    /**
     * Return the parsed WMS GetCapabilities document.
     *
     * @return array<string,mixed> Capabilities tree.
     */
    public function getCapabilities(): array
    {
        $this->logger->debug(
            'pdok-wms.getCapabilities',
            [
                'source'   => self::SOURCE_ID,
                'category' => self::SOURCE_CATEGORY,
                'active'   => $this->isActive(),
                'flavour'  => $this->wmsClient->flavour(),
            ]
        );

        return $this->wmsClient->getCapabilities();
    }//end getCapabilities()

    /**
     * Source-registry descriptor for the openconnector Source row.
     *
     * Mirrors the OR `source` schema fields the Source registry inserts when
     * the InitializeRegister repair step runs. Kept here next to the adapter
     * so registration metadata + implementation never drift.
     *
     * @return array<string,mixed>
     */
    public static function sourceDescriptor(): array
    {
        return [
            'id'            => self::SOURCE_ID,
            'name'          => 'PDOK WMS',
            'description'   => 'Publieke Dienstverlening Op de Kaart — Web Map Service (raster tile endpoints).',
            'category'      => self::SOURCE_CATEGORY,
            'subCategory'   => 'gis-raster',
            'adapterClass'  => self::class,
            'location'      => 'https://service.pdok.nl/{collection}/{dataset}/wms/v1_0',
            'type'          => 'wms',
            'auth'          => 'none',
            'isEnabled'     => false,
            'documentation' => 'https://www.pdok.nl/over-pdok/services/web-map-service-wms',
            'reference'     => 'pdok.feature_flag',
        ];
    }//end sourceDescriptor()
}//end class
