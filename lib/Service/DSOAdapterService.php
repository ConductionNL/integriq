<?php

/**
 * DSO Adapter Service
 *
 * Orchestrates processing of DSO-verzoeken, routes by type, downloads bijlagen,
 * maps activiteiten to zaaktypen, and handles samenloop strategies.
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Main adapter service for DSO-LV (Digitaal Stelsel Omgevingswet) integration.
 *
 * Routes incoming verzoeken to the correct handler, downloads bijlagen,
 * maps activiteiten to zaaktypen, and resolves samenloop strategies.
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class DSOAdapterService
{

    /**
     * Maximum number of download retry attempts per bijlage.
     *
     * @var int
     */
    private const MAX_DOWNLOAD_RETRIES = 3;

    /**
     * Base directory for storing downloaded DSO bijlagen.
     *
     * @var string
     */
    private const BIJLAGEN_BASE_PATH = '/DSO-verzoeken';

    /**
     * DSOAdapterService constructor.
     *
     * @param LoggerInterface $logger        PSR logger.
     * @param IClientService  $clientService Nextcloud HTTP client factory.
     * @param IAppConfig      $appConfig     Nextcloud app configuration.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly IClientService $clientService,
        private readonly IAppConfig $appConfig
    ) {

    }//end __construct()

    /**
     * Read the configured DSO-LV API base URL from app config.
     *
     * Centralises app-config access so callers (pushStatusToDSO,
     * testDSOConnection) can resolve the production/pre-productie endpoint
     * without each owning a separate IAppConfig lookup.
     *
     * @return string The configured DSO-LV API URL, or an empty string if unset.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
     */
    public function getConfiguredApiUrl(): string
    {
        return $this->appConfig->getValueString('openconnector', 'dso_api_url', '');

    }//end getConfiguredApiUrl()

    /**
     * Process an incoming DSO verzoek and route it to the correct handler.
     *
     * Routes based on verzoek type: 'melding', 'informatieverzoek', 'vooroverleg',
     * or defaults to handleAanvraag for all other types.
     *
     * @param array $verzoek The parsed DSO verzoek data.
     *
     * @return array Result containing 'zaakId', 'status', and 'verzoekId' keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
     */
    public function processVerzoek(array $verzoek): array
    {
        $type = ($verzoek['type'] ?? 'aanvraag');

        $this->logger->info(
            'DSO: processing verzoek',
            [
                'verzoekId' => ($verzoek['verzoekId'] ?? null),
                'type'      => $type,
            ]
        );

        if ($type === 'melding') {
            return $this->handleMelding(verzoek: $verzoek);
        }

        if ($type === 'informatieverzoek') {
            return $this->handleInformatieverzoek(verzoek: $verzoek);
        }

        if ($type === 'vooroverleg') {
            return $this->handleVooroverleg(verzoek: $verzoek);
        }

        return $this->handleAanvraag(verzoek: $verzoek);

    }//end processVerzoek()

    /**
     * Handle a melding-type verzoek.
     *
     * Creates a zaak with type 'melding' and returns the result.
     *
     * @param array $verzoek The parsed DSO verzoek data.
     *
     * @return array Result array with zaak details.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
     */
    public function handleMelding(array $verzoek): array
    {
        $this->logger->info(
            'DSO: handling melding',
            ['verzoekId' => ($verzoek['verzoekId'] ?? null)]
        );

        return $this->createZaak(
            verzoek: $verzoek,
            zaaktypeIdentificatie: 'melding',
            strategy: 'single'
        );

    }//end handleMelding()

    /**
     * Handle an informatieverzoek-type verzoek.
     *
     * Creates a lightweight zaak with type 'informatieverzoek'.
     *
     * @param array $verzoek The parsed DSO verzoek data.
     *
     * @return array Result array with zaak details.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
     */
    public function handleInformatieverzoek(array $verzoek): array
    {
        $this->logger->info(
            'DSO: handling informatieverzoek',
            ['verzoekId' => ($verzoek['verzoekId'] ?? null)]
        );

        return $this->createZaak(
            verzoek: $verzoek,
            zaaktypeIdentificatie: 'informatieverzoek',
            strategy: 'single'
        );

    }//end handleInformatieverzoek()

    /**
     * Handle a vooroverleg-type verzoek.
     *
     * Creates a zaak with type 'vooroverleg'.
     *
     * @param array $verzoek The parsed DSO verzoek data.
     *
     * @return array Result array with zaak details.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
     */
    public function handleVooroverleg(array $verzoek): array
    {
        $this->logger->info(
            'DSO: handling vooroverleg',
            ['verzoekId' => ($verzoek['verzoekId'] ?? null)]
        );

        return $this->createZaak(
            verzoek: $verzoek,
            zaaktypeIdentificatie: 'vooroverleg',
            strategy: 'single'
        );

    }//end handleVooroverleg()

    /**
     * Handle an aanvraag-type verzoek (default handler).
     *
     * Creates a zaak with type 'aanvraag'.
     *
     * @param array $verzoek The parsed DSO verzoek data.
     *
     * @return array Result array with zaak details.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-4
     */
    public function handleAanvraag(array $verzoek): array
    {
        $this->logger->info(
            'DSO: handling aanvraag',
            ['verzoekId' => ($verzoek['verzoekId'] ?? null)]
        );

        return $this->createZaak(
            verzoek: $verzoek,
            zaaktypeIdentificatie: 'aanvraag',
            strategy: 'single'
        );

    }//end handleAanvraag()

    /**
     * Download bijlagen from DSO-LV to local storage.
     *
     * Loops through bijlagen, downloads each via the HTTP client with retry logic
     * (up to MAX_DOWNLOAD_RETRIES attempts). Stores files under
     * /DSO-verzoeken/{year}/{verzoekId}/bijlagen/.
     *
     * @param array       $bijlagen  Array of bijlage objects (each with a 'url' key).
     * @param string      $verzoekId The verzoek identifier used for folder organisation.
     * @param string|null $certPath  Optional path to client certificate for mTLS.
     *
     * @return array Array of result objects with 'url', 'localPath', and 'status' keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-5
     */
    public function downloadBijlagen(array $bijlagen, string $verzoekId, ?string $certPath=null): array
    {
        $year    = date('Y');
        $baseDir = self::BIJLAGEN_BASE_PATH.'/'.$year.'/'.$verzoekId.'/bijlagen';
        $results = [];

        foreach ($bijlagen as $bijlage) {
            $url    = ($bijlage['url'] ?? '');
            $result = [
                'url'       => $url,
                'localPath' => null,
                'status'    => 'failed',
            ];

            if ($url === '') {
                $results[] = $result;
                continue;
            }

            // Only allow HTTPS to prevent SSRF via file://, http://localhost, etc.
            $scheme = parse_url(url: $url, component: PHP_URL_SCHEME);
            if ($scheme !== 'https') {
                $this->logger->warning(
                    'DSO: bijlage URL rejected — scheme not allowed',
                    [
                        'url'    => $url,
                        'scheme' => $scheme,
                    ]
                );
                $result['status'] = 'rejected_scheme';
                $results[]        = $result;
                continue;
            }

            $attempt    = 0;
            $downloaded = false;

            while ($attempt < self::MAX_DOWNLOAD_RETRIES && $downloaded === false) {
                $attempt++;

                try {
                    $clientOptions = [];
                    if ($certPath !== null) {
                        $clientOptions['cert'] = $certPath;
                    }

                    $client   = $this->clientService->newClient();
                    $response = $client->get(
                        uri: $url,
                        options: $clientOptions
                    );

                    $fileName  = basename(parse_url(url: $url, component: PHP_URL_PATH));
                    $localPath = $baseDir.'/'.$fileName;

                    // Ensure directory exists.
                    if (is_dir(filename: $baseDir) === false) {
                        mkdir(directory: $baseDir, permissions: 0755, recursive: true);
                    }

                    file_put_contents(filename: $localPath, data: $response->getBody());

                    $result['localPath'] = $localPath;
                    $result['status']    = 'downloaded';
                    $downloaded          = true;
                } catch (\Exception $e) {
                    $this->logger->warning(
                        'DSO: bijlage download attempt failed',
                        [
                            'url'     => $url,
                            'attempt' => $attempt,
                            'error'   => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end while

            $results[] = $result;
        }//end foreach

        return $results;

    }//end downloadBijlagen()

    /**
     * Map DSO activiteiten to zaaktypen using the provided mapping table.
     *
     * For each activiteit, looks up its 'code' in the mappingTable.
     * Returns an array of activiteiten enriched with their zaaktype assignment,
     * and a separate list of unmatched activiteiten.
     *
     * @param array $activiteiten Array of activiteit objects (each with a 'code' key).
     * @param array $mappingTable Mapping keyed by activiteitCode, each value containing
     *                            'zaaktypeIdentificatie' and 'samenloopStrategie'.
     *
     * @return array Associative array with 'mapped' and 'unmapped' sub-arrays.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-6
     */
    public function mapActiviteitenToZaaktypen(array $activiteiten, array $mappingTable): array
    {
        $mapped   = [];
        $unmapped = [];

        foreach ($activiteiten as $activiteit) {
            $code = ($activiteit['code'] ?? null);

            if ($code === null || isset($mappingTable[$code]) === false) {
                $activiteit['zaaktypeIdentificatie'] = null;
                $activiteit['samenloopStrategie']    = null;
                $activiteit['mapped'] = false;
                $unmapped[]           = $activiteit;
                continue;
            }

            $mapping = $mappingTable[$code];

            $activiteit['zaaktypeIdentificatie'] = ($mapping['zaaktypeIdentificatie'] ?? null);
            $activiteit['samenloopStrategie']    = ($mapping['samenloopStrategie'] ?? 'deelzaken');
            $activiteit['mapped'] = true;
            $mapped[] = $activiteit;
        }//end foreach

        return [
            'mapped'   => $mapped,
            'unmapped' => $unmapped,
        ];

    }//end mapActiviteitenToZaaktypen()

    /**
     * Return the default hardcoded mapping table of DSO activiteitcodes to zaaktypen.
     *
     * Contains 25+ default mappings covering the most common Omgevingswet activiteiten.
     * Each entry has dsoActiviteitCode, zaaktypeIdentificatie, samenloopStrategie,
     * and isActief flags.
     *
     * @return array Array of default mapping objects.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-6
     */
    public function getDefaultMappings(): array
    {
        return [
            [
                'dsoActiviteitCode'     => 'bouwen-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-BOUWEN-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'kappen-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-KAPPEN-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'uitrit-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-UITRIT-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'milieu-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-MILIEU-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'slopen-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-SLOPEN-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'reclame-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-RECLAME-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'opslaan-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-OPSLAG-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'lozen-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-LOZEN-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'monument-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-MONUMENT-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'inrit-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-INRIT-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'weg-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-WEG-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'water-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-WATER-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'grond-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-GROND-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'natuur-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-NATUUR-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'geluid-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-GELUID-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'lucht-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-LUCHT-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'bodem-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-BODEM-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'brand-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-BRAND-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'evenement-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-EVENEMENT-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'gebruik-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-GEBRUIK-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'inrichting-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-INRICHTING-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'aanleg-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-AANLEG-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'vellen-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-VELLEN-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'reclamebord-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-RECLAMEBORD-2024',
                'samenloopStrategie'    => 'gecombineerd',
                'isActief'              => true,
            ],
            [
                'dsoActiviteitCode'     => 'energie-01',
                'zaaktypeIdentificatie' => 'ZAAKTYPE-ENERGIE-2024',
                'samenloopStrategie'    => 'deelzaken',
                'isActief'              => true,
            ],
        ];

    }//end getDefaultMappings()

    /**
     * Determine the samenloop strategy for a set of mapped activiteiten.
     *
     * Returns 'gecombineerd' only when ALL mapped activiteiten carry that strategy.
     * Returns 'deelzaken' in all other cases (including an empty set).
     *
     * @param array $mappedActiviteiten Array of mapped activiteit objects, each with a
     *                                  'samenloopStrategie' key.
     *
     * @return string Either 'gecombineerd' or 'deelzaken'.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-7
     */
    public function determineSamenloopStrategy(array $mappedActiviteiten): string
    {
        if (count($mappedActiviteiten) === 0) {
            return 'deelzaken';
        }

        foreach ($mappedActiviteiten as $activiteit) {
            $strategy = ($activiteit['samenloopStrategie'] ?? 'deelzaken');
            if ($strategy !== 'gecombineerd') {
                return 'deelzaken';
            }
        }

        return 'gecombineerd';

    }//end determineSamenloopStrategy()

    /**
     * Handle samenloop by selecting and executing the correct strategy.
     *
     * Calls determineSamenloopStrategy() and then delegates to either
     * createHoofdzaakWithDeelzaken() or createGecombineerdZaak().
     *
     * @param array $verzoek            The parsed DSO verzoek data.
     * @param array $mappedActiviteiten Array of activiteiten with zaaktype assignments.
     *
     * @return array Array of created zaak identifiers.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-7
     */
    public function handleSamenloop(array $verzoek, array $mappedActiviteiten): array
    {
        $strategy = $this->determineSamenloopStrategy(mappedActiviteiten: $mappedActiviteiten);

        $this->logger->info(
            'DSO: samenloop strategy determined',
            [
                'verzoekId' => ($verzoek['verzoekId'] ?? null),
                'strategy'  => $strategy,
            ]
        );

        if ($strategy === 'deelzaken') {
            return $this->createHoofdzaakWithDeelzaken(
                verzoek: $verzoek,
                mappedActiviteiten: $mappedActiviteiten
            );
        }

        return $this->createGecombineerdZaak(
            verzoek: $verzoek,
            mappedActiviteiten: $mappedActiviteiten
        );

    }//end handleSamenloop()

    /**
     * Create a hoofdzaak with one deelzaak per mapped activiteit.
     *
     * @param array $verzoek            The parsed DSO verzoek data.
     * @param array $mappedActiviteiten Array of activiteiten with zaaktype assignments.
     *
     * @return array Array with 'hoofdzaakId' and 'deelzaakIds' keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-7
     */
    public function createHoofdzaakWithDeelzaken(array $verzoek, array $mappedActiviteiten): array
    {
        $hoofdzaak = $this->createZaak(
            verzoek: $verzoek,
            zaaktypeIdentificatie: 'aanvraag-meerdere-activiteiten',
            strategy: 'hoofdzaak'
        );

        $hoofdzaakId = ($hoofdzaak['id'] ?? uniqid(prefix: 'zaak-', more_entropy: true));
        $deelzaakIds = [];

        foreach ($mappedActiviteiten as $activiteit) {
            $zaaktypeId  = ($activiteit['zaaktypeIdentificatie'] ?? 'onbekend');
            $deelVerzoek = $verzoek;
            $deelVerzoek['activiteiten'] = [$activiteit];
            $deelVerzoek['hoofdzaakId']  = $hoofdzaakId;

            $deelzaak      = $this->createZaak(
                verzoek: $deelVerzoek,
                zaaktypeIdentificatie: $zaaktypeId,
                strategy: 'deelzaak'
            );
            $deelzaakIds[] = ($deelzaak['id'] ?? uniqid(prefix: 'deelzaak-', more_entropy: true));
        }//end foreach

        return [
            'hoofdzaakId' => $hoofdzaakId,
            'deelzaakIds' => $deelzaakIds,
        ];

    }//end createHoofdzaakWithDeelzaken()

    /**
     * Create one combined zaak for all mapped activiteiten.
     *
     * Activiteiten are stored as eigenschappen on the single zaak.
     *
     * @param array $verzoek            The parsed DSO verzoek data.
     * @param array $mappedActiviteiten Array of activiteiten with zaaktype assignments.
     *
     * @return array Array with a single 'zaakId' key.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-7
     */
    public function createGecombineerdZaak(array $verzoek, array $mappedActiviteiten): array
    {
        $gecombineerdVerzoek = $verzoek;
        $gecombineerdVerzoek['activiteiten']  = $mappedActiviteiten;
        $gecombineerdVerzoek['eigenschappen'] = $this->buildActiviteitenEigenschappen(
            activiteiten: $mappedActiviteiten
        );

        $zaak = $this->createZaak(
            verzoek: $gecombineerdVerzoek,
            zaaktypeIdentificatie: 'aanvraag-gecombineerd',
            strategy: 'gecombineerd'
        );

        return [
            'zaakId' => ($zaak['id'] ?? uniqid(prefix: 'zaak-', more_entropy: true)),
        ];

    }//end createGecombineerdZaak()

    /**
     * Handle an unmapped activiteitcode by creating a triage zaak.
     *
     * Logs a notification about the unknown activiteit and creates a zaak
     * with type 'onbekend-dso-activiteit' for manual triage.
     *
     * @param array  $verzoek        The parsed DSO verzoek data.
     * @param string $activiteitCode The unrecognised DSO activiteitcode.
     *
     * @return array Array with 'zaakId' and 'status' keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-8
     */
    public function handleUnmappedActiviteit(array $verzoek, string $activiteitCode): array
    {
        $this->logger->warning(
            'DSO: unmapped activiteit encountered, creating triage zaak',
            [
                'verzoekId'      => ($verzoek['verzoekId'] ?? null),
                'activiteitCode' => $activiteitCode,
            ]
        );

        $triageVerzoek = $verzoek;
        $triageVerzoek['activiteitCode'] = $activiteitCode;
        $triageVerzoek['triageReason']   = 'Onbekende DSO activiteitcode: '.$activiteitCode;

        $zaak = $this->createZaak(
            verzoek: $triageVerzoek,
            zaaktypeIdentificatie: 'onbekend-dso-activiteit',
            strategy: 'triage'
        );

        return [
            'zaakId' => ($zaak['id'] ?? uniqid(prefix: 'triage-', more_entropy: true)),
            'status' => 'triage',
        ];

    }//end handleUnmappedActiviteit()

    /**
     * Create a zaak structure from a verzoek.
     *
     * Maps all relevant verzoek fields to a zaak record and assigns the given
     * zaaktypeIdentificatie and strategy.
     *
     * @param array  $verzoek               The parsed DSO verzoek data.
     * @param string $zaaktypeIdentificatie The zaaktype to assign.
     * @param string $strategy              Processing strategy hint ('single', 'hoofdzaak',
     *                                      'deelzaak', 'gecombineerd', 'triage').
     *
     * @return array Zaak array with 'id', 'zaaktypeIdentificatie', 'status', 'aanvrager',
     *               and 'locatie' keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-9
     */
    public function createZaak(array $verzoek, string $zaaktypeIdentificatie, string $strategy='single'): array
    {
        $zaakId = uniqid(prefix: 'zaak-', more_entropy: true);

        return [
            'id'                    => $zaakId,
            'zaaktypeIdentificatie' => $zaaktypeIdentificatie,
            'status'                => 'ontvangen',
            'aanvrager'             => ($verzoek['aanvrager'] ?? null),
            'locatie'               => ($verzoek['locatie'] ?? null),
            'verzoekId'             => ($verzoek['verzoekId'] ?? null),
            'indieningsdatum'       => ($verzoek['indieningsdatum'] ?? null),
            'type'                  => ($verzoek['type'] ?? null),
            'activiteiten'          => ($verzoek['activiteiten'] ?? []),
            'bronorganisatie'       => ($verzoek['bronorganisatie'] ?? null),
            'strategy'              => $strategy,
            'aangemaaktOp'          => date('c'),
        ];

    }//end createZaak()

    /**
     * Validate a client certificate file for DSO mTLS.
     *
     * Reads the certificate, checks its expiry date, and flags a warning if the
     * certificate expires within 30 days.
     *
     * @param string $certPath Absolute path to the PEM certificate file.
     *
     * @return array Array with 'valid', 'expiryDate', 'daysRemaining', and 'warning' keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-12
     */
    public function validateCertificate(string $certPath): array
    {
        if (file_exists(filename: $certPath) === false) {
            return [
                'valid'         => false,
                'expiryDate'    => null,
                'daysRemaining' => 0,
                'warning'       => false,
            ];
        }

        $certContent = file_get_contents(filename: $certPath);

        if ($certContent === false) {
            return [
                'valid'         => false,
                'expiryDate'    => null,
                'daysRemaining' => 0,
                'warning'       => false,
            ];
        }

        $parsed = openssl_x509_parse(certificate: $certContent);

        if ($parsed === false) {
            return [
                'valid'         => false,
                'expiryDate'    => null,
                'daysRemaining' => 0,
                'warning'       => false,
            ];
        }

        $validTo    = ($parsed['validTo_time_t'] ?? 0);
        $now        = time();
        $remaining  = (int) round(num: (($validTo - $now) / 86400));
        $expiryDate = date('Y-m-d', $validTo);
        $isValid    = ($validTo > $now);
        $hasWarning = ($isValid === true && $remaining <= 30);

        return [
            'valid'         => $isValid,
            'expiryDate'    => $expiryDate,
            'daysRemaining' => $remaining,
            'warning'       => $hasWarning,
        ];

    }//end validateCertificate()

    /**
     * Test connectivity to the DSO-LV API endpoint.
     *
     * Makes a lightweight probe GET request to the given API URL and measures
     * response time. Optionally uses a client certificate for mTLS.
     *
     * @param string      $apiUrl   The DSO-LV API base URL to probe.
     * @param string|null $certPath Optional path to client certificate for mTLS.
     *
     * @return array Array with 'success', 'message', and 'responseTime' (in seconds) keys.
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-13
     */
    public function testDSOConnection(string $apiUrl, ?string $certPath=null): array
    {
        $start         = microtime(as_float: true);
        $clientOptions = ['timeout' => 10];

        if ($certPath !== null) {
            $certStatus = $this->validateCertificate(certPath: $certPath);
            if ($certStatus['valid'] === false) {
                return [
                    'success'      => false,
                    'message'      => 'Client certificate is invalid or not found',
                    'responseTime' => 0.0,
                ];
            }

            $clientOptions['cert'] = $certPath;
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(
                uri: $apiUrl,
                options: $clientOptions
            );

            $responseTime = (microtime(as_float: true) - $start);
            $statusCode   = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success'      => true,
                    'message'      => 'DSO-LV API bereikbaar (HTTP '.$statusCode.')',
                    'responseTime' => round(num: $responseTime, precision: 3),
                ];
            }

            return [
                'success'      => false,
                'message'      => 'DSO-LV API responded with HTTP '.$statusCode,
                'responseTime' => round(num: $responseTime, precision: 3),
            ];
        } catch (\Exception $e) {
            $responseTime = (microtime(as_float: true) - $start);

            $this->logger->error(
                'DSO: connection test failed',
                [
                    'apiUrl' => $apiUrl,
                    'error'  => $e->getMessage(),
                ]
            );

            return [
                'success'      => false,
                'message'      => 'Verbinding mislukt: '.$e->getMessage(),
                'responseTime' => round(num: $responseTime, precision: 3),
            ];
        }//end try

    }//end testDSOConnection()

    /**
     * Build eigenschappen array from mapped activiteiten.
     *
     * Used by createGecombineerdZaak to serialise activiteiten as zaak eigenschappen.
     *
     * @param array $activiteiten Array of mapped activiteit objects.
     *
     * @return array Array of eigenschap objects with 'naam' and 'waarde' keys.
     */
    private function buildActiviteitenEigenschappen(array $activiteiten): array
    {
        $eigenschappen = [];
        $index         = 1;

        foreach ($activiteiten as $activiteit) {
            $code = ($activiteit['code'] ?? 'onbekend');

            $eigenschappen[] = [
                'naam'   => 'activiteit-'.$index,
                'waarde' => $code,
            ];

            $index++;
        }//end foreach

        return $eigenschappen;

    }//end buildActiviteitenEigenschappen()
}//end class
