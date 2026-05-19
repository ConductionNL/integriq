<?php
/**
 * OpenConnector StUF Field Mapper
 *
 * Maps StUF-BG/StUF-ZKN fields to/from OpenRegister object properties.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for mapping StUF fields to/from OpenRegister object properties.
 *
 * Supports configurable field mappings stored as OpenRegister objects,
 * date format transformation, and nested object mapping for addresses.
 */
class StUFFieldMapper
{

    /**
     * Default BRP-to-StUF-BG field mapping.
     *
     * Maps OpenRegister property names to StUF-BG XML element names.
     *
     * @var array
     */
    private const DEFAULT_BRP_MAPPING = [
        'burgerservicenummer' => 'inp.bsn',
        'geslachtsnaam'       => 'geslachtsnaam',
        'voorvoegsel'         => 'voorvoegselGeslachtsnaam',
        'voornamen'           => 'voornamen',
        'geboortedatum'       => 'geboortedatum',
        'geslachtsaanduiding' => 'geslachtsaanduiding',
    ];

    /**
     * Default address field mapping.
     *
     * @var array
     */
    private const DEFAULT_ADDRESS_MAPPING = [
        'straatnaam'  => 'gor.straatnaam',
        'huisnummer'  => 'aoa.huisnummer',
        'postcode'    => 'aoa.postcode',
        'woonplaats'  => 'wpl.woonplaatsNaam',
    ];


    /**
     * StUFFieldMapper constructor.
     *
     * @param LoggerInterface $logger Logger for error handling
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Map an OpenRegister person object to StUF-BG field values.
     *
     * @param array      $person  The OpenRegister person object properties.
     * @param array|null $mapping Custom field mapping (null uses defaults).
     *
     * @return array Array of StUF field name to value pairs.
     */
    public function mapPersonToStUF(array $person, ?array $mapping = null): array
    {
        $fieldMapping = $mapping ?? self::DEFAULT_BRP_MAPPING;
        $result       = [];

        foreach ($fieldMapping as $registerField => $stufField) {
            if (isset($person[$registerField]) === true) {
                $value = $person[$registerField];

                // Transform dates from ISO 8601 to StUF YYYYMMDD format.
                if ($stufField === 'geboortedatum' && is_string($value) === true) {
                    $value = $this->isoDateToStUF($value);
                }

                $result[$stufField] = $value;
            }
        }

        // Map nested verblijfsadres.
        if (isset($person['verblijfsadres']) === true && is_array($person['verblijfsadres']) === true) {
            $result['verblijfsadres'] = $this->mapAddressToStUF($person['verblijfsadres']);
        }

        return $result;

    }//end mapPersonToStUF()


    /**
     * Map a StUF-BG person response to OpenRegister object properties.
     *
     * @param array      $stufData The StUF-BG response data.
     * @param array|null $mapping  Custom field mapping (null uses defaults).
     *
     * @return array Array of OpenRegister property name to value pairs.
     */
    public function mapStUFToPerson(array $stufData, ?array $mapping = null): array
    {
        $fieldMapping = $mapping ?? self::DEFAULT_BRP_MAPPING;
        $reversed     = array_flip($fieldMapping);
        $result       = [];

        foreach ($reversed as $stufField => $registerField) {
            if (isset($stufData[$stufField]) === true) {
                $value = $stufData[$stufField];

                // Transform dates from StUF YYYYMMDD to ISO 8601 format.
                if ($registerField === 'geboortedatum' && is_string($value) === true) {
                    $value = $this->stufDateToISO($value);
                }

                $result[$registerField] = $value;
            }
        }

        return $result;

    }//end mapStUFToPerson()


    /**
     * Map an OpenRegister address object to StUF-BG address fields.
     *
     * @param array      $address The address properties.
     * @param array|null $mapping Custom address field mapping.
     *
     * @return array Array of StUF address field name to value pairs.
     */
    public function mapAddressToStUF(array $address, ?array $mapping = null): array
    {
        $fieldMapping = $mapping ?? self::DEFAULT_ADDRESS_MAPPING;
        $result       = [];

        foreach ($fieldMapping as $registerField => $stufField) {
            if (isset($address[$registerField]) === true) {
                $result[$stufField] = $address[$registerField];
            }
        }

        return $result;

    }//end mapAddressToStUF()


    /**
     * Convert an ISO 8601 date to StUF YYYYMMDD format.
     *
     * @param string $isoDate The ISO 8601 date string (e.g., "1990-05-15").
     *
     * @return string The StUF date string (e.g., "19900515").
     */
    public function isoDateToStUF(string $isoDate): string
    {
        $date = \DateTime::createFromFormat('Y-m-d', substr($isoDate, 0, 10));
        if ($date === false) {
            return $isoDate;
        }

        return $date->format('Ymd');

    }//end isoDateToStUF()


    /**
     * Convert a StUF YYYYMMDD date to ISO 8601 format.
     *
     * @param string $stufDate The StUF date string (e.g., "19900515").
     *
     * @return string The ISO 8601 date string (e.g., "1990-05-15").
     */
    public function stufDateToISO(string $stufDate): string
    {
        $date = \DateTime::createFromFormat('Ymd', $stufDate);
        if ($date === false) {
            return $stufDate;
        }

        return $date->format('Y-m-d');

    }//end stufDateToISO()


}//end class
