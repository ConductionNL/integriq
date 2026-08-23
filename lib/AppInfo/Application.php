<?php

/**
 * Integriq App Bootstrap.
 *
 * Registers services, event listeners and integration providers when the
 * Nextcloud framework bootstraps the integriq app.
 *
 * @category AppInfo
 * @package  OCA\Integriq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\AppInfo;

// @todo Remove ViewUpdatedOrCreatedEventListener once it lives in the software catalog application.
use GuzzleHttp\Client as GuzzleHttpClient;
use OCA\DAV\Events\CachedCalendarObjectCreatedEvent;
use OCA\DAV\Events\CachedCalendarObjectDeletedEvent;
use OCA\DAV\Events\CachedCalendarObjectUpdatedEvent;
use OCA\Forms\Events\FormSubmittedEvent;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClient;
use OCA\Integriq\Adapters\Berichtenbox\BerichtenboxClientMock;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient as AdapterPdokGeocodingClient;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientMock;
use OCA\Integriq\Adapters\Pdok\PdokWfsClient;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokWfsClientMock;
use OCA\Integriq\Adapters\Pdok\PdokWmsClient;
use OCA\Integriq\Adapters\Pdok\PdokWmsClientHttp;
use OCA\Integriq\Adapters\Pdok\PdokWmsClientMock;
use OCA\Integriq\Capabilities;
use OCA\Integriq\Controller\HealthController;
use OCA\Integriq\Controller\MetricsController;
use OCA\Integriq\EventListener\CloudEventListener;
use OCA\Integriq\EventListener\EndpointCacheInvalidationListener;
use OCA\Integriq\EventListener\NextcloudCalendarEventListener;
use OCA\Integriq\EventListener\NextcloudFileEventListener;
use OCA\Integriq\EventListener\NextcloudFileTagEventListener;
use OCA\Integriq\EventListener\NextcloudFormsEventListener;
use OCA\Integriq\EventListener\NextcloudTablesEventListener;
use OCA\Integriq\EventListener\ObjectCreatedEventListener;
use OCA\Integriq\EventListener\ObjectDeletedEventListener;
use OCA\Integriq\EventListener\ObjectUpdatedEventListener;
use OCA\Integriq\EventListener\ViewDeletedEventListener;
use OCA\Integriq\EventListener\ViewUpdatedOrCreatedEventListener;
use OCA\Integriq\Observability\IntegriqMetricsProvider;
use OCA\Integriq\Repair\InitializeActions;
use OCA\Integriq\Sections\IntegriqAdmin as IntegriqAdminSection;
use OCA\Integriq\Service\Adapter\DataInfra\S3Adapter;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\Forms\FormsClientInterface;
use OCA\Integriq\Service\Forms\FormsOcsClient;
use OCA\Integriq\Service\Integration\SynchronizationContractProvider;
use OCA\Integriq\Service\PeppolOutboundConsumer;
use OCA\Integriq\Service\SettingsService;
use OCA\Integriq\Service\Tables\TablesClientInterface;
use OCA\Integriq\Service\Tables\TablesOcsClient;
use OCA\Integriq\Settings\IntegriqAdmin as IntegriqAdminSettings;
use OCA\Integriq\SetupCheck\OpenRegisterDependencyCheck;
use OCA\Integriq\Sources\Berichtenbox\BerichtenboxSourceAdapter;
use OCA\Integriq\Sources\Pdok\PdokGeocodingClient as SourcePdokGeocodingClient;
use OCA\Integriq\Sources\Pdok\PdokWfsSourceAdapter;
use OCA\Integriq\Sources\Pdok\PdokWmsSourceAdapter;
use OCA\Integriq\WorkflowEngine\RegisterOperationsListener;
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Repair\GenericInitializeActions;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\Tables\Event\RowAddedEvent;
use OCA\Tables\Event\RowDeletedEvent;
use OCA\Tables\Event\RowUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IRequest;
use OCP\SystemTag\MapperEvent;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use Psr\Container\ContainerInterface;

/**
 * Bootstrap entry point for the Integriq app.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class Application extends App implements IBootstrap {

	/**
	 * App identifier.
	 *
	 * @var string
	 */
	public const APP_ID = 'integriq';

	/**
	 * Constructor.
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);

	}//end __construct()

	/**
	 * Register services and event listeners.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 */
	public function register(IRegistrationContext $context): void {
		include_once __DIR__ . '/../../vendor/autoload.php';

		// LOAD-ORDER HAZARD: OC_App::getEnabledApps() sort()s the app list and
		// Coordinator::registerApps() calls registerAutoloading() then register()
		// one app at a time, so this method runs BEFORE OCA\OpenRegister\ is
		// autoloadable (this app sorts before `openregister`). Any AppHost
		// reference here — including a class_exists() probe — therefore answers
		// FALSE on a perfectly healthy instance. Put OpenRegister's prefix on the
		// autoloader ourselves; registerAutoloading() touches only the autoloader
		// and is idempotent ($alreadyRegistered key guard). Deliberately NOT
		// IAppManager::loadApp(), which would mark OpenRegister loaded and boot it
		// before its own register() had run.
		try {
			$openRegisterPath = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath('openregister');
			\OC_App::registerAutoloading('openregister', $openRegisterPath);
		} catch (\Throwable) {
			// OpenRegister absent/disabled — fall through to the degraded path.
		}

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
		// Peppol-access-point-connector: reacts to nl.conduction.peppol.outbound.requested
		// CloudEvents (register `openconnector` — the OpenRegister register slug,
		// frozen across the app-id rename; schema event) created by any app.
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: PeppolOutboundConsumer::class);
		// Outbound webhooks / CloudEvent subscriptions: turns any OR object
		// create/update/delete (any app) into a CloudEvent fanned out to
		// matching `event_subscription`s (events-cloudevents spec REQ-004).
		// Internally gated (EventService::hasActiveSubscriptions) so an
		// install with no configured subscriptions pays no persistence cost,
		// and guards against re-forwarding its own `event`/`event_message`
		// writes (would otherwise recurse — see CloudEventListener docblock).
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: CloudEventListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: CloudEventListener::class);
		$dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: CloudEventListener::class);
		// Nextcloud-core-event triggers (nextcloud-event-hub). Each family
		// normalizes its NC event into the SAME `event` CloudEvents envelope
		// shape the OR-object pipeline above already uses, then hands off to
		// the same processEvent/deliverMessage/retry/dead-letter machinery
		// (EventService::handleNextcloudEvent) — see design.md Decision 2.
		$this->registerNextcloudEventTriggers(context: $context, dispatcher: $dispatcher);

		// WorkflowEngine (NC's Settings > Flow UI) integration: registers
		// "Run synchronization"/"Call endpoint"/"Fire CloudEvent" as
		// `OCP\WorkflowEngine\ISpecificOperation`s an admin can wire to
		// file/tag Flow rules, feature-detected on the bundled
		// `workflowengine` app being enabled (flow-workflowengine-integration
		// design.md Decision 2).
		$this->registerWorkflowEngineOperations(context: $context, dispatcher: $dispatcher);

		// Flow nodes contributed to OpenRegister's flow engine (ADR-065): the
		// `source-call` and `synchronization-run` nodes that let a flow reach an
		// external API through a governed Integriq Source. Guarded on the
		// OR flow engine being present so this app still boots without it.
		$this->registerFlowNodes(dispatcher: $dispatcher);

		// Endpoint routing cache: clear it whenever an `openconnector`/endpoint
		// (register slug frozen across the app-id rename)
		// object is created, updated, or deleted so the runtime path matcher
		// (EndpointCacheService) never serves stale routing (self-gated on
		// register+schema slug, so unrelated object writes are a cheap no-op).
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: EndpointCacheInvalidationListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: EndpointCacheInvalidationListener::class);
		$dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: EndpointCacheInvalidationListener::class);
		// @todo Remove this temporary listener to the software catalog application.
		// $dispatcher->addServiceListener(eventName: ViewUpdatedOrCreatedEventListener::class, className: ViewUpdatedOrCreatedEventListener::class);
		// Path-2 integration leaf: load the tiny `integriq-integration`
		// bundle on EVERY full-page render (not just Integriq's own SPA)
		// so the "Synced from" component is registered on the OpenRegister
		// integration registry wherever a host app renders an object detail
		// page (e.g. an OpenCatalogi publication). BeforeTemplateRenderedEvent
		// fires for every app's page, so the global init-script lands once
		// per page regardless of which app is active.
		$dispatcher->addListener(
			BeforeTemplateRenderedEvent::class,
			static function (): void {
				Util::addInitScript('integriq', 'integriq-integration');
			}
		);

		// Dormant + active PDOK source-pattern adapters (lib/Sources/Pdok/).
		//
		// The abstract `PdokWmsClient`, `PdokWfsClient`, and
		// `PdokGeocodingClient` (lib/Adapters/Pdok/) are resolved to the
		// appropriate concrete flavour based on the `pdok.feature_flag`
		// app-config flag:
		// - `'1'` or `'true'`  → the `*ClientHttp` implementation
		// (real outbound HTTPS calls against api.pdok.nl /
		// service.pdok.nl).
		// - anything else (default) → the `*ClientMock` implementation
		// (deterministic canned responses; no network access).
		//
		// The Source-pattern facades (`PdokWmsSourceAdapter`,
		// `PdokWfsSourceAdapter`, `PdokGeocodingClient` under the Sources
		// namespace) layer logging + Source-row identity on top so they can
		// be discovered through the integriq Source registry under
		// category `geo`.
		$isPdokActive = static function ($c): bool {
			$config = $c->get('OCP\IAppConfig');
			$raw = $config->getValueString('integriq', 'pdok.feature_flag', '0');
			return ($raw === '1' || strtolower($raw) === 'true');
		};

		$context->registerService(
			PdokWmsClient::class,
			static function ($c) use ($isPdokActive) {
				if ($isPdokActive($c) === true) {
					return $c->get(PdokWmsClientHttp::class);
				}

				return $c->get(PdokWmsClientMock::class);
			}
		);
		$context->registerService(
			PdokWfsClient::class,
			static function ($c) use ($isPdokActive) {
				if ($isPdokActive($c) === true) {
					return $c->get(PdokWfsClientHttp::class);
				}

				return $c->get(PdokWfsClientMock::class);
			}
		);
		$context->registerService(
			AdapterPdokGeocodingClient::class,
			static function ($c) use ($isPdokActive) {
				if ($isPdokActive($c) === true) {
					return $c->get(PdokGeocodingClientHttp::class);
				}

				return $c->get(PdokGeocodingClientMock::class);
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
					httpClient: new GuzzleHttpClient(),
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
		$context->registerService(
			PdokWmsClientHttp::class,
			static function ($c) {
				return new PdokWmsClientHttp(
					httpClient: new GuzzleHttpClient(),
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
		$context->registerService(
			PdokWfsClientHttp::class,
			static function ($c) {
				return new PdokWfsClientHttp(
					httpClient: new GuzzleHttpClient(),
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
		// procest berichtenbox-integration spec). The abstract
		// BerichtenboxClient resolves to BerichtenboxClientMock by
		// default; flip `logius.berichtenbox.feature_flag` and bind
		// the BerichtenboxClientHttp implementation to activate.
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

		// Tables-bridge: bind the polymorphic Tables API seam to its concrete
		// v1-REST implementation (design.md Decision 2). `TablesOcsClient`'s
		// own dependencies (CallService, LoggerInterface) are plain
		// autowirable types, so only the interface binding needs an explicit
		// factory here.
		$context->registerService(
			TablesClientInterface::class,
			static function ($c) {
				return $c->get(TablesOcsClient::class);
			}
		);

		// Forms-connector: bind the polymorphic Forms API seam to its
		// concrete v3-REST implementation (design.md Decision 1), identical
		// shape to the TablesClientInterface binding above. `FormsOcsClient`'s
		// own dependencies (CallService, LoggerInterface) are plain
		// autowirable types, so only the interface binding needs an explicit
		// factory here. No conditional/feature-detected binding — the client
		// class itself has no `OCA\Forms\*` reference, so it is always
		// safely constructible; only *usage* is feature-detected
		// (FormsSyncAdapter::assertEnabled()).
		$context->registerService(
			FormsClientInterface::class,
			static function ($c) {
				return $c->get(FormsOcsClient::class);
			}
		);

		$this->registerAppHostObservability(context: $context);
		$this->registerAppHostBoilerplate(context: $context);

		// Fail-loud admin signal when the required OpenRegister app is absent.
		// The check uses IAppManager only (no OCA\OpenRegister\* reference) so it
		// is safe to run while OpenRegister is disabled (REQ-ADM-003).
		$context->registerSetupCheck(OpenRegisterDependencyCheck::class);

		// HITL approval workflow: the actionable approver notification is
		// dispatched imperatively (ApprovalService::notifyApprovers(), see
		// openspec/changes/hitl-approval-rule-action/design.md Decision 4) —
		// without a notifier registered under this app id, the notification
		// manager silently drops it when preparing it for display.
		$context->registerNotifierService(\OCA\Integriq\Notification\ApprovalNotifier::class);

		// Dashboard-http-datasource: advertise the capability so a leaf
		// dashboard/widget host (LaunchPad's live-data-tile-widget) can probe
		// for the resolve façade via the OCS capabilities document.
		$context->registerCapability(Capabilities::class);
	}//end register()

	/**
	 * Register the `nextcloud-event-triggers` capability's `IEventListener`s.
	 *
	 * Files and calendar registrations are unconditional (Decision 1):
	 * `OCP\Files\Events\Node\*` and `OCP\SystemTag\MapperEvent` are stable
	 * public `OCP` API present on every NC version this app targets (NC
	 * 28-34); `dav` (source of the OCA-internal `Cached*` calendar events)
	 * ships bundled with every instance, and `OCP\Calendar\Events\*`
	 * (`@since 32.0.0`) is referenced only via `::class` (a compile-time
	 * string — safe even where the class does not exist on NC < 32; see
	 * class docblocks on the listener classes and design.md Decision 1).
	 *
	 * Tables and Forms registrations are feature-detected via
	 * {@see self::appEnabledForAnyone()} — both are optional Nextcloud App
	 * Store apps. `appEnabledForAnyone('tables'|'forms')`
	 * returning false means the listener is never registered and no event
	 * of that family can ever be observed (REQ-003/REQ-004); this is not
	 * required for SAFETY (the `::class` reference is inert either way) but
	 * signals "is this event family available" for the subscription-modal
	 * event-type picker.
	 *
	 * @param IRegistrationContext $context Registration context.
	 * @param IEventDispatcher $dispatcher The NC event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
	 */
	private function registerNextcloudEventTriggers(IRegistrationContext $context, IEventDispatcher $dispatcher): void {
		// Files (REQ-001) — unconditional, stable OCP.
		$dispatcher->addServiceListener(eventName: NodeCreatedEvent::class, className: NextcloudFileEventListener::class);
		$dispatcher->addServiceListener(eventName: NodeWrittenEvent::class, className: NextcloudFileEventListener::class);
		$dispatcher->addServiceListener(eventName: NodeDeletedEvent::class, className: NextcloudFileEventListener::class);
		$dispatcher->addServiceListener(eventName: MapperEvent::class, className: NextcloudFileTagEventListener::class);

		// Calendar (REQ-002) — unconditional; `dav` ships with every
		// instance. Both the NC32+ OCP "own calendar" family and the
		// always-available OCA "cached subscription" family are wired so
		// one listener normalizes whichever fires (see
		// NextcloudCalendarEventListener's class docblock).
		$dispatcher->addServiceListener(eventName: CalendarObjectCreatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CalendarObjectUpdatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CalendarObjectDeletedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CachedCalendarObjectCreatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CachedCalendarObjectUpdatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CachedCalendarObjectDeletedEvent::class, className: NextcloudCalendarEventListener::class);

		// Tables (REQ-003) — feature-detected.
		try {
			$appManager = $this->getContainer()->get(\OCP\App\IAppManager::class);
			if ($this->appEnabledForAnyone(appManager: $appManager, appId: 'tables') === true) {
				$dispatcher->addServiceListener(eventName: RowAddedEvent::class, className: NextcloudTablesEventListener::class);
				$dispatcher->addServiceListener(eventName: RowUpdatedEvent::class, className: NextcloudTablesEventListener::class);
				$dispatcher->addServiceListener(eventName: RowDeletedEvent::class, className: NextcloudTablesEventListener::class);
			}

			// Forms (REQ-004) — feature-detected.
			if ($this->appEnabledForAnyone(appManager: $appManager, appId: 'forms') === true) {
				$dispatcher->addServiceListener(eventName: FormSubmittedEvent::class, className: NextcloudFormsEventListener::class);
			}
		} catch (\Throwable $e) {
			// IAppManager not resolvable this early on some SAPIs — degrade to
			// "Tables/Forms triggers unavailable this boot" rather than
			// crashing app registration; the OR-object and files/calendar
			// pipelines above are unaffected.
			try {
				$this->getContainer()->get(\Psr\Log\LoggerInterface::class)->warning(
					'integriq: could not feature-detect tables/forms for nextcloud-event-triggers — ' . $e->getMessage()
				);
			} catch (\Throwable) {
				// Logger unavailable, ignore.
			}
		}//end try

	}//end registerNextcloudEventTriggers()

	/**
	 * Whether an app is enabled for anyone, across the Nextcloud versions we support.
	 *
	 * HISTORY, because the shape of this method is not obvious. `IAppManager::isEnabledForAnyUser()`
	 * was RENAMED to `isEnabledForAnyone()` and the old name is gone in Nextcloud 34, where
	 * calling it raises "Call to undefined method OC\App\AppManager::isEnabledForAnyUser()".
	 * The caller catches Throwable and degrades to "Tables/Forms triggers unavailable", so
	 * the failure was silent in behaviour and loud in the log — two warnings on every request
	 * that boots this app, and the Tables and Forms triggers never registered at all on NC 34.
	 * #1103 then replaced a `false` fallback with `isInstalled()`, because `false` is not a
	 * safe default: it reports an installed, enabled Tables or Forms app as unavailable and
	 * silently skips registering its triggers.
	 *
	 * BOTH of those compatibility layers are now gone, and this method is a single call.
	 * `isEnabledForAnyone()` is `@since 32.0.0` and info.xml declares `min-version="32"`
	 * (raised in #1173, because `<app>openregister</app>` requires 32), so every server this
	 * app can install on has the method. The `method_exists()` probe could only ever take the
	 * true branch, and the `isInstalled()` fallback behind it was unreachable code — which
	 * static analysis could not say while `nextcloud/ocp` was pinned to `dev-stable29`, three
	 * majors below the declared floor (#1174). `isInstalled()` is also itself
	 * `@deprecated 32.0.0`, naming this very method as its replacement.
	 *
	 * @param \OCP\App\IAppManager $appManager The app manager.
	 * @param string $appId The app to test.
	 *
	 * @return boolean Whether the app is enabled for anyone.
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
	 */
	private function appEnabledForAnyone(\OCP\App\IAppManager $appManager, string $appId): bool {
		return $appManager->isEnabledForAnyone($appId);
	}//end appEnabledForAnyone()

	/**
	 * Register Integriq's three thin `ISpecificOperation` adapters
	 * ("Run synchronization", "Call endpoint", "Fire CloudEvent") with NC
	 * core's bundled `workflowengine` app (Settings > Flow), so an admin can
	 * wire a file/tag Flow rule directly to an existing Integriq
	 * synchronization/endpoint/CloudEvent — see
	 * flow-workflowengine-integration design.md.
	 *
	 * `RegisterOperationsEvent` is the only documented registration path
	 * (discovery.md finding 2) — `Manager::getOperatorList()` re-dispatches it
	 * on every operator-list read rather than caching a boot-time
	 * registration, so calling `IManager::registerOperation()` directly here
	 * would not survive across requests.
	 *
	 * Feature-detected via {@see self::appEnabledForAnyone()} ('workflowengine'),
	 * mirroring the Tables/Forms gate in {@see registerNextcloudEventTriggers()}:
	 * when disabled, no registration occurs and nothing is logged (a disabled
	 * `workflowengine` app is a normal state, not a fault). The `IAppManager`
	 * resolution and feature-detection check are wrapped in
	 * `try/catch (\Throwable)`; on failure this degrades to "WorkflowEngine
	 * operations unavailable this boot" (a warning-level log, register
	 * nothing) rather than throwing into `Application::register()`.
	 *
	 * @param IRegistrationContext $context Registration context (unused — kept for
	 *                                      signature symmetry with {@see registerNextcloudEventTriggers()}).
	 * @param IEventDispatcher $dispatcher The NC event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001
	 */
	private function registerWorkflowEngineOperations(IRegistrationContext $context, IEventDispatcher $dispatcher): void {
		try {
			$appManager = $this->getContainer()->get(\OCP\App\IAppManager::class);
			if ($this->appEnabledForAnyone(appManager: $appManager, appId: 'workflowengine') === true) {
				$dispatcher->addServiceListener(
					eventName: RegisterOperationsEvent::class,
					className: RegisterOperationsListener::class
				);
			}
		} catch (\Throwable $e) {
			// IAppManager not resolvable this early on some SAPIs — degrade to
			// "WorkflowEngine operations unavailable this boot" rather than
			// crashing app registration; every other capability is unaffected.
			try {
				$this->getContainer()->get(\Psr\Log\LoggerInterface::class)->warning(
					'integriq: could not feature-detect workflowengine for flow-workflowengine-integration — ' . $e->getMessage()
				);
			} catch (\Throwable) {
				// Logger unavailable, ignore.
			}
		}//end try

	}//end registerWorkflowEngineOperations()

	/**
	 * Wire the OpenRegister AppHost declarative observability engine.
	 *
	 * ADR-040 / ADR-006. Integriq adopts OpenRegister's AppHost
	 * observability engine instead of hand-writing its `/api/health` +
	 * `/api/metrics` controllers. The `metrics#index` and `health#index`
	 * route names (URLs `/api/metrics`, `/api/health`) are aliased here at
	 * the engine-owned generic controllers — built with `appName` =
	 * `integriq` so the engine reads THIS app's manifest
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
	 * NOTE: the boilerplate half of the AppHost adoption is now PARTIALLY
	 * adopted — see {@see registerAppHostBoilerplate()} for the per-user
	 * Preferences controller. The remaining bespoke plumbing (SPA/UiController
	 * with its permissive `connect-src *` CSP, the domain SettingsController
	 * `rebase` action + register.d-merging SettingsService, and the
	 * IntegriqAdmin AdminSettings/Section) is intentionally KEPT bespoke
	 * because each diverges behaviourally from the manifest-driven generics
	 * (see that method's docblock for the per-class rationale).
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */

	/**
	 * Register Integriq's contributed flow nodes with OpenRegister's flow engine.
	 *
	 * Feature-detected on the OR flow engine being present: without it this is a
	 * no-op, so Integriq still boots on an instance whose OpenRegister
	 * predates the flow engine.
	 *
	 * @param IEventDispatcher $dispatcher The NC event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function registerFlowNodes(IEventDispatcher $dispatcher): void {
		// Deliberately NOT guarded on the OR event class existing.
		//
		// The guard that used to stand here ran during register(), and at that
		// point OpenRegister's classes are not autoloadable yet — apps are
		// registered in an order that puts `integriq` before
		// `openregister`. So `class_exists()` answered FALSE on a perfectly
		// healthy instance and this returned early: the nodes never registered,
		// `source-call` and `synchronization-run` were absent from the palette,
		// and a flow naming either failed only when it RAN. Verified on a clean
		// install — the guard logged `class_exists at register(): false`, and
		// removing it took the registry from 10 nodes to 12.
		//
		// Dropping it is safe, which is why the guard was never buying anything:
		// `::class` resolves to a string and does not autoload, and
		// addServiceListener() is lazy — FlowNodeListener is only constructed if
		// the event actually fires, which can only happen when OpenRegister is
		// present and dispatching it. On an instance whose OpenRegister predates
		// the flow engine, nothing dispatches and this stays inert, which is
		// exactly the resilience the guard was reaching for.
		$dispatcher->addServiceListener(
			eventName: \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
			className: \OCA\Integriq\Flow\FlowNodeListener::class
		);

		// Same shape, same reasoning, for mapping: the transformation engine is
		// OpenRegister's, but three Twig functions need services only this app
		// has — callSource (CallService) and the two synchronisation-contract
		// id lookups. Contributing them keeps the dependency pointing the right
		// way; OpenRegister must load on an instance without Integriq.
		$dispatcher->addServiceListener(
			eventName: \OCA\OpenRegister\Service\RegisterMappingFunctionsEvent::class,
			className: \OCA\Integriq\Listener\MappingFunctionRegistrationListener::class
		);

	}//end registerFlowNodes()

	/**
	 * Adopt the OpenRegister AppHost consumables for this app.
	 *
	 * WHY THE TWO `assert($appManager instanceof IAppManager)` LINES BELOW ARE NOT NOISE.
	 * `ContainerInterface::get()` returns `mixed`, so without a narrowing statement
	 * neither psalm nor phpstan knows what `$appManager` is, and the
	 * `isEnabledForAnyone()` calls in both factories are checked against NOTHING. That
	 * is precisely how the deprecated `isInstalled()` survived in these two closures
	 * while the two controller guards they mirror were migrated (#1174) — the analysers
	 * could not see a method call to complain about. Measured both ways: with the
	 * assert, a deliberately misspelt method name is reported as "Call to an undefined
	 * method OCP\App\IAppManager::…" and phpstan exits 1; without it, phpstan is silent
	 * and exits 0.
	 *
	 * An inline `@var` is NOT an alternative here. This repo's phpcs standard rejects
	 * inline doc-block comments ("Inline doc block comments are not allowed") and
	 * mandates the single-star block-comment form, while phpstan reads `@var` ONLY from
	 * a double-star doc block — verified on a probe file: the same annotation written
	 * single-star bound nothing and the misspelt call went unreported. So the
	 * phpcs-legal form of that annotation reads as a type declaration and performs no
	 * check whatsoever.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	private function registerAppHostObservability(IRegistrationContext $context): void {
		// Build the thin integriq HealthController subclass (URL
		// /api/health, route name health#index — both unchanged) with the
		// engine collaborators resolved from OpenRegister's app container,
		// scoped to this app's manifest via appName.
		$context->registerService(
			HealthController::class,
			static function (ContainerInterface $c) {
				$appManager = $c->get(\OCP\App\IAppManager::class);
				// See registerAppHostObservability()'s docblock: this assert() is
				// load-bearing, it is what makes the isEnabledForAnyone() call below
				// statically checked at all.
				assert($appManager instanceof \OCP\App\IAppManager);

				// When OpenRegister is absent the engine delegate cannot be
				// built; pass null so HealthController returns a clean 503
				// naming the missing dependency (REQ-ADM-003) instead of a bare
				// DI 500. Building the delegate references OpenRegister classes,
				// so it is only done when OpenRegister is enabled.
				$delegate = null;
				if ($appManager->isEnabledForAnyone('openregister') === true) {
					// phpcs:ignore CustomSniffs.Nextcloud.NoLegacyServerAccessors.LegacyNamedAccessor -- cross-app DI container lookup; no \OCP\Server equivalent, still used by NC34 core (OCP\AppFramework\App).
					$orContainer = \OC::$server->getRegisteredAppContainer('openregister');
					$delegate = new \OCA\OpenRegister\AppHost\Controller\GenericHealthController(
						appName: self::APP_ID,
						request: $c->get(IRequest::class),
						manifestLoader: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
						executor: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor::class)
					);
				}

				return new HealthController(
					appName: self::APP_ID,
					request: $c->get(IRequest::class),
					appManager: $appManager,
					delegate: $delegate
				);
			}
		);

		// Build the thin integriq MetricsController (URL /api/metrics,
		// route name metrics#index — both unchanged) with the engine delegate
		// resolved from OpenRegister's app container, scoped to this app's
		// manifest via appName. Admin-only posture is engine-owned and
		// re-declared on the integriq method.
		$context->registerService(
			MetricsController::class,
			static function (ContainerInterface $c) {
				$appManager = $c->get(\OCP\App\IAppManager::class);
				// See registerAppHostObservability()'s docblock: this assert() is
				// load-bearing, it is what makes the isEnabledForAnyone() call below
				// statically checked at all.
				assert($appManager instanceof \OCP\App\IAppManager);

				// Mirrors the HealthController guard above. When OpenRegister
				// is absent the engine delegate cannot be built, so pass null
				// and let MetricsController return a clean 503 instead of a
				// bare DI 500 — getRegisteredAppContainer() throws for an app
				// that is not registered. Building the delegate references
				// OpenRegister classes, so it is only done when OpenRegister
				// is enabled.
				$delegate = null;
				if ($appManager->isEnabledForAnyone('openregister') === true) {
					// phpcs:ignore CustomSniffs.Nextcloud.NoLegacyServerAccessors.LegacyNamedAccessor -- cross-app DI container lookup; no \OCP\Server equivalent, still used by NC34 core (OCP\AppFramework\App).
					$orContainer = \OC::$server->getRegisteredAppContainer('openregister');
					$delegate = new \OCA\OpenRegister\AppHost\Controller\GenericMetricsController(
						appName: self::APP_ID,
						request: $c->get(IRequest::class),
						manifestLoader: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
						engine: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\MetricsEngine::class)
					);
				}

				return new MetricsController(
					appName: self::APP_ID,
					request: $c->get(IRequest::class),
					appManager: $appManager,
					delegate: $delegate
				);
			}
		);

		// Provider escape hatch (REQ-PROM-011, retry-and-circuit-breaker-policies):
		// the per-Source circuit_breaker_state gauge needs each Source's OWN
		// field value (1/0), not a row count — declarative tableCount/objectCount
		// descriptors only aggregate counts. The `{"kind":"provider"}` metric
		// descriptor in src/manifest.json merges this provider's samples into
		// the /api/metrics response; the engine resolves it via this alias.
		$context->registerServiceAlias(
			IMetricsProvider::class . '::integriq',
			IntegriqMetricsProvider::class
		);
	}//end registerAppHostObservability()

	/**
	 * Adopt the AppHost boilerplate generics that are a clean, behaviour-preserving win.
	 *
	 * ADR-040. The per-user Preferences controller, the admin Settings/Section
	 * pair, and the ADR-023 action-matrix repair step are adopted here — each
	 * a one-line subclass of an OpenRegister AppHost generic, with the
	 * per-app collaborators (appId, section metadata, translated section name,
	 * app-scoped action-auth service) injected by the factories below.
	 * `OCA\Integriq\Controller\PreferencesController` was a byte-for-byte
	 * copy of OpenRegister's engine-owned {@see GenericPreferencesController}
	 * (same `pref_` user-value namespace, same `[a-z0-9-]{0,64}` key
	 * sanitisation, same `{value: string|null}` envelope, same per-session
	 * user scoping), so it is deleted and the `/api/preferences/{key}`
	 * GET/PUT routes now resolve to the leaf-namespaced class
	 * `OCA\Integriq\AppHost\Controller\GenericPreferencesController`,
	 * registered here as a service that constructs the OpenRegister generic with
	 * `appName = integriq` injected — so every user value stays scoped to
	 * THIS app's namespace, never OpenRegister's. URLs + JSON contract unchanged;
	 * the engine owns the (identical, user-scoped, no-IDOR) auth posture so the
	 * leaf can never drift it. Mirrors opencatalogi's just-merged adoption.
	 *
	 * `Settings\IntegriqAdmin` / `Sections\IntegriqAdmin` /
	 * `Repair\InitializeRegister` / `Repair\InitializeActions` were DEFERRED in
	 * the original adopt-apphost proposal because `GenericAdminSettings`,
	 * `GenericSettingsSection`, `GenericInitializeSettings` and
	 * `GenericInitializeActions` did not yet exist in OpenRegister. They now
	 * do. `IntegriqAdmin` (Settings + Section) and `InitializeActions`
	 * are adopted here, each behaviour-preserving:
	 *   - `getAuthorizedAppConfig()` on the new `GenericAdminSettings`-backed
	 *     `IntegriqAdmin` returns `[]`, byte-identical to the bespoke
	 *     implementation it replaces — every one of the ~30
	 *     `#[AuthorizedAdminSetting(IntegriqAdmin::class)]`-gated
	 *     controller methods across the app keeps its exact fail-closed
	 *     (full-admin-only) posture. `getSection()`/`getPriority()` are pinned
	 *     to the pre-adoption values (`integriq`, `10`) below. The
	 *     dead `mySetting` template parameter (never read by
	 *     `templates/settings/admin.php`) is dropped; a real `isUpToDate`
	 *     signal is gained.
	 *   - The Section's display name stays translated: the factory below
	 *     resolves integriq's own scoped `IL10N` and calls
	 *     `->t('Integriq')` before constructing the generic section
	 *     (which itself has no l10n hook — it stores a plain string), so
	 *     `getName()` keeps returning the localised string. Icon file and
	 *     priority (`app-dark.svg`, `97`) are pinned to the pre-adoption values.
	 *   - `InitializeActions` reads/writes the identical `lib/actions.seed.json`
	 *     file and the identical `IAppConfig` storage key (`actions`, ADR-023)
	 *     as the bespoke step it replaces — see that class's own docblock.
	 *
	 * `InitializeRegister` stays bespoke — NOT adopted, unlike its
	 * `InitializeActions` sibling. Its ADR-037 `deepMergeConfig()`
	 * fragment-union algorithm is pinned by reflection-based unit tests
	 * (`RegisterFragmentMergeTest`, `EudiRegisterFragmentTest`,
	 * `HitlApprovalRegisterFragmentTest`) that assert against
	 * `InitializeRegister::deepMergeConfig()` directly; the equivalent logic
	 * in OpenRegister's `AppHostSettingsService` is `private` and lives in a
	 * different class, so converting would either break that fragment-merge
	 * coverage or require duplicating the algorithm as a test stub — deferred
	 * rather than trading away a safety net for a pure refactor.
	 *
	 * Defect #3 from the original proposal (repair steps wired nowhere) was
	 * already fixed independently of this adoption — `appinfo/info.xml`
	 * `<repair-steps><post-migration>` has referenced both `InitializeRegister`
	 * and `InitializeActions` since before this change.
	 *
	 * Lazy + fail-soft: every factory closure references the OR generic only
	 * when the corresponding route/settings-page/repair-step is dispatched, so
	 * a disabled OpenRegister never fatals app bootstrap (ADR-022).
	 *
	 * Deliberately NOT adopted (kept bespoke — would CHANGE behaviour):
	 *   - Dashboard / UiController SPA shell: its `makeSpaResponse()` sets a
	 *     permissive `connect-src *` ContentSecurityPolicy so the SPA can call
	 *     externally-configured source APIs. The engine GenericDashboardController
	 *     returns a plain `TemplateResponse` with NO custom CSP and no hook to
	 *     override one — adopting it would silently tighten the CSP and break
	 *     outbound source calls. The bespoke UiController + every `ui#*`/
	 *     catch-all route therefore stay.
	 *   - SettingsController: only the integriq-specific `rebase` action
	 *     (recompute log-retention deletion timestamps) survives chain-C; the
	 *     generic SettingsController does index/create/load with force-reimport
	 *     semantics and has no `rebase` equivalent.
	 *   - InitializeRegister (see above — pinned fragment-merge test coverage).
	 *   - The source/mapping/synchronization domain engine, PDOK/Berichtenbox
	 *     adapters, and event-retry/dead-letter/webhook plumbing.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	private function registerAppHostBoilerplate(IRegistrationContext $context): void {
		// Bind the AppHost preferences controller (which does not physically
		// exist in this app — same pattern as the Health/Metrics observability
		// aliases) to the OpenRegister generic, with appName=integriq so
		// the `pref_` user-value namespace is scoped to this app.
		//
		// The service key MUST be the STANDARD `OCA\Integriq\Controller\…`
		// namespace, because that is the class name NC's App::main synthesises
		// from the plain `genericPreferences#…` route name (see the matching
		// note in appinfo/routes.php). A non-standard namespace key (e.g.
		// `…\AppHost\Controller\…`) is never looked up by the router, so every
		// request 503s with "App controller is not enabled".
		$context->registerService(
			'OCA\\Integriq\\Controller\\GenericPreferencesController',
			static function (ContainerInterface $c) {
				return new GenericPreferencesController(
					appName: self::APP_ID,
					request: $c->get(IRequest::class),
					config: $c->get(\OCP\IConfig::class),
					userSession: $c->get(\OCP\IUserSession::class)
				);
			}
		);

		// App-scoped generic action-auth service, used only to seed the
		// matrix in InitializeActions below (reads/writes the identical
		// `actions` IAppConfig key, under the `integriq` app id, that
		// the still-bespoke `ActionAuthService` enforces against — see that
		// repair step's docblock). Not aliased under the bespoke
		// `ActionAuthService` class name — controllers keep injecting the
		// bespoke service for their in-request RBAC checks.
		$context->registerService(
			GenericActionAuthService::class,
			static function (ContainerInterface $c) {
				return new GenericActionAuthService(
					appId: self::APP_ID,
					appConfig: $c->get(\OCP\IAppConfig::class),
					groupManager: $c->get(\OCP\IGroupManager::class)
				);
			}
		);

		$this->registerAppHostAdminSettings(context: $context);

		// InitializeRegister (register.d fragment-merge repair step) stays
		// bespoke — NOT adopted here, unlike InitializeActions below. Its
		// ADR-037 `deepMergeConfig()` fragment-union algorithm is pinned by
		// reflection-based unit tests (RegisterFragmentMergeTest,
		// EudiRegisterFragmentTest, HitlApprovalRegisterFragmentTest) that
		// assert against `InitializeRegister::deepMergeConfig()` directly;
		// the equivalent logic in OpenRegister's `AppHostSettingsService` is
		// `private` and lives in a different class, so converting to
		// `GenericInitializeSettings` would either break that fragment-merge
		// coverage or require duplicating the algorithm as a test stub —
		// deferred rather than trading away a safety net for a pure refactor.
		// It keeps resolving via plain autowiring (its own constructor is
		// unchanged), so no factory is registered for it here.
		$context->registerService(
			InitializeActions::class,
			/**
			 * Build the leaf InitializeActions repair step.
			 *
			 * @param ContainerInterface $c App-scoped DI container.
			 *
			 * @return InitializeActions
			 *
			 * @psalm-suppress TooManyArguments see IntegriqAdminSettings above.
			 */
			static function (ContainerInterface $c) {
				return new InitializeActions(
					appId: self::APP_ID,
					actionAuth: $c->get(GenericActionAuthService::class),
					appManager: $c->get(\OCP\App\IAppManager::class),
					logger: $c->get(\Psr\Log\LoggerInterface::class)
				);
			}
		);

	}//end registerAppHostBoilerplate()

	/**
	 * Bind the leaf `IntegriqAdmin` settings form + section class names to
	 * the AppHost `GenericAdminSettings`/`GenericSettingsSection` generics.
	 *
	 * Split out of {@see registerAppHostBoilerplate()} to keep both methods
	 * under the project's method-length threshold. See that method's docblock
	 * for the full behaviour-preservation rationale.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	private function registerAppHostAdminSettings(IRegistrationContext $context): void {
		// Admin settings form + section: pinned to the pre-adoption metadata
		// (section id `integriq`, priority 10 / 97, icon `app-dark.svg`).
		$context->registerService(
			IntegriqAdminSettings::class,
			/**
			 * Build the leaf IntegriqAdmin settings form.
			 *
			 * IntegriqAdminSettings extends OpenRegister's
			 * GenericAdminSettings (peer app, not in vendor — see psalm.xml's
			 * UndefinedClass allowlist for the same class); Psalm cannot
			 * resolve the inherited constructor and treats the subclass as
			 * argument-less.
			 *
			 * @param ContainerInterface $c App-scoped DI container.
			 *
			 * @return IntegriqAdminSettings
			 *
			 * @psalm-suppress TooManyArguments
			 */
			static function (ContainerInterface $c) {
				return new IntegriqAdminSettings(
					appId: self::APP_ID,
					sectionId: 'integriq',
					priority: 10,
					appManager: $c->get(\OCP\App\IAppManager::class),
					initialState: $c->get(\OCP\AppFramework\Services\IInitialState::class),
					appConfig: $c->get(\OCP\IAppConfig::class)
				);
			}
		);

		$context->registerService(
			IntegriqAdminSection::class,
			/**
			 * Build the leaf IntegriqAdmin settings section.
			 *
			 * Resolves integriq's own scoped IL10N and translates the
			 * section name BEFORE constructing the generic section, which has
			 * no l10n hook of its own (see class docblock).
			 *
			 * @param ContainerInterface $c App-scoped DI container.
			 *
			 * @return IntegriqAdminSection
			 *
			 * @psalm-suppress TooManyArguments see IntegriqAdminSettings above.
			 */
			static function (ContainerInterface $c) {
				$name = $c->get(\OCP\IL10N::class)->t('Integriq');

				return new IntegriqAdminSection(
					sectionId: 'integriq',
					name: $name,
					appId: self::APP_ID,
					iconFile: 'app-dark.svg',
					priority: 97,
					urlGenerator: $c->get(\OCP\IURLGenerator::class)
				);
			}
		);
	}//end registerAppHostAdminSettings()

	/**
	 * Soft pre-flight check: warn when the legacy→OpenRegister storage migration has
	 * not yet run.
	 *
	 * Chain C removed every `lib/Db/*Mapper.php` and entity class; all domain data
	 * now lives in OpenRegister objects (ADR-001). The connector-specific services
	 * that inject OpenRegister's `ObjectService` only have data to operate on once
	 * the one-shot migrator has copied every legacy row across and flipped the
	 * `integriq.storage_migrated` IAppConfig flag to `'true'`.
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
	 * `INTEGRIQ_SKIP_STORAGE_MIGRATED_ASSERT=1`, and soft-skipped if `IAppConfig`
	 * cannot be resolved during very early bootstrap.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md#requirement-application-php-di-bindings-must-be-updated
	 */
	private function assertStorageMigrated(): void {
		/* CI / test bypass — no real upgrade has run in those environments.
		   `INTEGRIQ_SKIP_STORAGE_MIGRATED_ASSERT` is the CANONICAL name. The
		   `OPENCONNECTOR_` spelling is still honoured because a renamed env var
		   is the same silent-default trap as a renamed config key: this bypass
		   was documented under the old name (CHANGELOG 0.2.x and the
		   direct-or-usage spec), so a compose file, Helm chart, CI job or
		   developer shell profile out there plausibly still sets it. Reading
		   only the new name would quietly stop honouring theirs and start
		   logging the very warning they had deliberately silenced.
		   The old spelling can be dropped once the fleet rename is complete and
		   no environment sets it any more — tracked with the rest of the
		   openconnector -> integriq cleanup. */
		if (getenv('INTEGRIQ_SKIP_STORAGE_MIGRATED_ASSERT') !== false) {
			return;
		}

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
				'integriq: legacy storage has not been migrated to OpenRegister. '
				. 'Run "occ integriq:migrate-storage" to materialise the register and '
				. 'copy legacy rows. Connector services will operate on an empty register '
				. 'until then.'
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
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public function boot(IBootContext $context): void {
		$this->registerIntegrationProviders(context: $context);

	}//end boot()

	/**
	 * Register integriq-side IntegrationProviders with OR's IntegrationRegistry.
	 *
	 * Per OR's pluggable-integration-registry spec (AD-1), apps register their
	 * providers at boot — OR's registry is a shared per-request service so all
	 * apps see the same instance.
	 *
	 * Currently registered:
	 *   - SynchronizationContractProvider — surfaces SyncContract leaves on the
	 *     OR objects they synchronise (GH #824).
	 *   - AzureVirtualDesktopAdapter, SharePointOnlineAdapter, Microsoft365Adapter,
	 *     S3Adapter — one reference adapter per connector-category spec
	 *     (endpoint-workspace, document-cms, saas-productivity, data-infra),
	 *     proving the `AbstractCategoryAdapterProvider` registration pattern
	 *     (openspec/changes/connector-category-adapter-scaffolding).
	 *
	 * Soft-fails if OR's IntegrationRegistry isn't available (e.g. when
	 * integriq is loaded but openregister isn't enabled yet) so boot
	 * doesn't crash on a stale install.
	 *
	 * @param IBootContext $context Boot context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-integrationprovider-boot-time-registration-with-or-integrationregistry-req-002
	 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	private function registerIntegrationProviders(IBootContext $context): void {
		if (class_exists(IntegrationRegistry::class) === false) {
			return;
		}

		try {
			$container = $context->getServerContainer();
			$registry = $container->get(IntegrationRegistry::class);
			$registry->addProvider($container->get(SynchronizationContractProvider::class));
			$registry->addProvider($container->get(AzureVirtualDesktopAdapter::class));
			$registry->addProvider($container->get(SharePointOnlineAdapter::class));
			$registry->addProvider($container->get(Microsoft365Adapter::class));
			$registry->addProvider($container->get(S3Adapter::class));
		} catch (\Throwable $e) {
			// Don't crash boot — log and continue. The provider just won't appear
			// in object sidebars on this instance until the registry resolves.
			try {
				$context->getServerContainer()
					->get('Psr\Log\LoggerInterface')
					->warning(
						'integriq: failed to register IntegrationProviders with OR — ' . $e->getMessage(),
						['exception' => $e]
					);
			} catch (\Throwable) {
				// Logger unavailable, ignore.
			}
		}//end try

	}//end registerIntegrationProviders()
}//end class
