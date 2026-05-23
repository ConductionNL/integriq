<?php
/**
 * Add reference columns to Jobs and Synchronizations.
 *
 * This migration changes the following:
 * - Adding 1 new column for the table Consumers: reference.
 * - Adding 1 new column for the table Jobs: reference.
 * - Adding 1 new column for the table Synchronizations: reference.
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

/**
 * Adds reference string columns to the jobs and synchronizations tables.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20250107163601 extends SimpleMigrationStep
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
     * Adds the reference columns to the jobs and synchronizations tables.
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

        if ($schema->hasTable(tableName: 'openconnector_jobs') === true) {
            $table = $schema->getTable(tableName: 'openconnector_jobs');
            $table->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 255]);
        }

        if ($schema->hasTable(tableName: 'openconnector_synchronizations') === true) {
            $table = $schema->getTable(tableName: 'openconnector_synchronizations');
            $table->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 255]);
        }

        return $schema;

    }//end changeSchema()

    /**
     * Post-schema change callback.
     *
     * @param IOutput                   $output        Migration output interface.
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
     * @param array<string, mixed>      $options       Migration options.
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
    }//end postSchemaChange()
}//end class
