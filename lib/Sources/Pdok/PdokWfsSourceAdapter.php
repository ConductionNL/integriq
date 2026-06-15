<?php

/**
 * OpenConnector PDOK WFS Source Adapter (dormant).
 *
 * Source-pattern facade over the lower-level `PdokWfsClient` family
 * (`lib/Adapters/Pdok/`). Ships dormant: every call logs the intent and
 * returns a deterministic canned response so consuming apps (procest
 * `gis-integration`, `archief-edepot-handover-04-document-export`,
 * mandaat) can develop and test against a stable surface without
 * hitting the live PDOK WFS endpoint.
 *
 * Lives under `lib/Sources/Pdok/` so it can be discovered by the openconnector
 * Source registry (Source row `pdok-wfs`, category=`geo`). The active HTTP
 * implementation is `\OCA\OpenConnector\Adapters\Pdok\PdokWfsClientHttp` —
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

use OCA\OpenConnector\Adapters\Pdok\PdokWfsClient;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant source adapter for the PDOK WFS (Web Feature Service).
 *
 * Registered as openconnector Source row id `pdok-wfs`, category `geo`.
 * Until the `pdok.feature_flag` app-config key is flipped to `1`, every
 * method returns the canned response below and logs a single debug entry
 * so operators can verify the wiring without burning quota on the real
 * upstream.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
final class PdokWfsSourceAdapter
{
    /**
     * App id used for IAppConfig look-ups.
     */
    public const APP_ID = 'openconnector';

    /**
     * App-config key for the dormant-flag toggle (shared with WMS + geocoding).
     */
    public const FLAG_KEY = 'pdok.feature_flag';

    /**
     * Canonical Source row id this adapter is registered under.
     */
    public const SOURCE_ID = 'pdok-wfs';

    /**
     * Source category — `geo` per the connector-categories taxonomy.
     */
    public const SOURCE_CATEGORY = 'geo';

    /**
     * Constructor.
     *
     * @param IAppConfig      $config    App-config service (feature-flag check).
     * @param LoggerInterface $logger    Structured logger.
     * @param PdokWfsClient   $wfsClient Resolved WFS client (mock or http).
     */
    public function __construct(
        private readonly IAppConfig $config,
        private readonly LoggerInterface $logger,
        private readonly PdokWfsClient $wfsClient
    ) {
    }//end __construct()

    /**
     * Whether the live upstream WFS is enabled by the operator.
     *
     * @return bool True when `pdok.feature_flag` is `1`/`true`.
     */
    public function isActive(): bool
    {
        $raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
        return ($raw === '1' || strtolower($raw) === 'true');
    }//end isActive()

    /**
     * Fetch vector features for a WFS type name.
     *
     * In dormant mode returns an empty GeoJSON FeatureCollection (via the
     * resolved mock client) and logs the requested parameters at debug level.
     * In active mode delegates to the resolved `PdokWfsClient` (HTTP variant).
     *
     * @param string                $typeName WFS type name (e.g. `bag:pand`, `kadastralekaart:perceel`).
     * @param array<int,float>|null $bbox     Optional `[minx, miny, maxx, maxy]` in EPSG:28992.
     * @param int                   $count    Maximum number of features.
     * @param array<string,string>  $extras   Optional WFS overrides (`filter`, `srsName`, …).
     *
     * @return array<string,mixed> GeoJSON FeatureCollection.
     */
    public function getFeatures(
        string $typeName,
        ?array $bbox = null,
        int $count = 100,
        array $extras = []
    ): array {
        $this->logger->debug(
            'pdok-wfs.getFeatures',
            [
                'source'   => self::SOURCE_ID,
                'category' => self::SOURCE_CATEGORY,
                'typeName' => $typeName,
                'bbox'     => $bbox,
                'count'    => $count,
                'extras'   => $extras,
                'active'   => $this->isActive(),
                'flavour'  => $this->wfsClient->flavour(),
            ]
        );

        return $this->wfsClient->getFeatures($typeName, $bbox, $count, $extras);
    }//end getFeatures()

    /**
     * Return the parsed WFS GetCapabilities document.
     *
     * @return array<string,mixed>
     */
    public function describeService(): array
    {
        $this->logger->debug(
            'pdok-wfs.describeService',
            [
                'source'   => self::SOURCE_ID,
                'category' => self::SOURCE_CATEGORY,
                'active'   => $this->isActive(),
                'flavour'  => $this->wfsClient->flavour(),
            ]
        );

        return $this->wfsClient->describeService();
    }//end describeService()

    /**
     * Source-registry descriptor for the openconnector Source row.
     *
     * @return array<string,mixed>
     */
    public static function sourceDescriptor(): array
    {
        return [
            'id'            => self::SOURCE_ID,
            'name'          => 'PDOK WFS',
            'description'   => 'Publieke Dienstverlening Op de Kaart — Web Feature Service (vector feature endpoints).',
            'category'      => self::SOURCE_CATEGORY,
            'subCategory'   => 'gis-vector',
            'adapterClass'  => self::class,
            'location'      => 'https://service.pdok.nl/{collection}/{dataset}/wfs/v1_0',
            'type'          => 'wfs',
            'auth'          => 'none',
            'isEnabled'     => false,
            'documentation' => 'https://www.pdok.nl/over-pdok/services/web-feature-service-wfs',
            'reference'     => 'pdok.feature_flag',
        ];
    }//end sourceDescriptor()

}//end class
