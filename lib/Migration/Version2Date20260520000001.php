<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain B (openconnector-register-storage) — main migration entrypoint.
 *
 * Calls ConfigurationService::importFromApp() to materialise the openconnector
 * register + 15 schemas (declared by chain A's lib/Settings/openconnector_register.json),
 * then runs LegacyToRegisterMigrator::migrateAll() to copy every row out of the
 * 15 legacy oc_openconnector_* tables into OR-backed oc_openregister_objects.
 *
 * Idempotent: re-running has no effect after `openconnector.storage_migrated` is set.
 *
 * Cross-ref: openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md REQ-001/005, local ADR-012 (strangler-fig pattern).
 */

namespace OCA\OpenConnector\Migration;

use Closure;
use OCA\OpenConnector\Service\Migration\LegacyToRegisterMigrator;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IMigrationStep;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;

class Version2Date20260520000001 implements IMigrationStep
{

    public function __construct(
        private readonly IDBConnection $db,
        private readonly IAppConfig $appConfig,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        private readonly ConfigurationService $configurationService,
        private readonly LegacyToRegisterMigrator $migrator
    ) {

    }


    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No-op. All work happens in postSchemaChange so OR's schemas exist
        // before we attempt to import the descriptor.
    }


    /**
     * No schema diff for chain B — the OR tables already exist from the
     * openregister app's own migrations. We only IMPORT the descriptor and
     * COPY legacy rows.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;
    }


    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        if ($this->appConfig->getAppValueString('storage_migrated', '') === 'true') {
            $output->info('chain-B: storage_migrated=true already set — skipping (idempotent).');
            return;
        }

        $descriptorPath = __DIR__ . '/../Settings/openconnector_register.json';
        $output->info(sprintf('chain-B: importing register descriptor from %s', $descriptorPath));

        $descriptor = json_decode((string) file_get_contents($descriptorPath), true, flags: JSON_THROW_ON_ERROR);
        $appVersion = (string) $this->appConfig->getAppValueString('installed_version', '1.0.0');

        $this->configurationService->importFromApp(
            appId: 'openconnector',
            data: $descriptor,
            version: $appVersion
        );
        $output->info('chain-B: register descriptor imported (idempotent — existing schemas reused).');

        $output->info('chain-B: starting legacy → OR row migration via LegacyToRegisterMigrator::migrateAll()');
        $result = $this->migrator->migrateAll(
            dryRun: false,
            entitySlug: null,
            batchSize: 10000
        );

        $allOk = true;
        foreach ($result as $perEntity) {
            $skipped = (int) ($perEntity['skipped'] ?? 0);
            $output->info(sprintf(
                '  %s: legacy=%d migrated=%d skipped=%d fkRewrites=%d (%dms)',
                $perEntity['slug'] ?? '?',
                (int) ($perEntity['legacyCount'] ?? 0),
                (int) ($perEntity['migratedCount'] ?? 0),
                $skipped,
                (int) ($perEntity['fkRewrites'] ?? 0),
                (int) ($perEntity['elapsedMs'] ?? 0)
            ));
            if ($skipped > 0 || !empty($perEntity['error'])) {
                $allOk = false;
            }
        }

        if ($allOk === true) {
            $this->appConfig->setAppValueString('storage_migrated', 'true');
            $output->info('chain-B: storage_migrated=true — all 15 entities copied successfully.');
        } else {
            $output->warning('chain-B: storage_migrated flag NOT set — at least one entity reported skips or errors. Use occ openconnector:migrate-storage to retry per-entity.');
        }
    }


}
