<?php

/**
 * OpenConnector DSO Parser Service
 *
 * Parses DSO-verzoek XML/JSON payloads into structured data.
 * Validates payloads against the STAM schema definition.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for parsing and validating DSO-verzoek payloads.
 *
 * Extracts aanvrager, locatie, activiteiten, bijlagen, and projectbeschrijving
 * from DSO-LV STAM koppelvlak payloads (JSON or XML).
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
 */
class DSOParserService
{

    /**
     * Required fields in a DSO-verzoek payload.
     *
     * @var array
     */
    private const REQUIRED_FIELDS = [
        'verzoekId',
        'type',
        'indieningsdatum',
        'aanvrager',
        'locatie',
        'activiteiten',
    ];

    /**
     * Valid verzoek types per STAM schema.
     *
     * @var array
     */
    private const VALID_TYPES = [
        'aanvraag',
        'melding',
        'informatieverzoek',
        'vooroverleg',
    ];


    /**
     * DSOParserService constructor.
     *
     * @param LoggerInterface $logger Logger for error handling.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Validate a DSO-verzoek payload against the STAM schema.
     *
     * Returns an array of validation errors. An empty array means the payload is valid.
     *
     * @param array $payload The verzoek payload data.
     *
     * @return array Array of validation error objects with 'field', 'error', and 'message' keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
     */
    public function validatePayload(array $payload): array
    {
        $errors = [];

        // Check required fields.
        foreach (self::REQUIRED_FIELDS as $field) {
            if (isset($payload[$field]) === false
                || $payload[$field] === ''
                || $payload[$field] === null
            ) {
                $errors[] = [
                    'field'   => $field,
                    'error'   => 'required_field_missing',
                    'message' => ucfirst($field).' is verplicht',
                ];
            }
        }

        // Validate type enum.
        if (isset($payload['type']) === true
            && in_array($payload['type'], self::VALID_TYPES, true) === false
        ) {
            $errors[] = [
                'field'   => 'type',
                'error'   => 'invalid_enum_value',
                'message' => 'Type must be one of: '.implode(', ', self::VALID_TYPES),
            ];
        }

        // Validate activiteiten is an array.
        if (isset($payload['activiteiten']) === true
            && is_array($payload['activiteiten']) === false
        ) {
            $errors[] = [
                'field'   => 'activiteiten',
                'error'   => 'invalid_type',
                'message' => 'Activiteiten must be an array',
            ];
        }

        // Validate BSN if aanvrager contains one.
        if (isset($payload['aanvrager']['bsn']) === true) {
            if ($this->validateBSN(bsn: $payload['aanvrager']['bsn']) === false) {
                $errors[] = [
                    'field'   => 'aanvrager.bsn',
                    'error'   => 'invalid_bsn',
                    'message' => 'BSN does not pass the 11-proef validation',
                ];
            }
        }

        // Validate indieningsdatum format (ISO 8601).
        if (isset($payload['indieningsdatum']) === true
            && $this->validateISODate(date: $payload['indieningsdatum']) === false
        ) {
            $errors[] = [
                'field'   => 'indieningsdatum',
                'error'   => 'invalid_date_format',
                'message' => 'Indieningsdatum must be in ISO 8601 format (YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS)',
            ];
        }

        return $errors;

    }//end validatePayload()


    /**
     * Parse a DSO-verzoek payload into structured data.
     *
     * Extracts and normalizes all verzoek fields including aanvrager,
     * locatie, activiteiten, and bijlagen references.
     *
     * @param array $payload The raw verzoek payload.
     *
     * @return array The parsed and structured verzoek data.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
     */
    public function parseVerzoek(array $payload): array
    {
        $bouwkosten = null;
        if (isset($payload['bouwkosten']) === true) {
            $bouwkosten = (float) $payload['bouwkosten'];
        }

        $verzoek = [
            'verzoekId'       => ($payload['verzoekId'] ?? null),
            'bronorganisatie' => ($payload['bronorganisatie'] ?? null),
            'type'            => ($payload['type'] ?? null),
            'indieningsdatum' => ($payload['indieningsdatum'] ?? null),
            'aanvrager'       => $this->parseAanvrager(aanvrager: ($payload['aanvrager'] ?? [])),
            'locatie'         => $this->parseLocatie(locatie: ($payload['locatie'] ?? [])),
            'activiteiten'    => $this->parseActiviteiten(activiteiten: ($payload['activiteiten'] ?? [])),
            'bouwkosten'      => $bouwkosten,
            'bijlagen'        => ($payload['bijlagen'] ?? []),
            'status'          => 'ontvangen',
            'environment'     => ($payload['environment'] ?? 'productie'),
            'stamApiVersion'  => ($payload['stamApiVersion'] ?? null),
        ];

        if (isset($payload['projectbeschrijving']) === true) {
            $verzoek['projectbeschrijving'] = $payload['projectbeschrijving'];
        }

        return $verzoek;

    }//end parseVerzoek()


