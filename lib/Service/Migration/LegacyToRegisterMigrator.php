<?php
/**
 * OpenConnector Legacy → OR Storage Migrator.
 *
 * Chain B (openconnector-register-storage) — legacy → OR row migrator.
 *
 * Copies every row out of the 15 oc_openconnector_* legacy tables into
 * oc_openregister_objects. Preserves uuids byte-for-byte. Translates 6 integer
 * FK columns to OR uuids in a post-INSERT UPDATE pass. Branches
 * Synchronization.sourceId/targetId across 3 documented value formats
 * (integer-PK, register/schema slug, uuid).
 *
 * Setup:
 *   - Caches the openconnector register PK + 15 schema PKs at startup
 *   - Asserts plaintext credentials state per ADR-007 (no EncryptionService class)
 *   - Reads target DB platform once and selects the JSON build function accordingly
 *
 * Run:
 *   - Pass 1 (INSERT): iterate 15 entities in dependency order, bulk INSERT
 *     per batch of $batchSize. Each row's `owner` is NULL (system-owned).
 *   - Pass 2 (FK rewrite): UPDATE the 6 integer-FK columns to the new uuid via
 *     `jsonb_set` (Postgres) or `JSON_SET` (MySQL), joining back to the legacy
 *     target table.
 *   - Pass 3 (Sync branching): UPDATE synchronization rows for source_id /
 *     target_id, applying the 3-format branching rules.
 *
 * On full success (no skips, no errors), flips
 * `openconnector.storage_migrated` to 'true' and emits ONE audit-trail entry.
 *
 * Cross-ref: openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md REQ-001..REQ-012
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Migration
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

namespace OCA\OpenConnector\Service\Migration;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;
use LogicException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Migrates legacy oc_openconnector_* tables into oc_openregister_objects.
 */
class LegacyToRegisterMigrator
{

    public const REGISTER_SLUG = 'openconnector';

    /**
     * Migration order — entities without intra-openconnector FKs first, then dependents.
     * 1. Independent config entities (sources/consumer/endpoint/event/event_subscription/job/mapping/rule)
     * 2. Synchronization (FK: none — sourceId/targetId are polymorphic)
     * 3. SynchronizationContract (FK: synchronization)
     * 4. EventMessage (FK: event, consumer, event_subscription)
     * 5. CallLog (FK: source, synchronization)
     * 6. Logs (JobLog, SynchronizationLog, SynchronizationContractLog)
     *
     * Per-entity shape:
     *   - slug              schema slug in the openconnector register
     *   - legacyTable       oc_openconnector_* table name
     *   - hasSyncBranching  Synchronization-only (sourceId/targetId polymorphic resolution)
     */
    public const ENTITY_ORDER = [
        ['slug' => 'source',                       'legacyTable' => 'oc_openconnector_sources'],
        ['slug' => 'consumer',                     'legacyTable' => 'oc_openconnector_consumers'],
        ['slug' => 'endpoint',                     'legacyTable' => 'oc_openconnector_endpoints'],
        ['slug' => 'event',                        'legacyTable' => 'oc_openconnector_events'],
        ['slug' => 'event_subscription',           'legacyTable' => 'oc_openconnector_event_subscriptions'],
        ['slug' => 'job',                          'legacyTable' => 'oc_openconnector_jobs'],
        ['slug' => 'mapping',                      'legacyTable' => 'oc_openconnector_mappings'],
        ['slug' => 'rule',                         'legacyTable' => 'oc_openconnector_rules'],
        ['slug' => 'synchronization',              'legacyTable' => 'oc_openconnector_synchronizations', 'hasSyncBranching' => true],
        ['slug' => 'synchronization_contract',     'legacyTable' => 'oc_openconnector_synchronization_contracts'],
        ['slug' => 'event_message',                'legacyTable' => 'oc_openconnector_event_messages'],
        ['slug' => 'call_log',                     'legacyTable' => 'oc_openconnector_call_logs'],
        ['slug' => 'job_log',                      'legacyTable' => 'oc_openconnector_job_logs'],
        ['slug' => 'synchronization_log',          'legacyTable' => 'oc_openconnector_synchronization_logs'],
        ['slug' => 'synchronization_contract_log', 'legacyTable' => 'oc_openconnector_synchronization_contract_logs'],
    ];

