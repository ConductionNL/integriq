<?php

declare(strict_types=1);

namespace OCA\OpenConnector\AppInfo;

use OCA\OpenConnector\EventListener\ObjectCreatedEventListener;
use OCA\OpenConnector\EventListener\ObjectDeletedEventListener;
use OCA\OpenConnector\EventListener\ObjectUpdatedEventListener;
use OCA\OpenConnector\EventListener\ViewDeletedEventListener;
use OCA\OpenConnector\EventListener\ViewUpdatedOrCreatedEventListener; // @todo: remove this temporary listener to the software catalog application
use OCA\OpenConnector\Service\Integration\SynchronizationContractProvider;
use OCA\OpenConnector\Service\OrganisationBridgeService;
use OCA\OpenConnector\Service\SettingsService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'openconnector';

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }//end __construct()

    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Post chain-B/C cutover, openconnector controllers inject OR's
        // ObjectService via DI. Nextcloud resolves controller constructor
        // deps via PHP reflection, which requires the class to be autoloaded
        // BEFORE the controller is built. loadApp('openregister') registers
        // OR's PSR-4 paths. MUST be in register() (per-request) — boot() is
        // too late for controller-resolution paths.
        try {
            $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
            if ($appManager->isInstalled('openregister') === true) {
                $appManager->loadApp('openregister');
            }
        } catch (\Throwable) {
            // OR unavailable — let downstream DI failures surface clearly.
        }

        // Register services
        $context->registerService(
          SettingsService::class,
          function ($c) {
            return new SettingsService(
                $c->get('OCP\IDBConnection'),
                $c->get('OCP\IAppConfig'),
                $c->get('Psr\Log\LoggerInterface')
            );
          }
          );

        /* @var IEventDispatcher $dispatcher */
        $dispatcher = $this->getContainer()->get(IEventDispatcher::class);
        $dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: ObjectCreatedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: ObjectUpdatedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ViewDeletedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ObjectDeletedEventListener::class);
        // @todo: remove this temporary listener to the software catalog application
        //        $dispatcher->addServiceListener(eventName: ViewUpdatedOrCreatedEventListener::class, className: ViewUpdatedOrCreatedEventListener::class);
    }//end register()

    public function boot(IBootContext $context): void
    {
        $this->registerIntegrationProviders(context: $context);
    }//end boot()

    /**
     * Register openconnector-side IntegrationProviders with OR's
     * IntegrationRegistry. Per OR's pluggable-integration-registry spec
     * (AD-1), apps register their providers at boot — OR's registry is
     * a shared per-request service so all apps see the same instance.
     *
     * Currently registered:
     *   - SynchronizationContractProvider — surfaces SyncContract leaves
     *     on the OR objects they synchronise (GH #824).
     *
     * Soft-fails if OR's IntegrationRegistry isn't available (e.g. when
     * openconnector is loaded but openregister isn't enabled yet) so
     * boot doesn't crash on a stale install.
     */
    private function registerIntegrationProviders(IBootContext $context): void
    {
        if (class_exists(IntegrationRegistry::class) === false) {
            return;
        }

        try {
            $container = $context->getServerContainer();
            $registry  = $container->get(IntegrationRegistry::class);
            $registry->addProvider($container->get(SynchronizationContractProvider::class));
        } catch (\Throwable $e) {
            // Don't crash boot — log and continue. The provider just won't appear
            // in object sidebars on this instance until the registry resolves.
            try {
                $context->getServerContainer()
                    ->get('Psr\Log\LoggerInterface')
                    ->warning(
                        'openconnector: failed to register IntegrationProviders with OR — '.$e->getMessage(),
                        ['exception' => $e]
                    );
            } catch (\Throwable) {
                // Logger unavailable, ignore.
            }
        }
    }//end registerIntegrationProviders()
}//end class
