<?php
/**
 * Re-add consumer authorization_configuration and add endpoint conditions.
 *
 * Recreates the consumers.authorization_configuration column as JSON, adds the
 * consumers.user_id column, and adds the endpoints.conditions JSON column.
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
 * Re-adds consumer authorization_configuration / user_id and endpoint conditions.
 */
class Version1Date20241218122932 extends SimpleMigrationStep
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
     * Re-adds the typed consumer and endpoint configuration columns.
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

        if ($schema->hasTable(tableName: 'openconnector_consumers') === true) {
            $table = $schema->getTable(tableName: 'openconnector_consumers');
            $table->addColumn('authorization_configuration', Types::JSON);
            $table->addColumn('user_id', Types::STRING)->setNotnull(false);
        }

        if ($schema->hasTable(tableName: 'openconnector_endpoints') === true) {
            $table = $schema->getTable(tableName: 'openconnector_endpoints');
            $table->addColumn('conditions', Types::JSON);
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
