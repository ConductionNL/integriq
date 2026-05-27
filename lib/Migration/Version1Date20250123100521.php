<?php
/**
 * Add actions JSON column to Synchronizations.
 *
 * Adds the actions column to the openconnector_synchronizations table.
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
 * Adds the actions JSON column to the synchronizations table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20250123100521 extends SimpleMigrationStep
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
     * Adds the actions column to the synchronizations table when missing.
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

        if ($schema->hasTable(tableName: 'openconnector_synchronizations') === true) {
            $table = $schema->getTable(tableName: 'openconnector_synchronizations');
            if ($table->hasColumn('actions') === false) {
                $table->addColumn(name: 'actions', typeName: Types::JSON)->setNotnull(false)->setDefault('[]');
            }
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
