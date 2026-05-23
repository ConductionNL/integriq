<?php

/**
 * OpenConnector InitializeRegister Repair Step
 *
 * Imports the openconnector register + 15 schemas into OpenRegister on
 * install / upgrade. Repair-step counterpart to the fleet pattern
 * (decidesk, procest, pipelinq, scholiq, …): IRepairStep runs with all
 * enabled apps' autoloaders registered, so calling
 * `ConfigurationService::importFromApp()` works in fresh `occ app:enable`
 * flows where a Migration::postSchemaChange would silently skip (NC
 * doesn't fully bootstrap peer apps' PSR-4 paths before migrations
 * run).
 *
 * @category Repair
 * @package  OCA\OpenConnector\Repair
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>.
// SPDX-License-Identifier: EUPL-1.2.
declare(strict_types=1);

namespace OCA\OpenConnector\Repair;

use OCA\OpenConnector\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that imports the openconnector register descriptor into
 * OpenRegister via `ConfigurationService::importFromApp()`.
 *
 * Wired via `appinfo/info.xml` `<repair-steps>` under both `<install>`
 * (first enable) and `<post-migration>` (every subsequent `occ upgrade`).
 *
 * Idempotent — OR's importFromApp uses the descriptor's `version`
 * field to short-circuit when the existing register is up to date,
 * and we additionally guard the matching legacy → OR row migration
 * via the `storage_migrated` app-config flag.
 */
class InitializeRegister implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param ContainerInterface $container Server DI container (used to
     *                                      resolve OR's ConfigurationService
     *                                      lazily so the class_exists guard
     *                                      can short-circuit when OR isn't
     *                                      enabled).
     * @param IAppConfig         $appConfig Used to gate the legacy-table
     *                                      migration on the
     *                                      `storage_migrated` flag.
     * @param LoggerInterface    $logger    For non-fatal errors that
     *                                      shouldn't trip the IRepairStep
     *                                      contract.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Human-readable name surfaced by `occ` during install / upgrade.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Initialize OpenConnector register and 15 schemas via ConfigurationService';

    }//end getName()

    /**
     * Import openconnector_register.json into OpenRegister.
     *
     * @param IOutput $output progress/info channel piped to occ stdout
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        $output->info('OpenConnector: initializing register + schemas via OR ConfigurationService…');

        if (class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService') === false) {
            $output->warning('OpenConnector: OpenRegister is not installed or enabled. Skipping register initialization.');
            $this->logger->warning(
                'OpenConnector: OpenRegister not available, skipping register initialization'
            );
            return;
        }

        try {
            $configurationService = $this->container->get('OCA\\OpenRegister\\Service\\ConfigurationService');
        } catch (\Throwable $e) {
            $output->warning('OpenConnector: could not resolve OR ConfigurationService: '.$e->getMessage());
            $this->logger->error(
                'OpenConnector: ConfigurationService resolution failed',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $descriptorPath = __DIR__.'/../Settings/openconnector_register.json';
        if (file_exists($descriptorPath) === false) {
            $output->warning('OpenConnector: register descriptor missing at '.$descriptorPath.' — nothing to import');
            return;
        }

        try {
            $descriptor = json_decode((string) file_get_contents($descriptorPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $output->warning('OpenConnector: register descriptor JSON parse failed: '.$e->getMessage());
            $this->logger->error(
                'OpenConnector: descriptor JSON parse failed',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $appVersion = $this->appConfig->getValueString('openconnector', 'installed_version', '1.0.0');

        try {
            $configurationService->importFromApp(
                appId: Application::APP_ID,
                data: $descriptor,
                version: $appVersion
            );
            $output->info('OpenConnector: register descriptor imported (existing schemas reused).');
        } catch (\Throwable $e) {
            $output->warning('OpenConnector: register import failed: '.$e->getMessage());
            $this->logger->error(
                'OpenConnector: importFromApp failed',
                ['exception' => $e->getMessage()]
            );
        }

    }//end run()
}//end class
