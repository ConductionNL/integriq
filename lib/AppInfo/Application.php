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
use OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClient as AdapterPdokGeocodingClient;
use OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClientHttp;
use OCA\OpenConnector\Adapters\Pdok\PdokGeocodingClientMock;
use OCA\OpenConnector\Adapters\Pdok\PdokWfsClient;
use OCA\OpenConnector\Adapters\Pdok\PdokWfsClientHttp;
use OCA\OpenConnector\Adapters\Pdok\PdokWfsClientMock;
use OCA\OpenConnector\Adapters\Pdok\PdokWmsClient;
use OCA\OpenConnector\Adapters\Pdok\PdokWmsClientHttp;
use OCA\OpenConnector\Adapters\Pdok\PdokWmsClientMock;
use OCA\OpenConnector\Adapters\Berichtenbox\BerichtenboxClient;
use OCA\OpenConnector\Adapters\Berichtenbox\BerichtenboxClientMock;
use OCA\OpenConnector\EventListener\ObjectCreatedEventListener;
use OCA\OpenConnector\EventListener\ObjectDeletedEventListener;
use OCA\OpenConnector\EventListener\ObjectUpdatedEventListener;
use OCA\OpenConnector\EventListener\ViewDeletedEventListener;
use OCA\OpenConnector\EventListener\ViewUpdatedOrCreatedEventListener;
use OCA\OpenConnector\Service\Integration\SynchronizationContractProvider;
use OCA\OpenConnector\Service\OrganisationBridgeService;
use OCA\OpenConnector\Service\SettingsService;
use OCA\OpenConnector\Sources\Pdok\PdokGeocodingClient as SourcePdokGeocodingClient;
use OCA\OpenConnector\Sources\Pdok\PdokWfsSourceAdapter;
use OCA\OpenConnector\Sources\Pdok\PdokWmsSourceAdapter;
use OCA\OpenConnector\Sources\Berichtenbox\BerichtenboxSourceAdapter;
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
use OCP\IRequest;
use OCP\Util;
use Psr\Container\ContainerInterface;

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

        $this->assertStorageMigrated();

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

        // Dormant + active PDOK source-pattern adapters (lib/Sources/Pdok/).
        //
        // The abstract `PdokWmsClient`, `PdokWfsClient`, and
        // `PdokGeocodingClient` (lib/Adapters/Pdok/) are resolved to the
        // appropriate concrete flavour based on the `pdok.feature_flag`
        // app-config flag:
        //   - `'1'` or `'true'`  → the `*ClientHttp` implementation
        //     (real outbound HTTPS calls against api.pdok.nl /
        //     service.pdok.nl).
        //   - anything else (default) → the `*ClientMock` implementation
        //     (deterministic canned responses; no network access).
        //
        // The Source-pattern facades (`PdokWmsSourceAdapter`,
        // `PdokWfsSourceAdapter`, `PdokGeocodingClient` under the Sources
        // namespace) layer logging + Source-row identity on top so they can
        // be discovered through the openconnector Source registry under
        // category `geo`.
        $isPdokActive = static function ($c): bool {
            $config = $c->get('OCP\IAppConfig');
            $raw    = $config->getValueString('openconnector', 'pdok.feature_flag', '0');
            return ($raw === '1' || strtolower($raw) === 'true');
        };

        $context->registerService(
            PdokWmsClient::class,
            static function ($c) use ($isPdokActive) {
                return $isPdokActive($c) === true
                    ? $c->get(PdokWmsClientHttp::class)
                    : $c->get(PdokWmsClientMock::class);
            }
        );
        $context->registerService(
            PdokWfsClient::class,
            static function ($c) use ($isPdokActive) {
                return $isPdokActive($c) === true
                    ? $c->get(PdokWfsClientHttp::class)
                    : $c->get(PdokWfsClientMock::class);
            }
        );
        $context->registerService(
            AdapterPdokGeocodingClient::class,
            static function ($c) use ($isPdokActive) {
                return $isPdokActive($c) === true
                    ? $c->get(PdokGeocodingClientHttp::class)
                    : $c->get(PdokGeocodingClientMock::class);
            }
        );

        // Explicit factories for the *ClientHttp flavours so the Guzzle
        // ClientInterface is injected via a shared singleton; NC's
        // auto-wiring can't construct GuzzleHttp\Client directly because
        // ClientInterface is an interface.
        $context->registerService(
            PdokGeocodingClientHttp::class,
            static function ($c) {
                return new PdokGeocodingClientHttp(
                    httpClient: new \GuzzleHttp\Client(),
                    logger: $c->get('Psr\Log\LoggerInterface')
                );
            }
        );
        $context->registerService(
            PdokWmsClientHttp::class,
            static function ($c) {
                return new PdokWmsClientHttp(
                    httpClient: new \GuzzleHttp\Client(),
                    logger: $c->get('Psr\Log\LoggerInterface')
                );
            }
        );
        $context->registerService(
            PdokWfsClientHttp::class,
            static function ($c) {
                return new PdokWfsClientHttp(
                    httpClient: new \GuzzleHttp\Client(),
                    logger: $c->get('Psr\Log\LoggerInterface')
                );
            }
        );
        $context->registerService(
            PdokWmsSourceAdapter::class,
            static function ($c) {
                return new PdokWmsSourceAdapter(
                    config: $c->get('OCP\IAppConfig'),
                    logger: $c->get('Psr\Log\LoggerInterface'),
                    wmsClient: $c->get(PdokWmsClient::class)
                );
            }
        );
        $context->registerService(
            PdokWfsSourceAdapter::class,
            static function ($c) {
                return new PdokWfsSourceAdapter(
                    config: $c->get('OCP\IAppConfig'),
                    logger: $c->get('Psr\Log\LoggerInterface'),
                    wfsClient: $c->get(PdokWfsClient::class)
                );
            }
        );
        $context->registerService(
            SourcePdokGeocodingClient::class,
            static function ($c) {
                return new SourcePdokGeocodingClient(
                    config: $c->get('OCP\IAppConfig'),
                    logger: $c->get('Psr\Log\LoggerInterface'),
                    geocodingClient: $c->get(AdapterPdokGeocodingClient::class)
                );
            }
        );

        // Wave-4 external-API low-volume families.
        //
        // - Logius Berichtenbox (BBK 1.7 — burgerportaal-mijnoverheid-bridge,
        //   procest berichtenbox-integration spec). The abstract
        //   BerichtenboxClient resolves to BerichtenboxClientMock by
        //   default; flip `logius.berichtenbox.feature_flag` and bind
        //   the BerichtenboxClientHttp implementation to activate.
        $context->registerService(
            BerichtenboxClient::class,
            static function ($c) {
                return $c->get(BerichtenboxClientMock::class);
            }
        );
        $context->registerService(
            BerichtenboxSourceAdapter::class,
            static function ($c) {
                return new BerichtenboxSourceAdapter(
                    config: $c->get('OCP\IAppConfig'),
                    logger: $c->get('Psr\Log\LoggerInterface'),
                    berichtenboxClient: $c->get(BerichtenboxClient::class)
                );
            }
        );

        $this->registerAppHostObservability(context: $context);
    }//end register()

    /**
     * Wire the OpenRegister AppHost declarative observability engine.
     *
     * ADR-040 / ADR-006. OpenConnector adopts OpenRegister's AppHost
     * observability engine instead of hand-writing its `/api/health` +
     * `/api/metrics` controllers. The `metrics#index` and `health#index`
     * route names (URLs `/api/metrics`, `/api/health`) are aliased here at
     * the engine-owned generic controllers — built with `appName` =
     * `openconnector` so the engine reads THIS app's manifest
     * `observability` block, while the engine's own collaborators
     * (ManifestLoader / HealthCheckExecutor / MetricsEngine) are resolved
     * from OpenRegister's registered app container where they are pre-wired.
     *
     * Lazy + fail-soft: if OpenRegister is not present the factories throw
     * `QueryException` at route-resolution time (the standard NC "controller
     * could not be built" path) rather than fataling app bootstrap — every
     * Conduction app hard-requires OpenRegister (ADR-022), so this only fires
     * on a broken install, and the route 500s instead of bricking the SPA.
     *
     * NOTE: the boilerplate half of the AppHost adoption (the
     * `apphost-boilerplate-controllers` `Bootstrap::register()` /
     * `Routes::standard()` helpers and the generic Settings / Dashboard /
     * Preferences / repair-step classes) is intentionally NOT adopted here:
     * those generic classes do not yet exist in the OpenRegister release this
     * app builds against. OpenConnector keeps its bespoke SPA/UiController,
     * Preferences, Settings and repair plumbing until that engine half ships.
     *
     * @param IRegistrationContext $context Registration context.
     *
     * @return void
     *
     * @spec openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md
     */
    private function registerAppHostObservability(IRegistrationContext $context): void
    {
        // Alias the `health#index` route name (URL /api/health, unchanged) at
        // the engine's GenericHealthController, scoped to this app's manifest.
        $context->registerService(
            'OCA\\OpenConnector\\Controller\\HealthController',
            static function (ContainerInterface $c) {
                $orContainer = \OC::$server->getRegisteredAppContainer('openregister');
                return new \OCA\OpenRegister\AppHost\Controller\GenericHealthController(
                    appName: self::APP_ID,
                    request: $c->get(IRequest::class),
                    manifestLoader: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
                    executor: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor::class)
                );
            }
        );

        // Alias the `metrics#index` route name (URL /api/metrics, unchanged)
        // at the engine's GenericMetricsController (admin-only — the engine
        // owns that posture; ADR-006).
        $context->registerService(
            'OCA\\OpenConnector\\Controller\\MetricsController',
            static function (ContainerInterface $c) {
                $orContainer = \OC::$server->getRegisteredAppContainer('openregister');
                return new \OCA\OpenRegister\AppHost\Controller\GenericMetricsController(
                    appName: self::APP_ID,
                    request: $c->get(IRequest::class),
                    manifestLoader: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
                    engine: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\MetricsEngine::class)
                );
            }
        );
    }//end registerAppHostObservability()

    /**
     * Soft pre-flight check: warn when the legacy→OpenRegister storage migration has
     * not yet run.
     *
     * Chain C removed every `lib/Db/*Mapper.php` and entity class; all domain data
     * now lives in OpenRegister objects (ADR-001). The connector-specific services
     * that inject OpenRegister's `ObjectService` only have data to operate on once
     * the one-shot migrator has copied every legacy row across and flipped the
     * `openconnector.storage_migrated` IAppConfig flag to `'true'`.
     *
     * NOTE (consolidate-merge): PR #29 originally threw a `\LogicException` here as a
     * hard pre-flight. Development deliberately deferred that hard gate — a boot-time
     * throw risks bricking instances that re-installed from a fresh dump without
     * running migrate-storage (see openconnector-services-direct-or-usage/tasks.md).
     * The guard is therefore kept as PR #29's diagnostic intent but downgraded to a
     * non-fatal log so neither side's behaviour is lost and app boot is never crashed
     * by the check itself. The hard gate remains tracked as the OCC command's soft
     * gate (Task 15) and can be re-enabled in a dedicated change once the
     * legacy-table-cleanup release ships.
     *
     * Bypassable in CI/test (where no real upgrade has run) via
     * `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1`, and soft-skipped if `IAppConfig`
     * cannot be resolved during very early bootstrap.
     *
     * @return void
     *
     * @spec openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-applicationphp-di-bindings-must-be-updated
     */
    private function assertStorageMigrated(): void
    {
        // CI / test bypass — no real upgrade has run in those environments.
        if (getenv('OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT') !== false) {
            return;
        }

        try {
            $appConfig = $this->getContainer()->get(\OCP\IAppConfig::class);
        } catch (\Throwable) {
            // IAppConfig not resolvable this early — don't disturb boot over the guard.
            return;
        }

        if ($appConfig->getValueString(self::APP_ID, 'storage_migrated', 'false') === 'true') {
            return;
        }

        // Non-fatal: log a warning instead of throwing, so an unmigrated instance
        // still boots (development's deferral decision) while operators get a signal.
        try {
            $logger = $this->getContainer()->get(\Psr\Log\LoggerInterface::class);
            $logger->warning(
                'openconnector: legacy storage has not been migrated to OpenRegister. '
                .'Run "occ openconnector:migrate-storage" to materialise the register and '
                .'copy legacy rows. Connector services will operate on an empty register '
                .'until then.'
            );
        } catch (\Throwable) {
            // Logger unavailable this early — nothing more we can safely do.
            return;
        }

    }//end assertStorageMigrated()

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
        $this->ensureRegisterBootstrapped();

    }//end boot()

    /**
     * Ensure the openconnector register + schemas exist in OpenRegister.
     *
     * The InitializeRegister repair step runs during app install/upgrade, but
     * bails when OpenRegister isn't loaded yet at that moment (install order or
     * autoloader timing), leaving the app non-functional out of the box until
     * an admin runs `occ maintenance:repair`. As a safety net, run the same
     * repair step once at boot — when OR is guaranteed loaded — guarded by an
     * app-config flag keyed to the installed version so it is a cheap no-op on
     * every subsequent request.
     *
     * @return void
     */
    private function ensureRegisterBootstrapped(): void
    {
        if (class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService') === false) {
            return;
        }

        try {
            $container = $this->getContainer();
            $appConfig = $container->get(\OCP\IAppConfig::class);

            $installedVersion    = $appConfig->getValueString(self::APP_ID, 'installed_version', '');
            $bootstrappedVersion = $appConfig->getValueString(self::APP_ID, 'register_bootstrapped_version', '');
            if ($installedVersion !== '' && $bootstrappedVersion === $installedVersion) {
                return;
            }

            $repair = $container->get(\OCA\OpenConnector\Repair\InitializeRegister::class);
            $repair->run(
                new class implements \OCP\Migration\IOutput {
                    public function debug(string $message): void
                    {
                    }
                    public function info($message)
                    {
                    }
                    public function warning($message)
                    {
                    }
                    public function startProgress($max=0)
                    {
                    }
                    public function advance($step=1, $description='')
                    {
                    }
                    public function finishProgress()
                    {
                    }
                }
            );

            $appConfig->setValueString(self::APP_ID, 'register_bootstrapped_version', $installedVersion);
        } catch (\Throwable $e) {
            // Soft-fail so a bootstrap hiccup never breaks page loads; the next
            // boot retries (the flag is only set on success).
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
                '[openconnector] boot-time register bootstrap failed: '.$e->getMessage()
            );
        }

    }//end ensureRegisterBootstrapped()

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
