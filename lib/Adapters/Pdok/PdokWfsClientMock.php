<?php

/**
 * OpenConnector PDOK WFS Client (mock).
 *
 * Deterministic, no-network implementation of {@see PdokWfsClient}. Ships
 * dormant; DI returns this class until `pdok.feature_flag` is set to `1`.
 *
 * The canned GeoJSON FeatureCollection encodes one BAG verblijfsobject for
 * Conduction HQ (Lauriergracht 37, 1016 RG Amsterdam) so downstream code can
 * exercise its branches off real-looking data.
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
 * Mock PDOK WFS client — dormant default.
 */
final class PdokWfsClientMock extends PdokWfsClient
{

    /**
     * Render a canned GetCapabilities XML document.
     *
     * @param string $dataset PDOK WFS dataset key.
     *
     * @return string The raw XML capabilities document.
     */
    public function getCapabilities(string $dataset): string
    {
        $safe = htmlspecialchars($dataset, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<WFS_Capabilities version="2.0.0" xmlns="http://www.opengis.net/wfs/2.0">'
            .'<ServiceIdentification><Title>PDOK mock '.$safe.'</Title></ServiceIdentification>'
            .'<FeatureTypeList><FeatureType><Name>'.$safe.':feature</Name>'
            .'<DefaultCRS>urn:ogc:def:crs:EPSG::28992</DefaultCRS></FeatureType></FeatureTypeList>'
            .'</WFS_Capabilities>';

    }//end getCapabilities()

    /**
     * Render a canned DescribeFeatureType XML document.
     *
     * @param string $dataset     PDOK WFS dataset key.
     * @param string $featureType Feature type name.
     *
     * @return string The raw XML feature-type schema.
     */
    public function describeFeatureType(string $dataset, string $featureType): string
    {
        $safeDataset = htmlspecialchars($dataset, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $safeType    = htmlspecialchars($featureType, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema" targetNamespace="http://'.$safeDataset.'.pdok.nl/mock">'
            .'<xsd:element name="'.$safeType.'" type="'.$safeType.'Type"/>'
            .'<xsd:complexType name="'.$safeType.'Type">'
            .'<xsd:sequence>'
            .'<xsd:element name="identificatie" type="xsd:string"/>'
            .'<xsd:element name="geometrie" type="gml:GeometryPropertyType"/>'
            .'</xsd:sequence></xsd:complexType></xsd:schema>';

    }//end describeFeatureType()

    /**
     * Return the canned BAG-verblijfsobject FeatureCollection.
     *
     * @param string     $dataset      PDOK WFS dataset key.
     * @param string     $featureType  Feature type name.
     * @param array|null $bbox         Bounding box (unused).
     * @param int        $count        Maximum features (unused).
     * @param string     $srsName      CRS (unused).
     * @param array      $filterFields Filter pairs (unused).
     *
     * @return array<string,mixed> Canned GeoJSON FeatureCollection.
     */
    public function getFeature(
        string $dataset,
        string $featureType,
        ?array $bbox=null,
        int $count=10,
        string $srsName='EPSG:28992',
        array $filterFields=[]
    ): array {
        unset($dataset, $featureType, $bbox, $count, $srsName, $filterFields);

        return [
            'type'     => 'FeatureCollection',
            'features' => [
                [
                    'type'       => 'Feature',
                    'id'         => 'bag-mock-lauriergracht-37',
                    'geometry'   => [
                        'type'        => 'Point',
                        'coordinates' => [4.88525, 52.37025],
                    ],
                    'properties' => [
                        'identificatie'   => '0363010000406543',
                        'huisnummer'      => 37,
                        'postcode'        => '1016 RG',
                        'openbareruimte'  => 'Lauriergracht',
                        'woonplaats'      => 'Amsterdam',
                        'gebruiksdoel'    => 'kantoorfunctie',
                        'oppervlakte_m2'  => 142,
                        'status'          => 'Verblijfsobject in gebruik',
                    ],
                ],
            ],
            'totalFeatures' => 1,
            'numberMatched' => 1,
            'numberReturned' => 1,
            'crs'           => [
                'type'       => 'name',
                'properties' => ['name' => 'urn:ogc:def:crs:EPSG::4326'],
            ],
        ];

    }//end getFeature()

    /**
     * Flavour identifier.
     *
     * @return string
     */
    public function flavour(): string
    {
        return 'mock';

    }//end flavour()

}//end class
