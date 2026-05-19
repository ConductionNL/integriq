<?php

namespace OCA\OpenConnector\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCA\OpenConnector\Db\SynchronizationMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\SynchronizationContractMapper;
use OCA\OpenConnector\Db\ConsumerMapper;
use OCA\OpenConnector\Db\EndpointMapper;
use OCA\OpenConnector\Db\JobMapper;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\CallLogMapper;
use OCA\OpenConnector\Db\JobLogMapper;
use OCA\OpenConnector\Db\SynchronizationContractLogMapper;

/**
 * @package OCA\OpenConnector\Controller
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DashboardController extends Controller
{
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SynchronizationMapper $synchronizationMapper,
        private readonly SourceMapper $sourceMapper,
        private readonly SynchronizationContractMapper $synchronizationContractMapper,
        private readonly ConsumerMapper $consumerMapper,
        private readonly EndpointMapper $endpointMapper,
        private readonly JobMapper $jobMapper,
        private readonly MappingMapper $mappingMapper,
        private readonly CallLogMapper $callLogMapper,
        private readonly JobLogMapper $jobLogMapper,
        private readonly SynchronizationContractLogMapper $synchronizationContractLogMapper,
        private readonly IL10N $l
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(?string $getParameter): TemplateResponse
	{
        try {
            $response = new TemplateResponse(
				appName: $this->appName,
				templateName: 'index',
				params:[]
            );

            $csp = new ContentSecurityPolicy();
            $csp->addAllowedConnectDomain(domain: '*');
            $response->setContentSecurityPolicy($csp);

            return $response;
        } catch (\Exception $e) {
            return new TemplateResponse(
                appName: $this->appName,
                templateName: 'error',
                params: ['error' => $e->getMessage()],
                renderAs: '500'
            );
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        try {
            $results = [
                "sources" => $this->sourceMapper->getTotalCallCount(),
                "mappings" => $this->mappingMapper->getTotalCallCount(),
                "synchronizations" => $this->synchronizationMapper->getTotalCallCount(),
                "synchronizationContracts" => $this->synchronizationContractMapper->getTotalCallCount(),
                "jobs" => $this->jobMapper->getTotalCount(),
                "endpoints" => $this->endpointMapper->getTotalCallCount()
            ];
            return new JSONResponse($results);
        } catch (\Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }

    /**
     * Get call statistics for the dashboard
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date in ISO format
     * @param string|null $to End date in ISO format
     * @return JSONResponse
     */
    public function getCallStats(?string $from = null, ?string $to = null): JSONResponse 
    {
        try {
            $fromDate = $from ? new \DateTime($from) : (new \DateTime())->modify('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $dailyStats = $this->callLogMapper->getCallStatsByDateRange($fromDate, $toDate);
            $hourlyStats = $this->callLogMapper->getCallStatsByHourRange($fromDate, $toDate);

            return new JSONResponse([
                'daily' => $dailyStats,
                'hourly' => $hourlyStats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get job statistics for the dashboard
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date in ISO format
     * @param string|null $to End date in ISO format
     * @return JSONResponse
     */
    public function getJobStats(?string $from = null, ?string $to = null): JSONResponse 
    {
        try {
            $fromDate = $from ? new \DateTime($from) : (new \DateTime())->modify('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $dailyStats = $this->jobLogMapper->getJobStatsByDateRange($fromDate, $toDate);
            $hourlyStats = $this->jobLogMapper->getJobStatsByHourRange($fromDate, $toDate);

            return new JSONResponse([
                'daily' => $dailyStats,
                'hourly' => $hourlyStats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get synchronization statistics for the dashboard
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     * @param string|null $from Start date in ISO format
     * @param string|null $to End date in ISO format
     * @return JSONResponse
     */
    public function getSyncStats(?string $from = null, ?string $to = null): JSONResponse 
    {
        try {
            $fromDate = $from ? new \DateTime($from) : (new \DateTime())->modify('-7 days');
            $toDate = $to ? new \DateTime($to) : new \DateTime();

            $dailyStats = $this->synchronizationContractLogMapper->getSyncStatsByDateRange($fromDate, $toDate);
            $hourlyStats = $this->synchronizationContractLogMapper->getSyncStatsByHourRange($fromDate, $toDate);

            return new JSONResponse([
                'daily' => $dailyStats,
                'hourly' => $hourlyStats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
