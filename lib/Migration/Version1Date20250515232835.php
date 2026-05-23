<?php
/**
 * Add configurations and slug columns to all main tables.
 *
 * Adds the configurations JSON column, the slug string column (plus index and
 * unique constraint) and a status column on jobs and synchronizations. The
 * post-schema step backfills slugs from the existing name columns.
 *
 * @category Migration
 * @package  OCA\OpenConnector\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds configurations / slug / status columns and backfills slug values.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20250515232835 extends SimpleMigrationStep
{
    /**
     * Pre-schema change callback.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }//end preSchemaChange()

    /**
     * Adds configurations / slug / status columns to all related tables.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return ISchemaWrapper|null The modified schema wrapper.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // List of tables that need the new columns.
        $tables = [
            'openconnector_sources',
            'openconnector_endpoints',
            'openconnector_mappings',
            'openconnector_rules',
            'openconnector_jobs',
            'openconnector_synchronizations',
        ];

        // Add configurations and slug columns to each table.
        foreach ($tables as $tableName) {
            if ($schema->hasTable($tableName) === true) {
                $table = $schema->getTable($tableName);

                // Add configurations column if it doesn't exist.
                if ($table->hasColumn('configurations') === false) {
                    $table->addColumn('configurations', Types::JSON)
                        ->setNotnull(false)
                        ->setDefault('[]');
                }

                // Add slug column if it doesn't exist.
                if ($table->hasColumn('slug') === false) {
                    $table->addColumn(
                        'slug',
                        Types::STRING,
                        [
                            'length'  => 255,
                            'notnull' => false,
                            'default' => null,
                        ]
                    );

                    // Add index for the slug column.
                    $table->addIndex(['slug'], 'idx_'.$tableName.'_slug');
                    $table->addUniqueConstraint(['slug'], 'idx_'.$tableName.'_slug_unique');
                }
            }//end if
        }//end foreach

        // Add status column to synchronizations table if it doesn't exist.
        if ($schema->hasTable('openconnector_synchronizations') === true) {
            $table = $schema->getTable('openconnector_synchronizations');
            if ($table->hasColumn('status') === false) {
                $table->addColumn(
                    'status',
                    Types::STRING,
                    [
                        'length'  => 255,
                        'notnull' => false,
                        'default' => null,
                    ]
                );
            }
        }

        // Add status column to jobs table if it doesn't exist.
        if ($schema->hasTable('openconnector_jobs') === true) {
            $table = $schema->getTable('openconnector_jobs');
            if ($table->hasColumn('status') === false) {
                $table->addColumn(
                    'status',
                    Types::STRING,
                    [
                        'length'  => 255,
                        'notnull' => false,
                        'default' => null,
                    ]
                );
            }
        }

        return $schema;

    }//end changeSchema()

    /**
     * Backfills slug values for the migrated tables.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // Get the database connection.
        $connection = \OC::$server->get(\OCP\IDBConnection::class);

        // List of tables that need slug updates.
        $tables = [
            'openconnector_sources'          => 'name',
            'openconnector_endpoints'        => 'name',
            'openconnector_mappings'         => 'name',
            'openconnector_rules'            => 'name',
            'openconnector_jobs'             => 'name',
            'openconnector_synchronizations' => 'name',
        ];

        // Update slugs for each table.
        foreach ($tables as $tableName => $nameColumn) {
            // First, update any null or empty slugs using the name column.
            $query = $connection->getQueryBuilder();
            $query->update($tableName)
                ->set('slug', $query->createFunction('LOWER(REPLACE(REPLACE(REPLACE('.$nameColumn.', \' \', \'-\'), \'.\', \'-\'), \'_\', \'-\'))'))
                ->where(
                    $query->expr()->orX(
                        $query->expr()->isNull('slug'),
                        $query->expr()->eq('slug', $query->createNamedParameter(''))
                    )
                );
            $query->execute();

            // Then, ensure uniqueness across all tables.
            $query = $connection->getQueryBuilder();
            $query->select('id', 'slug')
                ->from($tableName)
                ->orderBy('id', 'ASC');
            $result  = $query->execute();
            $slugs   = [];
            $updates = [];

            while (($row = $result->fetch()) !== false) {
                $originalSlug = $row['slug'];
                $newSlug      = $originalSlug;
                $counter      = 1;

                // If slug is empty or null, use a default.
                if (empty($originalSlug) === true) {
                    $newSlug = 'item-'.$row['id'];
                }

                // Handle duplicate slugs.
                if (empty($originalSlug) === false) {
                    while (isset($slugs[$newSlug]) === true) {
                        $newSlug = $originalSlug.'-'.$counter;
                        $counter++;
                    }
                }

                if ($newSlug !== $originalSlug) {
                    $updates[] = [
                        'id'   => $row['id'],
                        'slug' => $newSlug,
                    ];
                }

                $slugs[$newSlug] = true;
            }//end while

            $result->closeCursor();

            // Apply the updates.
            foreach ($updates as $update) {
                $query = $connection->getQueryBuilder();
                $query->update($tableName)
                    ->set('slug', $query->createNamedParameter($update['slug']))
                    ->where($query->expr()->eq('id', $query->createNamedParameter($update['id'])));
                $query->execute();
            }

            $output->info("Updated slugs for table: ".$tableName);
        }//end foreach

    }//end postSchemaChange()
}//end class