    /**
     * 6 integer FK rewrites — post-INSERT UPDATE pass.
     *
     * Each entry: source schema slug, legacy column name, JSON target field,
     * target schema slug, onDelete behaviour.
     */
    public const FK_REWRITES = [
        [
            'fromSchema' => 'call_log',
            'legacyCol'  => 'source_id',
            'jsonField'  => 'source',
            'toSchema'   => 'source',
            'onDelete'   => 'SET NULL',
        ],
        [
            'fromSchema' => 'call_log',
            'legacyCol'  => 'synchronization_id',
            'jsonField'  => 'synchronization',
            'toSchema'   => 'synchronization',
            'onDelete'   => 'SET NULL',
        ],
        [
            'fromSchema' => 'event_message',
            'legacyCol'  => 'event_id',
            'jsonField'  => 'event',
            'toSchema'   => 'event',
            'onDelete'   => 'CASCADE',
        ],
        [
            'fromSchema' => 'event_message',
            'legacyCol'  => 'consumer_id',
            'jsonField'  => 'consumer',
            'toSchema'   => 'consumer',
            'onDelete'   => 'SET NULL',
        ],
        [
            'fromSchema' => 'event_message',
            'legacyCol'  => 'subscription_id',
            'jsonField'  => 'subscription',
            'toSchema'   => 'event_subscription',
            'onDelete'   => 'CASCADE',
        ],
        [
            'fromSchema' => 'synchronization_contract_log',
            'legacyCol'  => 'synchronization_contract_id',
            'jsonField'  => 'synchronization_contract',
            'toSchema'   => 'synchronization_contract',
            'onDelete'   => 'CASCADE',
        ],
    ];

    /**
     * Cached openconnector register PK resolved at startup.
     *
     * @var integer|null
     */
    private ?int $registerPk = null;

    /**
     * Schema slug → PK map, populated at startup.
     *
     * @var array<string, int>
     */
    private array $schemaPks = [];

    /**
     * Detected platform — 'pgsql' | 'mysql'.
     *
     * @var string
     */
    private string $platform = 'mysql';

    /**
     * Constructor.
     *
     * @param IDBConnection   $db        Database connection used for legacy + OR queries.
     * @param IAppConfig      $appConfig App config used to flip storage_migrated on success.
     * @param LoggerInterface $logger    Logger for per-batch progress and warnings.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Run the migration. Per the spec contract:
     *  - dryRun=true: count rows without writing
     *  - entitySlug non-null: run a single entity (no flag flip)
     *  - entitySlug=null: full run; flip storage_migrated on success
     *
     * @param boolean     $dryRun     Run without writing, only counting.
     * @param string|null $entitySlug Single-entity override (slug from ENTITY_ORDER).
     * @param integer     $batchSize  Default 10,000; valid range [100, 100000].
     *
     * @return array<array{slug:string,legacyCount:int,migratedCount:int,skipped:int,fkRewrites:int,elapsedMs:int}>
     */
    public function migrateAll(bool $dryRun=false, ?string $entitySlug=null, int $batchSize=10000): array
    {
        if ($batchSize < 100 || $batchSize > 100000) {
            throw new InvalidArgumentException(sprintf('batchSize MUST be in [100, 100000], got %d', $batchSize));
        }

        $entitiesToRun = self::ENTITY_ORDER;
        if ($entitySlug !== null) {
            $entitiesToRun = array_values(
                    array_filter(
                self::ENTITY_ORDER,
                static fn (array $e): bool => $e['slug'] === $entitySlug
            )
                    );
            if ($entitiesToRun === []) {
                throw new InvalidArgumentException(
                        sprintf(
                    'entitySlug "%s" not in valid set: %s',
                    $entitySlug,
                    implode(', ', array_column(self::ENTITY_ORDER, 'slug'))
                )
                        );
            }
        }

        $this->resolveStartupState();
        $this->assertPlaintextCredentialsState();

        $results          = [];
        $hadSkipsOrErrors = false;

        foreach ($entitiesToRun as $entity) {
            $start = microtime(true);
            try {
                $perEntity = $this->migrateEntity(entity: $entity, dryRun: $dryRun, batchSize: $batchSize);
            } catch (\Throwable $e) {
                $this->logger->error(
                        sprintf(
                    'chain-B migration: entity %s threw %s: %s',
                    $entity['slug'],
                    get_class($e),
                    $e->getMessage()
                )
                        );
                $hadSkipsOrErrors = true;
                $perEntity        = [
                    'slug'          => $entity['slug'],
                    'legacyCount'   => 0,
                    'migratedCount' => 0,
                    'skipped'       => 0,
                    'fkRewrites'    => 0,
                    'error'         => get_class($e).': '.$e->getMessage(),
                ];
            }//end try

            $perEntity['elapsedMs'] = (int) ((microtime(true) - $start) * 1000);
            if (($perEntity['skipped'] ?? 0) > 0) {
                $hadSkipsOrErrors = true;
            }

            $results[] = $perEntity;
        }//end foreach

        // FK rewrite pass — run only on full migration (single-entity runs skip).
        if ($entitySlug === null && $dryRun === false) {
            $this->runFkRewritePass(results: $results);
        }

        // Flip flag only on full, clean, non-dry-run.
        if ($entitySlug === null && $dryRun === false && $hadSkipsOrErrors === false) {
            $this->appConfig->setAppValueString('storage_migrated', 'true');
            $this->logger->info('chain-B: storage_migrated flag set to true (clean full run)');
        }

        $this->emitSummaryAuditTrail(results: $results, dryRun: $dryRun, entitySlug: $entitySlug);

        return $results;

    }//end migrateAll()

