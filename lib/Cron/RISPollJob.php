<?php
/**
 * OpenConnector RIS Poll Job
 *
 * Background job task for polling iBabs and NotuBiz for besluit updates.
 * Runs at a configurable interval (default 15 minutes) to retrieve besluiten
 * for all outstanding sync records and updates zaak statuses accordingly.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\IBabsConnectorService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that polls iBabs and NotuBiz for besluit updates.
 *
 * Iterates all sync records with status "synced" and checks the corresponding
 * RIS for besluit responses, updating zaak statuses in Procest.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
 */
class RISPollJob extends TimedJob
{

    /**
     * Default polling interval in seconds (15 minutes).
     *
     * @var int
     */
    private const DEFAULT_INTERVAL = 900;

    /**
     * RISPollJob constructor.
     *
     * @param ITimeFactory          $time                  Time factory for job scheduling.
     * @param IBabsConnectorService $ibabsConnectorService iBabs connector service.
     * @param OrObjectService       $orObjectService       OR object service for sync records.
     * @param LoggerInterface       $logger                Logger for error handling.
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IBabsConnectorService $ibabsConnectorService,
        private readonly OrObjectService $orObjectService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->setInterval(seconds: self::DEFAULT_INTERVAL);

        // Not strictly time-sensitive; run when the system is available.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Only one poll instance at a time to avoid duplicate updates.
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Execute the RIS poll job.
     *
     * Loads all sync records with status "synced", groups them by risType,
     * and dispatches to the appropriate connector for besluit retrieval.
     *
     * @param mixed $argument Task arguments (not used in this implementation).
     *
     * @return void
     *
     * @psalm-param   mixed $argument
     * @phpstan-param mixed $argument
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
     */
    public function run(mixed $argument): void
    {
        $this->logger->info('RISPollJob: starting besluit poll');

        $syncRecords = $this->loadSyncedRecords();
        if (empty($syncRecords) === true) {
            $this->logger->info('RISPollJob: no synced records to poll');
            return;
        }

        $byRisType = $this->groupByRisType(records: $syncRecords);

        if (empty($byRisType['ibabs']) === false) {
            $this->pollIBabs(records: $byRisType['ibabs']);
        }

        if (empty($byRisType['notubiz']) === false) {
            $this->pollNotuBiz(records: $byRisType['notubiz']);
        }

        $this->logger->info(
            'RISPollJob: poll complete',
            [
                'ibabsCount'   => count($byRisType['ibabs']),
                'notubizCount' => count($byRisType['notubiz']),
            ]
        );

    }//end run()

    /**
     * Load all sync records with status "synced" from OpenRegister.
     *
     * @return array<int, array<string, mixed>> List of sync records.
     */
    private function loadSyncedRecords(): array
    {
        try {
            $result = $this->orObjectService->findAll(
                config: [
                    'filters' => [
                        'register' => 'openconnector',
                        'schema'   => 'ris_sync_record',
                        'status'   => 'synced',
                    ],
                ]
            );

            $entities = $result['results'] ?? $result;
            $records  = [];
            foreach ($entities as $entity) {
                if (method_exists($entity, 'getObject') === true) {
                    $records[] = $entity->getObject();
                }
            }

            return $records;
        } catch (\Exception $e) {
            $this->logger->error('RISPollJob: failed to load sync records', ['exception' => $e->getMessage()]);
            return [];
        }//end try

    }//end loadSyncedRecords()

    /**
     * Group sync records by their risType (ibabs / notubiz).
     *
     * @param array<int, array<string, mixed>> $records All synced records.
     *
     * @return array{ibabs: array<int, array<string, mixed>>, notubiz: array<int, array<string, mixed>>} Grouped records.
     */
    private function groupByRisType(array $records): array
    {
        $groups = ['ibabs' => [], 'notubiz' => []];
        foreach ($records as $record) {
            $risType = strtolower($record['risType'] ?? '');
            if (isset($groups[$risType]) === true) {
                $groups[$risType][] = $record;
            }
        }

        return $groups;

    }//end groupByRisType()

    /**
     * Poll iBabs sources for besluit updates on the given sync records.
     *
     * @param array<int, array<string, mixed>> $records Sync records with risType "ibabs".
     *
     * @return void
     */
    private function pollIBabs(array $records): void
    {
        $sourceGroups = $this->groupBySourceId(records: $records);

        foreach ($sourceGroups as $sourceId => $sourceRecords) {
            $source = $this->loadSource(sourceId: (string) $sourceId);
            if ($source === null) {
                $this->logger->warning('RISPollJob: iBabs source not found', ['sourceId' => $sourceId]);
                continue;
            }

            try {
                $besluiten = $this->ibabsConnectorService->pollBesluiten(
                    source: $source,
                    syncedItems: $sourceRecords
                );

                foreach ($besluiten as $besluit) {
                    $this->logger->info(
                        'RISPollJob: iBabs besluit received',
                        ['zaakId' => $besluit['zaakId'], 'status' => $besluit['zaakStatus']]
                    );
                }
            } catch (\Exception $e) {
                $this->logger->error(
                    'RISPollJob: iBabs poll error',
                    ['sourceId' => $sourceId, 'exception' => $e->getMessage()]
                );
            }//end try
        }//end foreach

    }//end pollIBabs()

    /**
     * Poll NotuBiz sources for besluit updates on the given sync records.
     *
     * @param array<int, array<string, mixed>> $records Sync records with risType "notubiz".
     *
     * @return void
     */
    private function pollNotuBiz(array $records): void
    {
        $this->logger->info(
            'RISPollJob: NotuBiz poll — besluit retrieval not yet implemented for NotuBiz',
            ['count' => count($records)]
        );

    }//end pollNotuBiz()

    /**
     * Group sync records by their source ID.
     *
     * @param array<int, array<string, mixed>> $records Sync records to group.
     *
     * @return array<string, array<int, array<string, mixed>>> Records keyed by source ID.
     */
    private function groupBySourceId(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            $sourceId = $record['sourceId'] ?? 'default';
            if (isset($groups[$sourceId]) === false) {
                $groups[$sourceId] = [];
            }

            $groups[$sourceId][] = $record;
        }

        return $groups;

    }//end groupBySourceId()

    /**
     * Load a source ObjectEntity from OpenRegister by ID.
     *
     * @param string $sourceId The source UUID or slug.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The source, or null if not found.
     */
    private function loadSource(string $sourceId): ?\OCA\OpenRegister\Db\ObjectEntity
    {
        try {
            return $this->orObjectService->find(id: $sourceId);
        } catch (\Exception $e) {
            $this->logger->warning('RISPollJob: loadSource failed', ['id' => $sourceId, 'e' => $e->getMessage()]);
            return null;
        }//end try

    }//end loadSource()
}//end class
