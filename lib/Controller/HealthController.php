<?php
/**
 * OpenConnector Health Controller
 *
 * Controller for exposing health check status.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
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

namespace OCA\OpenConnector\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for health check endpoint.
 *
 * Returns JSON indicating whether the application and its dependencies are healthy.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class HealthController extends Controller
{


    /**
     * HealthController constructor.
     *
     * @param string          $appName The name of the app
     * @param IRequest        $request Request object
     * @param IDBConnection   $db      The database connection
     * @param LoggerInterface $logger  Logger for error handling
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Return health check status.
     *
     * @return JSONResponse JSON response with health status and checks.
     *
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        $checks = [];
        $status = 'ok';

        // Database check.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('1'));
            $result = $qb->executeQuery();
            $result->closeCursor();
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error';
            $status              = 'error';
            $this->logger->error('Health check: database failed', ['exception' => $e->getMessage()]);
        }

        // Source table check.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) AS cnt'))
                ->from('openconnector_sources');
            $result = $qb->executeQuery();
            $result->closeCursor();
            $checks['sources_table'] = 'ok';
        } catch (\Exception $e) {
            $checks['sources_table'] = 'error';
            $status                   = 'degraded';
            $this->logger->warning('Health check: sources table not accessible', ['exception' => $e->getMessage()]);
        }

        return new JSONResponse(
            [
                'status' => $status,
                'checks' => $checks,
            ]
        );

    }//end index()


}//end class