    /**
     * Validate a BSN (Burger Service Nummer) using the 11-proef.
     *
     * The 11-proef validation multiplies each digit by a weight factor
     * and checks that the sum is divisible by 11.
     *
     * NOTE: This is intentionally duplicated from OpenRegister's BsnFormat.
     * OpenConnector cannot depend on OpenRegister being installed since it
     * connects to external systems independently. If the canonical implementation
     * changes, this method must be updated to match.
     *
     * @param string $bsn The BSN to validate.
     *
     * @return bool True if the BSN passes the 11-proef.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
     *
     * @see \OCA\OpenRegister\Formats\BsnFormat::validate() Canonical BSN validation (ADR-011)
     */
    public function validateBSN(string $bsn): bool
    {
        // BSN must be 8 or 9 digits.
        $bsn = ltrim(string: $bsn, characters: '0');
        $bsn = str_pad(string: $bsn, length: 9, pad_string: '0', pad_type: STR_PAD_LEFT);

        if (preg_match(pattern: '/^\d{9}$/', subject: $bsn) !== 1) {
            return false;
        }

        // 11-proef: multiply each digit by its weight factor.
        $sum     = 0;
        $weights = [
            9,
            8,
            7,
            6,
            5,
            4,
            3,
            2,
            -1,
        ];

        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $bsn[$i] * $weights[$i]);
        }

        return ($sum % 11 === 0 && $sum !== 0);

    }//end validateBSN()


    /**
     * Validate an ISO 8601 date string.
     *
     * @param string $date The date string to validate.
     *
     * @return bool True if the date is valid ISO 8601.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
     */
    public function validateISODate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
            return true;
        }

        $parsed = \DateTime::createFromFormat('Y-m-d\TH:i:s', $date);
        if ($parsed !== false) {
            return true;
        }

        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $date);
        if ($parsed !== false) {
            return true;
        }

        return false;

    }//end validateISODate()


    /**
     * Parse the aanvrager (initiatiefnemer) block.
     *
     * @param array $aanvrager The raw aanvrager data.
     *
     * @return array The parsed aanvrager data.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
     */
    private function parseAanvrager(array $aanvrager): array
    {
        return [
            'bsn'              => ($aanvrager['bsn'] ?? null),
            'kvkNummer'        => ($aanvrager['kvkNummer'] ?? null),
            'vestigingsnummer' => ($aanvrager['vestigingsnummer'] ?? null),
            'naam'             => ($aanvrager['naam'] ?? null),
            'bedrijfsnaam'     => ($aanvrager['bedrijfsnaam'] ?? null),
            'adres'            => ($aanvrager['adres'] ?? null),
            'contactgegevens'  => ($aanvrager['contactgegevens'] ?? null),
        ];

    }//end parseAanvrager()


    /**
     * Parse the locatie block.
     *
     * Handles BAG-adresgegevens and GML-geometrie conversion.
     *
     * @param array $locatie The raw locatie data.
     *
     * @return array The parsed locatie data.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
     */
    private function parseLocatie(array $locatie): array
    {
        $parsed = [
            'bagAdres'             => ($locatie['bagAdres'] ?? null),
            'kadastraleAanduiding' => ($locatie['kadastraleAanduiding'] ?? null),
            'geometrie'            => null,
        ];

        // Convert GML to GeoJSON if present.
        if (isset($locatie['gmlGeometrie']) === true) {
            $parsed['geometrie'] = $this->convertGMLToGeoJSON(gml: $locatie['gmlGeometrie']);
        }

        return $parsed;

    }//end parseLocatie()


    /**
     * Parse the activiteiten array.
     *
     * @param array $activiteiten The raw activiteiten data.
     *
     * @return array The parsed activiteiten data.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
     */
    private function parseActiviteiten(array $activiteiten): array
    {
        $parsed = [];
        foreach ($activiteiten as $activiteit) {
            $code         = ($activiteit['code'] ?? ($activiteit['activiteitCode'] ?? null));
            $omschrijving = ($activiteit['omschrijving'] ?? null);

            $parsed[] = [
                'code'         => $code,
                'omschrijving' => $omschrijving,
            ];
        }

        return $parsed;

    }//end parseActiviteiten()


    /**
     * Convert a GML geometry string to GeoJSON.
     *
     * Handles common GML point format (gml:pos). For full GML polygon
     * and complex geometry support a dedicated geometry library is needed.
     *
     * @param string $gml The GML geometry string.
     *
     * @return array|null The GeoJSON geometry object, or null if conversion fails.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
     */
    private function convertGMLToGeoJSON(string $gml): ?array
    {
        $matchCount = preg_match(
            pattern: '/<gml:pos>([\d.]+)\s+([\d.]+)<\/gml:pos>/',
            subject: $gml,
            matches: $matches
        );

        if ($matchCount === 1) {
            return [
                'type'        => 'Point',
                'coordinates' => [
                    (float) $matches[2],
                    (float) $matches[1],
                ],
            ];
        }

        $this->logger->info('DSO: GML conversion not fully implemented for complex geometries');
        return null;

    }//end convertGMLToGeoJSON()


}//end class
