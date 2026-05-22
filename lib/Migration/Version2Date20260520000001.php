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
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

class Version2Date20260520000001 extends SimpleMigrationStep
{

    // Nextcloud's MigrationService::createInstance() uses `new $class()` —
    // migrations cannot have constructor parameters. All dependencies are
    // resolved via the service container inside postSchemaChange().
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // No-op. All work happens in postSchemaChange so OR's schemas exist
        // before we attempt to import the descriptor.
    }//end preSchemaChange()

    /**
     * No schema diff for chain B — the OR tables already exist from the
     * openregister app's own migrations. We only IMPORT the descriptor and
     * COPY legacy rows.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;
    }//end changeSchema()

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $container = \OC::$server;
        $appConfig = $container->get(IAppConfig::class);
        $logger    = $container->get(LoggerInterface::class);

        if ($appConfig->getValueString('openconnector', 'storage_migrated', '') === 'true') {
            $output->info('chain-B: storage_migrated=true already set — skipping (idempotent).');
            return;
        }

        // `occ app:enable openconnector` runs migrations with openconnector's
        // PSR-4 paths loaded but NOT openregister's, so the class_exists
        // probe below would return false even when OR is enabled and
        // upgraded. We tried doing the load via `Application::register()` —
        // got a recursive DI loop (b421b9c8). We tried `IAppManager::loadApp`
        // — silently no-ops on fresh CI `occ app:enable` (the target app's
        // migrations run before NC's per-command app-loading walk fires).
        //
        // Final approach: require OR's composer autoload directly. That
        // registers OR's PSR-4 paths unconditionally, idempotently, and
        // independent of NC's per-command app-loading order. Scoped to the
        // migration step only (no side effects on web requests).
        $appManager = $container->get(\OCP\App\IAppManager::class);
        try {
            $orAppPath  = $appManager->getAppPath('openregister');
            $orAutoload = $orAppPath.'/vendor/autoload.php';
            if (file_exists($orAutoload)) {
                include_once $orAutoload;
                $output->info('chain-B: required openregister autoload from '.$orAutoload);
            } else {
                $output->warning('chain-B: openregister vendor/autoload.php not found at '.$orAutoload.' — register import will skip');
            }
        } catch (\Throwable $e) {
            $output->warning('chain-B: could not resolve openregister app path ('.$e->getMessage().') — register import will skip');
            $logger->warning('chain-B: getAppPath(openregister) failed: '.$e->getMessage(), ['exception' => $e]);
        }

        // Belt-and-braces: ALSO call loadApp so OR's Application::register/boot
        // fire (some services may rely on `register()` side effects, not just
        // the autoloader). Same try/catch as before.
        try {
            $appManager->loadApp('openregister');
        } catch (\Throwable $e) {
            $logger->info('chain-B: loadApp(openregister) skipped: '.$e->getMessage());
        }

        // Guard against OpenRegister being unavailable (would crash the
        // upgrade with a useless DI error). Defer to next upgrade.
        if (class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService') === false) {
            $output->warning('chain-B: openregister app not enabled or not loaded; skipping descriptor import + migration. Re-run `occ upgrade` after enabling openregister.');
            return;
        }

        try {
            $configurationService = $container->get('OCA\\OpenRegister\\Service\\ConfigurationService');
            $migrator = $container->get(LegacyToRegisterMigrator::class);
        } catch (\Throwable $e) {
            $output->warning('chain-B: failed to resolve services ('.$e->getMessage().'); skipping.');
            return;
        }

        $descriptorPath = __DIR__.'/../Settings/openconnector_register.json';
        $output->info(sprintf('chain-B: importing register descriptor from %s', $descriptorPath));

        $descriptor = json_decode((string) file_get_contents($descriptorPath), true, flags: JSON_THROW_ON_ERROR);
        $appVersion = $appConfig->getValueString('openconnector', 'installed_version', '1.0.0');

        $configurationService->importFromApp(
            appId: 'openconnector',
            data: $descriptor,
            version: $appVersion
        );
        $output->info('chain-B: register descriptor imported (idempotent — existing schemas reused).');

        $output->info('chain-B: starting legacy → OR row migration via LegacyToRegisterMigrator::migrateAll()');
        $result = $migrator->migrateAll(
            dryRun: false,
            entitySlug: null,
            batchSize: 10000
        );

        $allOk = true;
        foreach ($result as $perEntity) {
            $skipped = (int) ($perEntity['skipped'] ?? 0);
            $output->info(
                    sprintf(
                '  %s: legacy=%d migrated=%d skipped=%d fkRewrites=%d (%dms)',
                $perEntity['slug'] ?? '?',
                (int) ($perEntity['legacyCount'] ?? 0),
                (int) ($perEntity['migratedCount'] ?? 0),
                $skipped,
                (int) ($perEntity['fkRewrites'] ?? 0),
                (int) ($perEntity['elapsedMs'] ?? 0)
            )
                    );
            if ($skipped > 0 || !empty($perEntity['error'])) {
                $allOk = false;
            }
        }

        if ($allOk === true) {
            $appConfig->setValueString('openconnector', 'storage_migrated', 'true');
            $output->info('chain-B: storage_migrated=true — all 15 entities copied successfully.');
        } else {
            $output->warning('chain-B: storage_migrated flag NOT set — at least one entity reported skips or errors. Use occ openconnector:migrate-storage to retry per-entity.');
        }
    }//end postSchemaChange()
}//end class
