<?php
/**
 * Rename SynchronizationContract source fields to origin.
 *
 * This migration changes the following:
 * - Renaming of SynchronizationContract sourceId & sourceHash to originId and originHash,
 *   creating the new columns and transferring old data to the new fields.
 * - Removal of old indexes related to sourceId and sourceHash.
 * - Addition of new indexes for originId and synchronization_id fields.
 *
 * @category Migration
 * @package  OCA\OpenConnector\Migration
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

namespace OCA\OpenConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\IDBConnection;

/**
 * Renames SynchronizationContract source fields to origin and migrates the data.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20241111144800 extends SimpleMigrationStep
{

    /**
     * Database connection used to copy data between the legacy and renamed columns.
     *
     * @var IDBConnection
     */
    private IDBConnection $connection;

    /**
     * Constructor.
     *
     * @param IDBConnection $connection Database connection injected by the Nextcloud DI container.
     */
    public function __construct(IDBConnection $connection)
    {
        $this->connection = $connection;

    }//end __construct()

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
     * Add the renamed columns and adjust related indexes.
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
        $table  = $schema->getTable('openconnector_synchronization_contracts');

        // Step 1: Add new columns for 'origin_id' and 'origin_hash'.
        if ($table->hasColumn('origin_id') === false) {
            $table->addColumn(
                'origin_id',
                Types::STRING,
                [
                    'length'  => 255,
                    'notnull' => true,
                ]
            );
        }

        if ($table->hasColumn('origin_hash') === false) {
            $table->addColumn(
                'origin_hash',
                Types::STRING,
                [
                    'length'  => 255,
                    'notnull' => false,
                ]
            );
        }

        // Step 4: Adjust indexes in preparation for data migration.
        if ($table->hasIndex('openconnector_sync_contracts_source_id_index') === true) {
            $table->dropIndex('openconnector_sync_contracts_source_id_index');
        }

        if ($table->hasIndex('openconnector_sync_contracts_origin_id_index') === false) {
            $table->addIndex(['origin_id'], 'openconnector_sync_contracts_origin_id_index');
        }

        if ($table->hasIndex('openconnector_sync_contracts_sync_source_index') === true) {
            $table->dropIndex('openconnector_sync_contracts_sync_source_index');
        }

        if ($table->hasIndex('openconnector_sync_contracts_sync_origin_index') === false) {
            $table->addIndex(['synchronization_id', 'origin_id'], 'openconnector_sync_contracts_sync_origin_index');
        }

        return $schema;

    }//end changeSchema()

    /**
     * Copy data from the legacy source columns into the new origin columns and drop the originals.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();
        $table  = $schema->getTable('openconnector_synchronization_contracts');

        // Step 2: Copy data from old columns to new columns.
        if ($table->hasColumn('origin_id') === true && $table->hasColumn('origin_hash') === true
            && $table->hasColumn('source_id') === true && $table->hasColumn('source_hash') === true
        ) {
            $this->connection->executeQuery(
                "
				UPDATE oc_openconnector_synchronization_contracts
				SET origin_id = source_id, origin_hash = source_hash
				WHERE source_id IS NOT NULL
			"
            );
        }

        if ($table->hasColumn('source_id') === true) {
            $table->dropColumn('source_id');
        }

        if ($table->hasColumn('source_hash') === true) {
            $table->dropColumn('source_hash');
        }

    }//end postSchemaChange()
}//end class
