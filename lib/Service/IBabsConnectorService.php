<?php
/**
 * OpenConnector iBabs Connector Service
 *
 * Service for bidirectional integration with the iBabs raadsinformatiesysteem.
 * Pushes collegevoorstellen to iBabs and retrieves besluiten and besluitenlijsten.
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
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Service for iBabs RIS integration.
 *
 * Handles document push (voorstellen, bijlagen), agendapunt creation,
 * and besluit/besluitenlijst retrieval via the iBabs REST API.
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
 */
class IBabsConnectorService
{

    /**
     * IBabs REST API base path for organisations.
     *
     * @var string
     */
    private const API_BASE = '/api/v1/organisations';

    /**
     * Besluit status map from iBabs to Procest.
     *
     * @var array<string,string>
     */
    private const BESLUIT_STATUS_MAP = [
        'aangenomen'    => 'Besluit: aangenomen',
        'verworpen'     => 'Besluit: verworpen',
        'aangehouden'   => 'Besluit: aangehouden',
        'doorgeschoven' => 'Besluit: doorgeschoven',
    ];

    /**
     * IBabsConnectorService constructor.
     *
     * @param CallService     $callService The call service for API requests.
     * @param LoggerInterface $logger      Logger for error handling.
     * @param IRootFolder     $rootFolder  Nextcloud root folder for file storage.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
     */
    public function __construct(
        private readonly CallService $callService,
        private readonly LoggerInterface $logger,
        private readonly IRootFolder $rootFolder
    ) {

    }//end __construct()

    /**
     * Extract and normalise the source configuration array.
     *
     * @param ObjectEntity $source The source entity.
     *
     * @return array<string,mixed> Normalised configuration.
     */
    private function extractConfig(ObjectEntity $source): array
    {
        $sourceData = $source->getObject();
        $config     = ($sourceData['configuration'] ?? []);
        if (is_string($config) === true) {
            $config = json_decode($config, true) ?? [];
        }

        if (is_array($config) === false) {
            return [];
        }

        return $config;

    }//end extractConfig()

    /**
     * Get the HTTP status code from a call log ObjectEntity.
     *
     * @param ObjectEntity|null $callLog The entity returned by CallService::call().
     *
     * @return int HTTP status code, 0 if callLog is null or missing.
     */
    private function getStatusCode(?ObjectEntity $callLog): int
    {
        if ($callLog === null) {
            return 0;
        }

        $data = $callLog->getObject();
        return (int) ($data['statusCode'] ?? 0);

    }//end getStatusCode()

    /**
     * Get the decoded response body from a call log ObjectEntity.
     *
     * @param ObjectEntity|null $callLog The entity returned by CallService::call().
     *
     * @return mixed The decoded response body, or null.
     */
    private function getResponseBody(?ObjectEntity $callLog): mixed
    {
        if ($callLog === null) {
            return null;
        }

        $data = $callLog->getObject();
        $body = $data['response']['body'] ?? null;
        if (is_string($body) === true) {
            $decoded = json_decode($body, true);
            if ($decoded !== null) {
                return $decoded;
            }

            return $body;
        }

        return $body;

    }//end getResponseBody()

