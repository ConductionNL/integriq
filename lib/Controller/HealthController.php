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
        parent::__construct(appName: $appName, request: $request);

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
            $status = 'error';
            $this->logger->error('Health check: database failed', ['exception' => $e->getMessage()]);
        }

        // OR-cutover: the legacy `oc_openconnector_sources` table was
        // dropped by Version2Date20260520000099. Sources now live as OR
        // objects. A targeted aggregate over `oc_openregister_objects`
        // filtered to `register='openconnector', schema='source'` is the
        // post-cutover equivalent of the old table-existence probe.
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) AS cnt'))
                ->from('openregister_objects', 'o')
                ->innerJoin('o', 'openregister_registers', 'r', $qb->expr()->eq('o.register', 'r.id'))
                ->innerJoin('o', 'openregister_schemas',   's', $qb->expr()->eq('o.schema', 's.id'))
                ->where($qb->expr()->eq('r.slug', $qb->createNamedParameter('openconnector')))
                ->andWhere($qb->expr()->eq('s.slug', $qb->createNamedParameter('source')));
            $result = $qb->executeQuery();
            $result->closeCursor();
            $checks['sources_table'] = 'ok';
        } catch (\Exception $e) {
            $checks['sources_table'] = 'error';
            $status = 'degraded';
            $this->logger->warning('Health check: sources store not accessible', ['exception' => $e->getMessage()]);
        }

        return new JSONResponse(
            [
                'status' => $status,
                'checks' => $checks,
            ]
        );

    }//end index()
}//end class
