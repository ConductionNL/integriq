<?php

declare(strict_types=1);

/**
 * JobLogMessageColumnMigration
 *
 * Migration step to increase the message column size in the openconnector_job_logs table
 * to handle longer job execution messages and prevent truncation errors.
 *
 * @category Migration
 * @package  OCA\OpenConnector\Migration
 * @author   OpenConnector Development Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/openconnector
 * @version  1.0.0
 */

namespace OCA\OpenConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration step to increase the message column size in job logs table
 *
 * This migration addresses the issue where job execution messages longer than
 * 255 characters were causing database truncation errors. The message column
 * is changed from VARCHAR(255) to TEXT to accommodate longer messages.
 *
 * @psalm-api
 * @package OCA\OpenConnector\Migration
 * @category Migration
 * @author OpenConnector Team
 * @copyright 2025 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class Version1Date20250826103500 extends SimpleMigrationStep
{
    /**
     * Pre-schema change callback
     *
     * @param IOutput $output Migration output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array<string, mixed> $options Migration options
     *
     * @return void
     *
     * @psalm-param IOutput $output
     * @psalm-param Closure(): ISchemaWrapper $schemaClosure
     * @psalm-param array<string, mixed> $options
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No pre-schema changes needed
    }

    /**
     * Main schema change callback
     *
     * This method modifies the message column in the openconnector_job_logs table
     * to use the TEXT type instead of VARCHAR(255), allowing for much longer
     * job execution messages without truncation.
     *
     * @param IOutput $output Migration output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array<string, mixed> $options Migration options
     *
     * @return ISchemaWrapper|null The modified schema wrapper
     *
     * @psalm-param IOutput $output
     * @psalm-param Closure(): ISchemaWrapper $schemaClosure
     * @psalm-param array<string, mixed> $options
     * @psalm-return ISchemaWrapper|null
     * @phpstan-param IOutput $output
     * @phpstan-param Closure(): ISchemaWrapper $schemaClosure
     * @phpstan-param array<string, mixed> $options
     * @phpstan-return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /**
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Check if the job_logs table exists
        if (!$schema->hasTable('openconnector_job_logs')) {
            $output->warning('openconnector_job_logs table not found');
            return $schema;
        }

        $table = $schema->getTable('openconnector_job_logs');

        // Check if the message column exists
        if (!$table->hasColumn('message')) {
            $output->warning('Message column not found in openconnector_job_logs table');
            return $schema;
        }

        // Change the column to TEXT type to allow longer messages
        // In Nextcloud migrations, we use changeColumn to modify existing columns
        $table->changeColumn('message', [
            'type' => \Doctrine\DBAL\Types\Type::getType(Types::TEXT),
            'notnull' => true,
        ]);

        $output->info('Updated message column in openconnector_job_logs table to TEXT type');

        return $schema;
    }

    /**
     * Post-schema change callback
     *
     * @param IOutput $output Migration output interface
     * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
     * @param array<string, mixed> $options Migration options
     *
     * @return void
     *
     * @psalm-param IOutput $output
     * @psalm-param Closure(): ISchemaWrapper $schemaClosure
     * @psalm-param array<string, mixed> $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No post-schema changes needed
        $output->info('Job logs message column migration completed successfully');
    }
}