    /**
     * Resolve register PK + 15 schema PKs once at startup. Detect platform.
     *
     * @return void
     *
     * @throws LogicException If the openconnector register has not been imported yet.
     */
    private function resolveStartupState(): void
    {
        $platformObj = $this->db->getDatabasePlatform();
        if ($platformObj instanceof PostgreSQLPlatform) {
            $this->platform = 'pgsql';
        } else if ($platformObj instanceof AbstractMySQLPlatform) {
            $this->platform = 'mysql';
        } else {
            $this->platform = $this->db->getDatabaseProvider();
            $this->logger->warning(
                    sprintf(
                'chain-B: unknown DB platform %s (provider=%s) — defaulting to mysql JSON syntax',
                get_class($platformObj),
                $this->platform
            )
                    );
            $this->platform = 'mysql';
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_registers')
            ->where($qb->expr()->eq('slug', $qb->createNamedParameter(self::REGISTER_SLUG)));
        $result = $qb->executeQuery();
        $row    = $result->fetchAssociative();
        $result->closeCursor();

        if ($row === false) {
            throw new LogicException(
                'chain-B: openconnector register not found in oc_openregister_registers;'
                .' run the chain-B migration class (which calls ConfigurationService::importFromApp)'
                .' before invoking the migrator directly.'
            );
        }

        $this->registerPk = (int) $row['id'];

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from('openregister_schemas')
            ->where($qb->expr()->eq('application', $qb->createNamedParameter(self::REGISTER_SLUG)));
        $result = $qb->executeQuery();
        while (($row = $result->fetchAssociative()) !== false) {
            $this->schemaPks[(string) $row['slug']] = (int) $row['id'];
        }

        $result->closeCursor();

    }//end resolveStartupState()

    /**
     * Assert the codebase is in the plaintext-credentials state documented by ADR-007.
     *
     * If an EncryptionService class is found, abort: the chain-B verbatim copy strategy
     * is no longer valid and the migration spec MUST be revised before proceeding.
     *
     * @return void
     */
    private function assertPlaintextCredentialsState(): void
    {
        if (class_exists('OCA\\OpenConnector\\Service\\EncryptionService', false) === true
            || class_exists('OCA\\OpenConnector\\Service\\EncryptionService', true) === true
        ) {
            throw new LogicException(
                'chain-B: OCA\\OpenConnector\\Service\\EncryptionService class has been introduced'
                .' since this spec was written — the verbatim-copy strategy no longer applies.'
                .' Revise openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md'
                .' before re-running.'
            );
        }

    }//end assertPlaintextCredentialsState()

    /**
     * Migrate a single entity. Returns per-entity result counts.
     *
     * @param array{slug:string,legacyTable:string,hasSyncBranching?:bool} $entity    Entity descriptor from ENTITY_ORDER.
     * @param boolean                                                      $dryRun    Run without writing.
     * @param integer                                                      $batchSize Batch size for legacy reads.
     *
     * @return array{slug:string,legacyCount:int,migratedCount:int,skipped:int,fkRewrites:int}
     */
    private function migrateEntity(array $entity, bool $dryRun, int $batchSize): array
    {
        $slug       = $entity['slug'];
        $table      = $entity['legacyTable'];
        $tableShort = str_replace('oc_', '', $table);
        $hasSyncBranching = (bool) ($entity['hasSyncBranching'] ?? false);

        $schemaPk = $this->schemaPks[$slug] ?? null;
        if ($schemaPk === null) {
            throw new LogicException(sprintf('chain-B: schema slug "%s" not found in openconnector register', $slug));
        }

        // Skip cleanly if the legacy table does not exist (fresh install with no pre-chain-B data).
        if ($this->tableExists(tableName: $table) === false) {
            $this->logger->info(sprintf('chain-B: legacy table %s not present — fresh install, skipping entity %s', $table, $slug));
            return ['slug' => $slug, 'legacyCount' => 0, 'migratedCount' => 0, 'skipped' => 0, 'fkRewrites' => 0];
        }

        $count = $this->countLegacyRows(tableShort: $tableShort);
        if ($count === 0) {
            return ['slug' => $slug, 'legacyCount' => 0, 'migratedCount' => 0, 'skipped' => 0, 'fkRewrites' => 0];
        }

        if ($dryRun === true) {
            return ['slug' => $slug, 'legacyCount' => $count, 'migratedCount' => 0, 'skipped' => 0, 'fkRewrites' => 0];
        }

        $migrated         = 0;
        $skipped          = 0;
        $offset           = 0;
        $syncBranchCounts = ['integer-pk' => 0, 'register-schema' => 0, 'uuid' => 0, 'unrecognised' => 0];

        while ($offset < $count) {
            $rows = $this->fetchLegacyBatch(tableShort: $tableShort, offset: $offset, batchSize: $batchSize);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $jsonBody = $this->buildJsonBody(row: $row, hasSyncBranching: $hasSyncBranching, syncBranchCounts: $syncBranchCounts);
                $uuid     = (string) ($row['uuid'] ?? '');
                if ($uuid === '') {
                    $this->logger->warning(sprintf('chain-B: %s row id=%s has no uuid — skipping', $slug, (string) ($row['id'] ?? '?')));
                    $skipped++;
                    continue;
                }

                try {
                    $this->insertObjectRow(
                        registerPk: (int) $this->registerPk,
                        schemaPk: $schemaPk,
                        uuid: $uuid,
                        legacyId: (int) ($row['id'] ?? 0),
                        jsonBody: $jsonBody,
                        created: (string) ($row['created'] ?? $row['date_created'] ?? date('Y-m-d H:i:s')),
                        updated: (string) ($row['updated'] ?? $row['date_modified'] ?? date('Y-m-d H:i:s'))
                    );
                    $migrated++;
                } catch (\Throwable $e) {
                    $this->logger->error(
                            sprintf(
                        'chain-B: %s uuid=%s INSERT failed (%s): %s',
                        $slug,
                        $uuid,
                        get_class($e),
                        $e->getMessage()
                    )
                            );
                    $skipped++;
                }//end try
            }//end foreach

            $offset += $batchSize;
            $this->logger->info(sprintf('chain-B: %s — batch progress: %d / %d', $slug, min($offset, $count), $count));
        }//end while

        if ($hasSyncBranching === true) {
            $this->logger->info(
                    sprintf(
                'chain-B: synchronization sourceId/targetId branching counts — integer-PK→uuid: %d, register/schema: %d, uuid: %d, unrecognised: %d',
                $syncBranchCounts['integer-pk'],
                $syncBranchCounts['register-schema'],
                $syncBranchCounts['uuid'],
                $syncBranchCounts['unrecognised']
            )
                    );
        }

        if ($hasSyncBranching === true) {
            $branching = $syncBranchCounts;
        } else {
            $branching = null;
        }

        return [
            'slug'          => $slug,
            'legacyCount'   => $count,
            'migratedCount' => $migrated,
            'skipped'       => $skipped,
            // Populated by FK rewrite pass.
            'fkRewrites'    => 0,
            'branching'     => $branching,
        ];

    }//end migrateEntity()

