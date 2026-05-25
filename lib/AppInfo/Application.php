<?php
/**
 * OpenConnector App Bootstrap.
 *
 * Registers services, event listeners and integration providers when the
 * Nextcloud framework bootstraps the openconnector app.
 *
 * @category AppInfo
 * @package  OCA\OpenConnector\AppInfo
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

namespace OCA\OpenConnector\AppInfo;

// @todo Remove ViewUpdatedOrCreatedEventListener once it lives in the software catalog application.
use OCA\OpenConnector\EventListener\ObjectCreatedEventListener;
use OCA\OpenConnector\EventListener\ObjectDeletedEventListener;
use OCA\OpenConnector\EventListener\ObjectUpdatedEventListener;
use OCA\OpenConnector\EventListener\ViewDeletedEventListener;
use OCA\OpenConnector\EventListener\ViewUpdatedOrCreatedEventListener;
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
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Util;

/**
 * Bootstrap entry point for the OpenConnector app.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends App implements IBootstrap
{

    /**
     * App identifier.
     *
     * @var string
     */
    public const APP_ID = 'openconnector';

    /**
     * Constructor.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);

    }//end __construct()

    /**
     * Register services and event listeners.
     *
     * @param IRegistrationContext $context Registration context.
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // Register services.
        $context->registerService(
          SettingsService::class,
          function ($c) {
            return new SettingsService(
                db: $c->get('OCP\IDBConnection'),
                config: $c->get('OCP\IAppConfig'),
                logger: $c->get('Psr\Log\LoggerInterface')
            );
          }
          );

        /*
         * Event dispatcher registration.
         *
         * @var IEventDispatcher $dispatcher
         */

        $dispatcher = $this->getContainer()->get(IEventDispatcher::class);
        $dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: ObjectCreatedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: ObjectUpdatedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ViewDeletedEventListener::class);
        $dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ObjectDeletedEventListener::class);
        // @todo Remove this temporary listener to the software catalog application.
        // $dispatcher->addServiceListener(eventName: ViewUpdatedOrCreatedEventListener::class, className: ViewUpdatedOrCreatedEventListener::class);
        // Path-2 integration leaf: load the tiny `openconnector-integration`
        // bundle on EVERY full-page render (not just OpenConnector's own SPA)
        // so the "Synced from" component is registered on the OpenRegister
        // integration registry wherever a host app renders an object detail
        // page (e.g. an OpenCatalogi publication). BeforeTemplateRenderedEvent
        // fires for every app's page, so the global init-script lands once
        // per page regardless of which app is active.
        $dispatcher->addListener(
            BeforeTemplateRenderedEvent::class,
            static function (): void {
                Util::addInitScript('openconnector', 'openconnector-integration');
            }
        );
    }//end register()

    /**
     * Boot the app and wire integration providers.
     *
     * @param IBootContext $context Boot context.
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        $this->registerIntegrationProviders(context: $context);

    }//end boot()

    /**
     * Register openconnector-side IntegrationProviders with OR's IntegrationRegistry.
     *
     * Per OR's pluggable-integration-registry spec (AD-1), apps register their
     * providers at boot — OR's registry is a shared per-request service so all
     * apps see the same instance.
     *
     * Currently registered:
     *   - SynchronizationContractProvider — surfaces SyncContract leaves on the
     *     OR objects they synchronise (GH #824).
     *
     * Soft-fails if OR's IntegrationRegistry isn't available (e.g. when
     * openconnector is loaded but openregister isn't enabled yet) so boot
     * doesn't crash on a stale install.
     *
     * @param IBootContext $context Boot context.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-repair-and-app-boot/tasks.md#task-2
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