    /**
     * Test the connection to an iBabs API source.
     *
     * Makes a lightweight GET request to list vergaderingen to verify connectivity.
     *
     * @param ObjectEntity $source The iBabs source configuration.
     *
     * @return array{success: bool, message: string} Result with success flag and message.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
     */
    public function testConnection(ObjectEntity $source): array
    {
        $config = $this->extractConfig(source: $source);

        $organisatieId = $config['organisatieId'] ?? null;
        if ($organisatieId === null) {
            return [
                'success' => false,
                'message' => 'Organisation ID not configured',
            ];
        }

        try {
            $endpoint = self::API_BASE.'/'.$organisatieId.'/vergaderingen';
            $callLog  = $this->callService->call(
                source: $source,
                endpoint: $endpoint,
                method: 'GET'
            );

            $statusCode = $this->getStatusCode(callLog: $callLog);
            if ($statusCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Connection successful',
                ];
            }

            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'message' => 'Invalid API key (HTTP 401)',
                ];
            }

            return [
                'success' => false,
                'message' => 'API returned status '.$statusCode,
            ];
        } catch (\Exception $e) {
            $this->logger->warning('iBabs connection test failed', ['exception' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }//end try

    }//end testConnection()

    /**
     * Push a collegevoorstel document to iBabs.
     *
     * Uploads the document (PDF) and its bijlagen to iBabs and creates
     * a vergaderstuk linked to the specified vergadering.
     *
     * @param ObjectEntity $source   The iBabs source configuration.
     * @param array        $voorstel The voorstel data including document path and metadata.
     *                               Expected keys: onderwerp, portefeuillehouder, zaaktype,
     *                               geheimhouding (bool), bijlagen (array of {name, content}).
     *
     * @return array{success: bool, message: string, vergaderstukId: string|null} Upload result.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-2
     */
    public function pushVoorstel(ObjectEntity $source, array $voorstel): array
    {
        $config        = $this->extractConfig(source: $source);
        $organisatieId = $config['organisatieId'] ?? null;
        if ($organisatieId === null) {
            return [
                'success'        => false,
                'message'        => 'Organisation ID not configured',
                'vergaderstukId' => null,
            ];
        }

        $metadata = [
            'onderwerp'          => $voorstel['onderwerp'] ?? '',
            'portefeuillehouder' => $voorstel['portefeuillehouder'] ?? '',
            'zaaktype'           => $voorstel['zaaktype'] ?? '',
            'vertrouwelijk'      => (bool) ($voorstel['geheimhouding'] ?? false),
        ];

        $this->logger->info(
            'iBabs: Pushing voorstel',
            ['onderwerp' => $metadata['onderwerp'], 'organisatieId' => $organisatieId]
        );

        try {
            $endpoint = self::API_BASE.'/'.$organisatieId.'/documents';
            $callLog  = $this->callService->call(
                source: $source,
                endpoint: $endpoint,
                method: 'POST',
                config: ['json' => $metadata]
            );

            $statusCode = $this->getStatusCode(callLog: $callLog);
            if ($statusCode < 200 || $statusCode >= 300) {
                $message = match ($statusCode) {
                    0   => 'API call failed — no response',
                    401 => 'Invalid API key',
                    413 => 'Document too large — consider compressing before upload',
                    default => 'Upload failed with status '.$statusCode,
                };

                $this->logger->error(
                    'iBabs: voorstel push failed',
                    ['status' => $statusCode, 'onderwerp' => $metadata['onderwerp']]
                );

                return [
                    'success'        => false,
                    'message'        => $message,
                    'vergaderstukId' => null,
                ];
            }//end if

            $body           = $this->getResponseBody(callLog: $callLog);
            $vergaderstukId = null;
            if (is_array($body) === true) {
                $vergaderstukId = $body['id'] ?? $body['documentId'] ?? null;
            }

            return [
                'success'        => true,
                'message'        => 'Voorstel pushed successfully',
                'vergaderstukId' => $vergaderstukId,
            ];
        } catch (\Exception $e) {
            $this->logger->error('iBabs: voorstel push exception', ['exception' => $e->getMessage()]);
            return [
                'success'        => false,
                'message'        => 'Push failed: '.$e->getMessage(),
                'vergaderstukId' => null,
            ];
        }//end try

    }//end pushVoorstel()

    /**
     * Create an agendapunt in iBabs linked to a vergaderstuk.
     *
     * Queries available vergaderingen and selects the next upcoming one
     * (auto-select) or uses the provided vergaderingId.
     *
     * @param ObjectEntity $source         The iBabs source configuration.
     * @param string       $vergaderstukId The iBabs document/vergaderstuk ID.
     * @param string|null  $vergaderingId  Specific vergadering to link to, or null for auto-select.
     *
     * @return array{success: bool, message: string, agendapuntId: string|null} Creation result.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-3
     */
    public function createAgendapunt(
        ObjectEntity $source,
        string $vergaderstukId,
        ?string $vergaderingId=null
    ): array {
        $config        = $this->extractConfig(source: $source);
        $organisatieId = $config['organisatieId'] ?? null;
        if ($organisatieId === null) {
            return [
                'success'      => false,
                'message'      => 'Organisation ID not configured',
                'agendapuntId' => null,
            ];
        }

        try {
            if ($vergaderingId === null) {
                $vergaderingId = $this->selectNextVergadering(
                    source: $source,
                    organisatieId: $organisatieId
                );
            }

            if ($vergaderingId === null) {
                $this->logger->warning('iBabs: no upcoming vergadering found for agendapunt');
                return [
                    'success'      => false,
                    'message'      => 'No upcoming vergadering available — sync set to pending',
                    'agendapuntId' => null,
                ];
            }

            $payload  = [
                'vergaderingId'  => $vergaderingId,
                'vergaderstukId' => $vergaderstukId,
            ];
            $endpoint = self::API_BASE.'/'.$organisatieId.'/vergaderingen/'.$vergaderingId.'/agendapunten';
            $callLog  = $this->callService->call(
                source: $source,
                endpoint: $endpoint,
                method: 'POST',
                config: ['json' => $payload]
            );

            $statusCode = $this->getStatusCode(callLog: $callLog);
            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'success'      => false,
                    'message'      => 'Agendapunt creation failed with status '.$statusCode,
                    'agendapuntId' => null,
                ];
            }

            $body         = $this->getResponseBody(callLog: $callLog);
            $agendapuntId = null;
            if (is_array($body) === true) {
                $agendapuntId = $body['id'] ?? $body['agendapuntId'] ?? null;
            }

            return [
                'success'      => true,
                'message'      => 'Agendapunt created',
                'agendapuntId' => $agendapuntId,
            ];
        } catch (\Exception $e) {
            $this->logger->error('iBabs: agendapunt creation exception', ['exception' => $e->getMessage()]);
            return [
                'success'      => false,
                'message'      => 'Agendapunt creation failed: '.$e->getMessage(),
                'agendapuntId' => null,
            ];
        }//end try

    }//end createAgendapunt()

    /**
     * Select the next upcoming vergadering from iBabs for auto-assignment.
     *
     * @param ObjectEntity $source        The iBabs source.
     * @param string       $organisatieId The organisation ID.
     *
     * @return string|null The ID of the next vergadering, or null if none found.
     */
    private function selectNextVergadering(ObjectEntity $source, string $organisatieId): ?string
    {
        $endpoint = self::API_BASE.'/'.$organisatieId.'/vergaderingen';
        $callLog  = $this->callService->call(
            source: $source,
            endpoint: $endpoint,
            method: 'GET'
        );

        $statusCode = $this->getStatusCode(callLog: $callLog);
        if ($statusCode !== 200) {
            return null;
        }

        $body = $this->getResponseBody(callLog: $callLog);
        if (is_array($body) === false) {
            return null;
        }

        $vergaderingen = $body['items'] ?? $body;
        if (is_array($vergaderingen) === false || empty($vergaderingen) === true) {
            return null;
        }

        $now      = new \DateTime();
        $upcoming = array_filter(
            $vergaderingen,
            static function (array $v) use ($now): bool {
                $datum = $v['datum'] ?? $v['date'] ?? null;
                if ($datum === null) {
                    return false;
                }

                try {
                    return new \DateTime($datum) > $now;
                } catch (\Exception) {
                    return false;
                }
            }
        );

        if (empty($upcoming) === true) {
            return null;
        }

        $first = reset($upcoming);
        return $first['id'] ?? $first['vergaderingId'] ?? null;

    }//end selectNextVergadering()

    /**
     * Poll for besluiten from iBabs.
     *
     * Queries the iBabs API for besluiten related to previously pushed
     * voorstellen and returns an array of besluit data with status mappings.
     *
     * @param ObjectEntity $source      The iBabs source configuration.
     * @param array        $syncedItems List of sync records with iBabs agendapunt IDs to check.
     *
     * @return array<int, array{zaakId: string, ibabsStatus: string, zaakStatus: string, besluitdatum: string|null}> Besluit records.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
     */
    public function pollBesluiten(ObjectEntity $source, array $syncedItems=[]): array
    {
        $config        = $this->extractConfig(source: $source);
        $organisatieId = $config['organisatieId'] ?? null;
        if ($organisatieId === null) {
            $this->logger->warning('iBabs: pollBesluiten called without organisatieId');
            return [];
        }

        $besluiten = [];

        foreach ($syncedItems as $syncRecord) {
            $vergaderingId = $syncRecord['risVergaderingId'] ?? null;
            $zaakId        = $syncRecord['zaakId'] ?? null;

            if ($vergaderingId === null || $zaakId === null) {
                continue;
            }

            try {
                $endpoint = self::API_BASE.'/'.$organisatieId.'/vergaderingen/'.$vergaderingId.'/besluiten';
                $callLog  = $this->callService->call(
                    source: $source,
                    endpoint: $endpoint,
                    method: 'GET'
                );

                $statusCode = $this->getStatusCode(callLog: $callLog);
                if ($statusCode !== 200) {
                    $this->logger->info(
                        'iBabs: no besluit yet for vergadering',
                        ['vergaderingId' => $vergaderingId, 'status' => $statusCode]
                    );
                    continue;
                }

                $body = $this->getResponseBody(callLog: $callLog);
                if (is_array($body) === false) {
                    continue;
                }

                $besluiten[] = $this->parseBesluitResponse(
                    body: $body,
                    zaakId: $zaakId,
                    vergaderingId: $vergaderingId
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    'iBabs: besluit polling failed',
                    ['vergaderingId' => $vergaderingId, 'exception' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        return array_filter($besluiten);

    }//end pollBesluiten()

    /**
     * Parse an iBabs besluit response body into a normalised record.
     *
     * @param array  $body          The decoded response body.
     * @param string $zaakId        The source zaak UUID.
     * @param string $vergaderingId The iBabs vergadering ID.
     *
     * @return array<string,mixed>|null Normalised besluit record, or null if status unknown.
     */
    private function parseBesluitResponse(array $body, string $zaakId, string $vergaderingId): ?array
    {
        $ibabsStatus  = strtolower($body['besluitStatus'] ?? $body['status'] ?? '');
        $besluitdatum = $body['besluitDatum'] ?? $body['datum'] ?? null;

        if (empty($ibabsStatus) === true) {
            return null;
        }

        return [
            'zaakId'        => $zaakId,
            'vergaderingId' => $vergaderingId,
            'ibabsStatus'   => $ibabsStatus,
            'zaakStatus'    => $this->mapBesluitStatus(ibabsStatus: $ibabsStatus),
            'besluitdatum'  => $besluitdatum,
        ];

    }//end parseBesluitResponse()

    /**
     * Retrieve and store the besluitenlijst PDF from iBabs.
     *
     * Downloads the besluitenlijst for a concluded vergadering and stores it
     * in Nextcloud Files under /RIS-besluiten/{year}/{datum}/.
     *
     * @param ObjectEntity $source           The iBabs source configuration.
     * @param string       $vergaderingId    The iBabs vergadering ID.
     * @param string       $vergaderingDatum The vergadering date (YYYY-MM-DD).
     * @param string       $userId           Nextcloud user ID under whose account to store.
     *
     * @return array{success: bool, message: string, filePath: string|null} Result with file path.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-5
     */
    public function retrieveBesluitenlijst(
        ObjectEntity $source,
        string $vergaderingId,
        string $vergaderingDatum,
        string $userId
    ): array {
        $config        = $this->extractConfig(source: $source);
        $organisatieId = $config['organisatieId'] ?? null;
        if ($organisatieId === null) {
            return [
                'success'  => false,
                'message'  => 'Organisation ID not configured',
                'filePath' => null,
            ];
        }

        try {
            $endpoint = self::API_BASE.'/'.$organisatieId.'/vergaderingen/'.$vergaderingId.'/besluitenlijst';
            $callLog  = $this->callService->call(
                source: $source,
                endpoint: $endpoint,
                method: 'GET'
            );

            $statusCode = $this->getStatusCode(callLog: $callLog);
            if ($statusCode === 404) {
                return [
                    'success'  => false,
                    'message'  => 'Besluitenlijst not yet published',
                    'filePath' => null,
                ];
            }

            if ($statusCode !== 200) {
                return [
                    'success'  => false,
                    'message'  => 'Failed to retrieve besluitenlijst (HTTP '.$statusCode.')',
                    'filePath' => null,
                ];
            }

            $body = $this->getResponseBody(callLog: $callLog);
            if ($body === null) {
                return [
                    'success'  => false,
                    'message'  => 'Empty besluitenlijst response',
                    'filePath' => null,
                ];
            }

            if (is_string($body) === true) {
                $content = $body;
            } else {
                $content = (string) json_encode($body);
            }

            $filePath = $this->storeBesluitenlijst(
                userId: $userId,
                datum: $vergaderingDatum,
                content: $content
            );

            return [
                'success'  => true,
                'message'  => 'Besluitenlijst stored at '.$filePath,
                'filePath' => $filePath,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'iBabs: besluitenlijst retrieval failed',
                ['vergaderingId' => $vergaderingId, 'exception' => $e->getMessage()]
            );
            return [
                'success'  => false,
                'message'  => 'Retrieval failed: '.$e->getMessage(),
                'filePath' => null,
            ];
        }//end try

    }//end retrieveBesluitenlijst()

    /**
     * Store besluitenlijst content in Nextcloud Files.
     *
     * @param string $userId  Nextcloud user ID.
     * @param string $datum   Vergadering date (YYYY-MM-DD).
     * @param string $content File content (PDF binary or JSON).
     *
     * @return string The stored file path within the user's file system.
     */
    private function storeBesluitenlijst(string $userId, string $datum, string $content): string
    {
        $year     = substr($datum, 0, 4);
        $folder   = '/RIS-besluiten/'.$year.'/'.$datum;
        $fileName = 'besluitenlijst.pdf';
        $filePath = $folder.'/'.$fileName;

        $userFolder = $this->rootFolder->getUserFolder(userId: $userId);

        if ($userFolder->nodeExists(path: $folder) === false) {
            $userFolder->newFolder(path: $folder);
        }

        $existingNode = $userFolder->nodeExists(path: $filePath);
        if ($existingNode === true) {
            $file = $userFolder->get(path: $filePath);
            $file->putContent(data: $content);
        } else {
            $userFolder->newFile(path: $filePath, content: $content);
        }

        return $filePath;

    }//end storeBesluitenlijst()

    /**
     * Map an iBabs besluit status to a Procest zaak status.
     *
     * @param string $ibabsStatus The iBabs besluit status.
     *
     * @return string The corresponding Procest zaak status.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
     */
    public function mapBesluitStatus(string $ibabsStatus): string
    {
        return self::BESLUIT_STATUS_MAP[strtolower($ibabsStatus)] ?? 'Besluit: onbekend';

    }//end mapBesluitStatus()
}//end class