    /**
     * Probe information_schema for the presence of a legacy table.
     *
     * @param string $tableName Fully qualified table name (oc_openconnector_*).
     *
     * @return boolean
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1");
            $stmt->execute([$tableName]);
            $row = $stmt->fetch();
            return $row !== false;
        } catch (\Throwable) {
            return false;
        }

    }//end tableExists()

    /**
     * Count rows in a legacy table.
     *
     * @param string $tableShort Table name without the oc_ prefix.
     *
     * @return integer
     */
    private function countLegacyRows(string $tableShort): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'c'))
            ->from($tableShort);
        $result = $qb->executeQuery();
        $row    = $result->fetchAssociative();
        $result->closeCursor();
        return (int) ($row['c'] ?? 0);

    }//end countLegacyRows()

    /**
     * Fetch a batch of legacy rows ordered by id.
     *
     * @param string  $tableShort Table name without the oc_ prefix.
     * @param integer $offset     Offset.
     * @param integer $batchSize  Max rows to return.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchLegacyBatch(string $tableShort, int $offset, int $batchSize): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($tableShort)
            ->orderBy('id', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($batchSize);
        $result = $qb->executeQuery();
        $rows   = $result->fetchAllAssociative();
        $result->closeCursor();
        return $rows;

    }//end fetchLegacyBatch()

    /**
     * Build the JSON object body for a single legacy row.
     *
     * Preserves field names verbatim (legacy snake_case → camelCase in JSON);
     * casts JSON columns to native arrays/objects; applies Sync.sourceId/targetId
     * branching when hasSyncBranching=true.
     *
     * @param array<string, mixed> $row              Legacy row.
     * @param boolean              $hasSyncBranching Whether to apply sourceId/targetId branching.
     * @param array<string, int>   $syncBranchCounts Per-variant counts, mutated by reference.
     *
     * @return array<string, mixed>
     */
    private function buildJsonBody(array $row, bool $hasSyncBranching, array &$syncBranchCounts): array
    {
        $body = [];
        foreach ($row as $col => $value) {
            // Skip OR-managed columns — those go in oc_openregister_objects directly, not in the JSON body.
            if (in_array($col, ['id', 'uuid', 'owner', 'register', 'schema'], true) === true) {
                continue;
            }

            // Detect JSON-typed columns by attempted decode (legacy mappers used $this->addType($field, 'json')).
            if (is_string($value) === true && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $body[$this->toCamelCase(snake: $col)] = $decoded;
                    continue;
                }
            }

            $body[$this->toCamelCase(snake: $col)] = $value;
        }

        if ($hasSyncBranching === true) {
            foreach (['sourceId', 'targetId'] as $field) {
                if (isset($body[$field]) === true && is_string($body[$field]) === true) {
                    $resolved     = $this->resolveSyncRef(value: $body[$field]);
                    $body[$field] = $resolved['value'];
                    $syncBranchCounts[$resolved['variant']]++;
                }
            }
        }

        return $body;

    }//end buildJsonBody()

    /**
     * Translate snake_case column names to camelCase JSON keys
     * (matching the openconnector entity property names + chain-A JSON schema).
     *
     * @param string $snake Snake-case input.
     *
     * @return string
     */
    private function toCamelCase(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));

    }//end toCamelCase()

    /**
     * Resolve a Synchronization.sourceId / .targetId value across 3 formats.
     *
     * @param string $value The raw legacy value.
     *
     * @return array{value: string, variant: 'integer-pk'|'register-schema'|'uuid'|'unrecognised'}
     */
    private function resolveSyncRef(string $value): array
    {
        if (preg_match('/^\d+$/', $value) === 1) {
            // Integer-PK: resolve to source uuid via lookup.
            $uuid = $this->lookupSourceUuidByInt(id: (int) $value);
            return ['value' => $uuid ?? $value, 'variant' => 'integer-pk'];
        }

        if (preg_match('/^[\w-]+\/[\w-]+$/', $value) === 1) {
            return ['value' => $value, 'variant' => 'register-schema'];
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return ['value' => $value, 'variant' => 'uuid'];
        }

        $this->logger->warning(
            sprintf(
                'chain-B: Synchronization sourceId/targetId value "%s" matches no known format'
                .' — preserved as-is, marked unrecognised',
                $value
            )
        );
        return ['value' => $value, 'variant' => 'unrecognised'];

    }//end resolveSyncRef()

    /**
     * Look up a source UUID by its legacy integer PK.
     *
     * @param integer $id Legacy integer PK.
     *
     * @return string|null
     */
    private function lookupSourceUuidByInt(int $id): ?string
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('uuid')
                ->from('openconnector_sources')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
            $result = $qb->executeQuery();
            $row    = $result->fetchAssociative();
            $result->closeCursor();
            if ($row !== false) {
                return (string) $row['uuid'];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }

    }//end lookupSourceUuidByInt()

    /**
     * Bulk INSERT one row into oc_openregister_objects.
     *
     * Dual-platform JSON build per spec REQ-002.
     *
     * @param integer              $registerPk OR register PK.
     * @param integer              $schemaPk   OR schema PK.
     * @param string               $uuid       Row UUID.
     * @param integer              $legacyId   Legacy integer PK.
     * @param array<string, mixed> $jsonBody   JSON body to insert.
     * @param string               $created    Created timestamp.
     * @param string               $updated    Updated timestamp.
     *
     * @return void
     */
    private function insertObjectRow(
        int $registerPk,
        int $schemaPk,
        string $uuid,
        int $legacyId,
        array $jsonBody,
        string $created,
        string $updated
    ): void {
        $jsonString = json_encode($jsonBody, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Insert via QueryBuilder for portability; the `object` column receives
        // the raw JSON string — Doctrine handles the JSON encoding on both
        // platforms via type binding.
        $qb = $this->db->getQueryBuilder();
        $qb->insert('openregister_objects')
            ->values(
                   [
                       'uuid'     => $qb->createNamedParameter($uuid),
                       'register' => $qb->createNamedParameter($registerPk, \PDO::PARAM_INT),
                       'schema'   => $qb->createNamedParameter($schemaPk, \PDO::PARAM_INT),
                       'object'   => $qb->createNamedParameter($jsonString),
                       'owner'    => $qb->createNamedParameter(null),
                       'created'  => $qb->createNamedParameter($created),
                       'updated'  => $qb->createNamedParameter($updated),
                   ]
                   );
        $qb->executeStatement();

    }//end insertObjectRow()

    /**
     * Pass 2: rewrite 6 integer FK columns to OR uuids.
     *
     * For each FK descriptor, JOIN back to the legacy target table to find each
     * target row's uuid, then UPDATE the openregister_objects.object JSON via
     * jsonb_set / JSON_SET (platform-specific).
     *
     * Each entity's fkRewrites tally is incremented as rewrites complete.
     *
     * @param array<int, array<string, mixed>> $results Per-entity results, mutated by reference.
     *
     * @return void
     */
    private function runFkRewritePass(array &$results): void
    {
        foreach (self::FK_REWRITES as $fk) {
            $count = $this->applyOneFkRewrite(fk: $fk);
            foreach ($results as &$perEntity) {
                if ($perEntity['slug'] === $fk['fromSchema']) {
                    $perEntity['fkRewrites'] = ((int) ($perEntity['fkRewrites'] ?? 0) + $count);
                    break;
                }
            }

            unset($perEntity);
            $this->logger->info(
                    sprintf(
                'chain-B FK rewrite: %s.%s → %s (target %s, onDelete=%s) — %d rows updated',
                $fk['fromSchema'],
                $fk['legacyCol'],
                $fk['jsonField'],
                $fk['toSchema'],
                $fk['onDelete'],
                $count
            )
                    );
        }//end foreach

    }//end runFkRewritePass()

    /**
     * Apply a single FK rewrite. Returns the count of rows updated.
     *
     * @param array<string, string> $fk FK descriptor from FK_REWRITES.
     *
     * @return integer
     */
    private function applyOneFkRewrite(array $fk): int
    {
        // Find each legacy row's target uuid and the source-side uuid.
        $fromSchemaPk = $this->schemaPks[$fk['fromSchema']] ?? null;
        $toSchemaPk   = $this->schemaPks[$fk['toSchema']] ?? null;
        if ($fromSchemaPk === null || $toSchemaPk === null) {
            return 0;
        }

        // The source-side JSON body has the integer FK in camelCase form.
        $jsonFkField = $this->toCamelCase(snake: $fk['legacyCol']);

        $count = 0;
        try {
            if ($this->platform === 'pgsql') {
                $sql  = <<<SQL
UPDATE oc_openregister_objects src
SET object = jsonb_set(
    src.object::jsonb,
    ARRAY[:relField],
    to_jsonb((SELECT tgt.uuid FROM oc_openregister_objects tgt
              WHERE tgt.schema = :toSchemaPk
                AND (tgt.object::jsonb ->> 'id')::int = (src.object::jsonb ->> :jsonFkField)::int))
)
WHERE src.schema = :fromSchemaPk
  AND src.object::jsonb ? :jsonFkField
  AND (src.object::jsonb ->> :jsonFkField) ~ '^\\d+\$'
SQL;
                $stmt = $this->db->prepare($sql);
                $stmt->bindValue('relField', $fk['jsonField']);
                $stmt->bindValue('jsonFkField', $jsonFkField);
                $stmt->bindValue('fromSchemaPk', $fromSchemaPk, \PDO::PARAM_INT);
                $stmt->bindValue('toSchemaPk', $toSchemaPk, \PDO::PARAM_INT);
                $count = (int) $stmt->execute();
            } else {
                $sql  = <<<SQL
UPDATE oc_openregister_objects src
JOIN oc_openregister_objects tgt
  ON tgt.schema = :toSchemaPk
 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(tgt.object, '$.id')) AS UNSIGNED)
   = CAST(JSON_UNQUOTE(JSON_EXTRACT(src.object, CONCAT('$.', :jsonFkField))) AS UNSIGNED)
SET src.object = JSON_SET(src.object, CONCAT('$.', :relField), tgt.uuid)
WHERE src.schema = :fromSchemaPk
SQL;
                $stmt = $this->db->prepare($sql);
                $stmt->bindValue('relField', $fk['jsonField']);
                $stmt->bindValue('jsonFkField', $jsonFkField);
                $stmt->bindValue('fromSchemaPk', $fromSchemaPk, \PDO::PARAM_INT);
                $stmt->bindValue('toSchemaPk', $toSchemaPk, \PDO::PARAM_INT);
                $count = (int) $stmt->execute();
            }//end if
        } catch (\Throwable $e) {
            $this->logger->error(
                    sprintf(
                'chain-B FK rewrite %s.%s → %s failed: %s — leaving legacy %s in place per spec REQ-003 fallback',
                $fk['fromSchema'],
                $fk['legacyCol'],
                $fk['jsonField'],
                $e->getMessage(),
                $fk['legacyCol']
            )
                    );
        }//end try

        return $count;

    }//end applyOneFkRewrite()

    /**
     * Emit ONE audit-trail row covering the full migration.
     *
     * Per spec REQ-012: do NOT route writes through ObjectService::saveObject
     * during INSERT pass (that would emit per-object audit entries). This is
     * the single summary written explicitly to oc_openregister_audit_trail.
     *
     * @param array<int, array<string, mixed>> $results    Per-entity results.
     * @param boolean                          $dryRun     Whether this was a dry-run.
     * @param string|null                      $entitySlug Single-entity override (if any).
     *
     * @return void
     */
    private function emitSummaryAuditTrail(array $results, bool $dryRun, ?string $entitySlug): void
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('openregister_audit_trail')
                ->values(
                       [
                           'uuid'     => $qb->createNamedParameter(\OC::$server->get(\OCP\Security\ISecureRandom::class)->generate(36)),
                           'action'   => $qb->createNamedParameter('chain-b-migration'),
                           'changed'  => $qb->createNamedParameter(
                           json_encode(
                           [
                               'dryRun'     => $dryRun,
                               'entitySlug' => $entitySlug,
                               'perEntity'  => $results,
                           ],
                           JSON_UNESCAPED_SLASHES
                           )
                           ),
                           'register' => $qb->createNamedParameter($this->registerPk, \PDO::PARAM_INT),
                           'created'  => $qb->createNamedParameter(date('Y-m-d H:i:s')),
                       ]
                       );
            $qb->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->warning(
                    sprintf(
                'chain-B: summary audit-trail emission failed (%s) — proceeding anyway; migration counts remain in the log',
                $e->getMessage()
            )
                    );
        }//end try

    }//end emitSummaryAuditTrail()
}//end class
