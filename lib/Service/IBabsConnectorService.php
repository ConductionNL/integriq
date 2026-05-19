<?php
/**
 * OpenConnector iBabs Connector Service
 *
 * Service for bidirectional integration with the iBabs raadsinformatiesysteem.
 * Pushes collegevoorstellen to iBabs and retrieves besluiten.
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

use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Db\SourceMapper;
use Psr\Log\LoggerInterface;

/**
 * Service for iBabs RIS integration.
 *
 * Handles document push (voorstellen, bijlagen), agendapunt creation,
 * and besluit/besluitenlijst retrieval via the iBabs REST API.
 */
class IBabsConnectorService
{


    /**
     * IBabsConnectorService constructor.
     *
     * @param CallService     $callService  The call service for API requests
     * @param SourceMapper    $sourceMapper The source mapper for source lookup
     * @param LoggerInterface $logger       Logger for error handling
     */
    public function __construct(
        private readonly CallService $callService,
        private readonly SourceMapper $sourceMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Test the connection to an iBabs API source.
     *
     * Makes a lightweight GET request to list vergaderingen to verify connectivity.
     *
     * @param Source $source The iBabs source configuration.
     *
     * @return array Result with 'success' boolean and 'message' string.
     */
    public function testConnection(Source $source): array
    {
        try {
            $config = $source->getConfiguration();
            if (is_string($config) === true) {
                $config = json_decode($config, true);
            }

            $organisatieId = $config['organisatieId'] ?? null;
            if ($organisatieId === null) {
                return [
                    'success' => false,
                    'message' => 'Organisation ID not configured',
                ];
            }

            // Use CallService to make a lightweight API call.
            $endpoint = '/api/v1/organisations/'.$organisatieId.'/vergaderingen';
            $response = $this->callService->call(
                source: $source,
                endpoint: $endpoint,
                method: 'GET'
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                return [
                    'success' => true,
                    'message' => 'Connection successful',
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
     * @param Source $source   The iBabs source configuration.
     * @param array  $voorstel The voorstel data including document path and metadata.
     *
     * @return array Result with 'success' boolean and 'vergaderstukId'.
     */
    public function pushVoorstel(Source $source, array $voorstel): array
    {
        $config = $source->getConfiguration();
        if (is_string($config) === true) {
            $config = json_decode($config, true);
        }

        $organisatieId = $config['organisatieId'] ?? null;

        $metadata = [
            'onderwerp'          => $voorstel['onderwerp'] ?? '',
            'portefeuillehouder' => $voorstel['portefeuillehouder'] ?? '',
            'zaaktype'           => $voorstel['zaaktype'] ?? '',
            'vertrouwelijk'      => $voorstel['geheimhouding'] ?? false,
        ];

        $this->logger->info('iBabs: Pushing voorstel', ['onderwerp' => $metadata['onderwerp']]);

        // Placeholder for actual document upload via CallService.
        return [
            'success'        => false,
            'message'        => 'Document upload not yet implemented',
            'vergaderstukId' => null,
        ];

    }//end pushVoorstel()


    /**
     * Poll for besluiten from iBabs.
     *
     * Queries the iBabs API for besluiten related to previously pushed
     * voorstellen and returns an array of besluit data with status mappings.
     *
     * @param Source $source The iBabs source configuration.
     *
     * @return array Array of besluit records with zaak references and status.
     */
    public function pollBesluiten(Source $source): array
    {
        $this->logger->info('iBabs: Polling for besluiten');

        // Placeholder for actual besluit polling.
        return [];

    }//end pollBesluiten()


    /**
     * Map an iBabs besluit status to a Procest zaak status.
     *
     * @param string $ibabsStatus The iBabs besluit status.
     *
     * @return string The corresponding Procest zaak status.
     */
    public function mapBesluitStatus(string $ibabsStatus): string
    {
        $mapping = [
            'aangenomen'    => 'Besluit: aangenomen',
            'verworpen'     => 'Besluit: verworpen',
            'aangehouden'   => 'Besluit: aangehouden',
            'doorgeschoven' => 'Besluit: doorgeschoven',
        ];

        return $mapping[strtolower($ibabsStatus)] ?? 'Besluit: onbekend';

    }//end mapBesluitStatus()


}//end class
