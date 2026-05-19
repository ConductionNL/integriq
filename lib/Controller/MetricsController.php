<?php
/**
 * OpenConnector Metrics Controller
 *
 * Controller for exposing Prometheus metrics in text exposition format.
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
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for exposing Prometheus metrics.
 *
 * Provides a metrics endpoint returning data in Prometheus text exposition format
 * for monitoring sources, calls, and synchronizations.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class MetricsController extends Controller
{


    /**
     * MetricsController constructor.
     *
     * @param string          $appName The name of the app
     * @param IRequest        $request Request object
     * @param IConfig         $config  The config service
     * @param IDBConnection   $db      The database connection
     * @param LoggerInterface $logger  Logger for error handling
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IConfig $config,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Expose Prometheus metrics.
     *
     * @return TextPlainResponse Plain text response with Prometheus metrics.
     *
     * @NoCSRFRequired
     */
    public function index(): TextPlainResponse
    {
        $lines = [];

        $appVersion = $this->config->getAppValue('openconnector', 'installed_version', '0.0.0');
        $phpVersion = PHP_VERSION;
        $ncVersion  = $this->config->getSystemValueString('version', '0.0.0');

        // Info gauge.
        $lines[] = '# HELP openconnector_info Application information';
        $lines[] = '# TYPE openconnector_info gauge';
        $lines[] = 'openconnector_info{version="'.$appVersion.'",php_version="'.$phpVersion.'",nextcloud_version="'.$ncVersion.'"} 1';

        // Up gauge.
        $lines[] = '# HELP openconnector_up Whether the application is up';
        $lines[] = '# TYPE openconnector_up gauge';
        $lines[] = 'openconnector_up 1';

        // Sources total by type.
        $this->collectSourceMetrics($lines);

        // Calls total by status.
        $this->collectCallMetrics($lines);

        // Synchronizations total by status.
        $this->collectSyncMetrics($lines);

        // Endpoints total.
        $this->collectEndpointMetrics($lines);

        // Jobs total and job runs by status.
        $this->collectJobMetrics($lines);

        // Mappings and rules totals.
        $this->collectMappingRuleMetrics($lines);

        $body     = implode("\n", $lines)."\n";
        $response = new TextPlainResponse($body);
        $response->addHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        return $response;

    }//end index()


    /**
     * Collect source metrics grouped by type.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function collectSourceMetrics(array &$lines): void
    {
        $lines[] = '# HELP openconnector_sources_total Total sources by type';
        $lines[] = '# TYPE openconnector_sources_total gauge';

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('type', $qb->createFunction('COUNT(*) AS cnt'))
                ->from('openconnector_sources')
                ->groupBy('type');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            $counts = [];
            foreach ($rows as $row) {
                $type          = ($row['type'] !== null && $row['type'] !== '') ? strtolower($row['type']) : 'rest';
                $counts[$type] = (isset($counts[$type]) === true) ? $counts[$type] + (int) $row['cnt'] : (int) $row['cnt'];
            }

            if (empty($counts) === true) {
                $lines[] = 'openconnector_sources_total{type="rest"} 0';
            }

            foreach ($counts as $type => $count) {
                $lines[] = 'openconnector_sources_total{type="'.$type.'"} '.$count;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not count sources for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_sources_total{type="rest"} 0';
        }//end try

    }//end collectSourceMetrics()


    /**
     * Collect call log metrics grouped by status code.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function collectCallMetrics(array &$lines): void
    {
        $lines[] = '# HELP openconnector_calls_total Total API calls by status';
        $lines[] = '# TYPE openconnector_calls_total counter';

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('status_code', $qb->createFunction('COUNT(*) AS cnt'))
                ->from('openconnector_call_logs')
                ->groupBy('status_code');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            if (empty($rows) === true) {
                $lines[] = 'openconnector_calls_total{status="200"} 0';
            }

            foreach ($rows as $row) {
                $statusCode = $row['status_code'] ?? 'unknown';
                $lines[]    = 'openconnector_calls_total{status="'.$statusCode.'"} '.(int) $row['cnt'];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not count calls for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_calls_total{status="200"} 0';
        }//end try

    }//end collectCallMetrics()


    /**
     * Collect synchronization metrics grouped by status.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function collectSyncMetrics(array &$lines): void
    {
        $lines[] = '# HELP openconnector_synchronizations_total Total synchronization runs';
        $lines[] = '# TYPE openconnector_synchronizations_total gauge';

        try {
            $total = $this->countTable('openconnector_synchronizations');
            $lines[] = 'openconnector_synchronizations_total '.$total;
        } catch (\Exception $e) {
            $this->logger->warning('Could not count synchronizations for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_synchronizations_total 0';
        }

        // Sync logs by result for counter metric.
        $lines[] = '# HELP openconnector_synchronization_runs_total Total synchronization log entries by result';
        $lines[] = '# TYPE openconnector_synchronization_runs_total counter';

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('result', $qb->createFunction('COUNT(*) AS cnt'))
                ->from('openconnector_synchronization_logs')
                ->groupBy('result');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            if (empty($rows) === true) {
                $lines[] = 'openconnector_synchronization_runs_total{status="success"} 0';
            }

            foreach ($rows as $row) {
                $resultLabel = ($row['result'] !== null && $row['result'] !== '') ? strtolower($row['result']) : 'unknown';
                $lines[]     = 'openconnector_synchronization_runs_total{status="'.$resultLabel.'"} '.(int) $row['cnt'];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not count sync logs for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_synchronization_runs_total{status="success"} 0';
        }//end try

    }//end collectSyncMetrics()


    /**
     * Collect endpoint metrics.
     *
     * Counts total registered endpoints from the openconnector_endpoints table.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function collectEndpointMetrics(array &$lines): void
    {
        $lines[] = '# HELP openconnector_endpoints_total Total registered endpoints';
        $lines[] = '# TYPE openconnector_endpoints_total gauge';

        try {
            $total   = $this->countTable('openconnector_endpoints');
            $lines[] = 'openconnector_endpoints_total '.$total;
        } catch (\Exception $e) {
            $this->logger->warning('Could not count endpoints for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_endpoints_total 0';
        }

    }//end collectEndpointMetrics()


    /**
     * Collect job queue metrics.
     *
     * Counts total configured jobs and job log entries grouped by status
     * from the openconnector_jobs and openconnector_job_logs tables.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function collectJobMetrics(array &$lines): void
    {
        $lines[] = '# HELP openconnector_jobs_total Total configured jobs';
        $lines[] = '# TYPE openconnector_jobs_total gauge';

        try {
            $total   = $this->countTable('openconnector_jobs');
            $lines[] = 'openconnector_jobs_total '.$total;
        } catch (\Exception $e) {
            $this->logger->warning('Could not count jobs for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_jobs_total 0';
        }

        $lines[] = '# HELP openconnector_job_runs_total Total job log entries by status';
        $lines[] = '# TYPE openconnector_job_runs_total counter';

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('status', $qb->createFunction('COUNT(*) AS cnt'))
                ->from('openconnector_job_logs')
                ->groupBy('status');

            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();
            $result->closeCursor();

            if (empty($rows) === true) {
                $lines[] = 'openconnector_job_runs_total{status="success"} 0';
            }

            foreach ($rows as $row) {
                $statusLabel = ($row['status'] !== null && $row['status'] !== '') ? strtolower($row['status']) : 'unknown';
                $lines[]     = 'openconnector_job_runs_total{status="'.$statusLabel.'"} '.(int) $row['cnt'];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not count job logs for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_job_runs_total{status="success"} 0';
        }//end try

    }//end collectJobMetrics()


    /**
     * Collect mapping and rule metrics.
     *
     * Counts total configured mappings and rules from the
     * openconnector_mappings and openconnector_rules tables.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function collectMappingRuleMetrics(array &$lines): void
    {
        $lines[] = '# HELP openconnector_mappings_total Total configured mappings';
        $lines[] = '# TYPE openconnector_mappings_total gauge';

        try {
            $total   = $this->countTable('openconnector_mappings');
            $lines[] = 'openconnector_mappings_total '.$total;
        } catch (\Exception $e) {
            $this->logger->warning('Could not count mappings for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_mappings_total 0';
        }

        $lines[] = '# HELP openconnector_rules_total Total configured rules';
        $lines[] = '# TYPE openconnector_rules_total gauge';

        try {
            $total   = $this->countTable('openconnector_rules');
            $lines[] = 'openconnector_rules_total '.$total;
        } catch (\Exception $e) {
            $this->logger->warning('Could not count rules for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'openconnector_rules_total 0';
        }

    }//end collectMappingRuleMetrics()


    /**
     * Count rows in a given table.
     *
     * @param string $tableName The table name.
     *
     * @return int The row count.
     */
    private function countTable(string $tableName): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS cnt'))
            ->from($tableName);

        $result = $qb->executeQuery();
        $count  = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;

    }//end countTable()


}//end class
