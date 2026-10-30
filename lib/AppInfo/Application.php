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
use OCA\Integriq\Capabilities;
use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Outbound\OutboundRegistrar;
use OCA\Integriq\Expression\ExpressionRegistrar;
use OCA\Integriq\Flow\FlowRegistrar;
use OCA\Integriq\Directory\DirectoryRegistrar;
use OCA\Integriq\Connectors\ConnectorsRegistrar;
use OCA\Integriq\Intake\IntakeRegistrar;
use OCA\Integriq\Observability\ObservabilityRegistrar;
use OCA\Integriq\Auth\AuthRegistrar;
use OCA\Integriq\Adapters\AdaptersRegistrar;
use OCA\Integriq\Bridge\BridgeRegistrar;
use OCA\Integriq\Broker\BrokerRegistrar;
use OCA\Integriq\Controller\HealthController;
use OCA\Integriq\Controller\MetricsController;
use OCA\Integriq\EventListener\EventListenerRegistrar;
use OCA\Integriq\Gateway\GatewayRegistrar;
use OCA\Integriq\Mcp\McpRegistrar;
use OCA\Integriq\Migration\MigrationRegistrar;
use OCA\Integriq\Notification\NotificationRegistrar;
use OCA\Integriq\PropertySource\PropertySourceRegistrar;
use OCA\Integriq\Repair\RepairRegistrar;
use OCA\Integriq\Rule\RuleRegistrar;
use OCA\Integriq\Sections\SectionsRegistrar;
use OCA\Integriq\Settings\SettingsRegistrar;
use OCA\Integriq\SetupCheck\SetupCheckRegistrar;
use OCA\Integriq\Sources\SourcesRegistrar;
use OCA\Integriq\Service\ServiceRegistrar;
use OCA\Integriq\WorkflowEngine\RegisterOperationsListener;
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use Psr\Container\ContainerInterface;

/**
 * Bootstrap entry point for the Integriq app.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)           One small private method per registration group.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Summed over those methods; each one stays simple.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
 */
class Application extends App implements IBootstrap {

	/**
	 * App identifier.
	 *
	 * @var string
	 */
	public const APP_ID = AppIdentity::APP_ID;

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
		// Coordinator::registerApps() registers each app's autoloader then calls
		// its register() one app at a time, so this method runs BEFORE
		// OCA\OpenRegister\ is autoloadable (this app sorts before
		// `openregister`). Any AppHost reference here — including a
		// class_exists() probe — therefore answers FALSE on a perfectly healthy
		// instance. Put OpenRegister's prefix on the autoloader ourselves, via
		// public API only (Nextcloud 35 removed \OC_App::registerAutoloading()).
		// Deliberately NOT IAppManager::loadApp(), which would mark OpenRegister
		// loaded and boot it before its own register() had run. Returns false
		// (never throws) when OpenRegister is absent or disabled — fall through
		// to the degraded path.
		OpenRegisterAutoloader::register();

		$this->assertStorageMigrated();

		(new ServiceRegistrar())->register(context: $context);
		(new OutboundRegistrar())->register(context: $context);
		(new FlowRegistrar())->register(context: $context);
		(new DirectoryRegistrar())->register(context: $context);
		(new ConnectorsRegistrar())->register(context: $context);
		(new IntakeRegistrar())->register(context: $context);
		(new ObservabilityRegistrar())->register(context: $context);
		(new AuthRegistrar())->register(context: $context);
		(new AdaptersRegistrar())->register(context: $context);
		(new EventListenerRegistrar())->register(context: $context);
		(new SourcesRegistrar())->register(context: $context);
		(new RuleRegistrar())->register(context: $context);
		(new PropertySourceRegistrar())->register(context: $context);
		(new BrokerRegistrar())->register(context: $context);
		(new MigrationRegistrar())->register(context: $context);
		(new GatewayRegistrar())->register(context: $context);
		(new McpRegistrar())->register(context: $context);
		$this->registerPreferencesController(context: $context);
		(new SettingsRegistrar())->register(context: $context);
		(new SectionsRegistrar())->register(context: $context);
		(new RepairRegistrar())->register(context: $context);
		(new SetupCheckRegistrar())->register(context: $context);
		(new NotificationRegistrar())->register(context: $context);
		(new BridgeRegistrar())->register(context: $context);
		(new ExpressionRegistrar())->register(context: $context);

		/*
		 * Event dispatcher registration.
		 *
		 * @var IEventDispatcher $dispatcher
		 */

		$dispatcher = $this->getContainer()->get(IEventDispatcher::class);
		$this->registerListeners(context: $context, dispatcher: $dispatcher);

		$this->registerAppHostObservability(context: $context);
		$this->registerAppHostBoilerplate(context: $context);

		// Dashboard-http-datasource: advertise the capability so a leaf
		// dashboard/widget host (LaunchPad's live-data-tile-widget) can probe
		// for the resolve façade via the OCS capabilities document.
		$context->registerCapability(Capabilities::class);

		$this->registerRegisterSlugResolver(context: $context);
	}//end register()

	/**
	 * Registers the event listeners, in the order they must run.
	 *
	 * @param IRegistrationContext $context The registration context.
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerListeners(IRegistrationContext $context, IEventDispatcher $dispatcher): void {
		$listeners = new EventListenerRegistrar();
		$listeners->registerListeners(dispatcher: $dispatcher);

		// Nextcloud-core-event triggers (nextcloud-event-hub). Each family
		// normalizes its NC event into the SAME `event` CloudEvents envelope
		// shape the OR-object pipeline above already uses, then hands off to
		// the same processEvent/deliverMessage/retry/dead-letter machinery
		// (EventService::handleNextcloudEvent) — see design.md Decision 2.
		$this->registerNextcloudEventTriggers(listeners: $listeners, dispatcher: $dispatcher);

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
		(new FlowRegistrar())->registerListeners(dispatcher: $dispatcher);
		$this->registerMappingFunctions(dispatcher: $dispatcher);

		$this->registerIntegrationScript(dispatcher: $dispatcher);
	}//end registerListeners()

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
	}//end registerAppHostObservability()

	/**
	 * Registers the AppHost preferences controller.
	 *
	 * It stays in lib/AppInfo: the controller is bound in the container rather
	 * than shipped as a class, and the route-reachability gate looks for such
	 * bindings under lib/AppInfo only.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerPreferencesController(IRegistrationContext $context): void {
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
					appName: AppIdentity::APP_ID,
					request: $c->get(IRequest::class),
					config: $c->get(\OCP\IConfig::class),
					userSession: $c->get(\OCP\IUserSession::class)
				);
			}
		);
	}//end registerPreferencesController()

	/**
	 * Registers the integration init script on every full-page render.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerIntegrationScript(IEventDispatcher $dispatcher): void {
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
	}//end registerIntegrationScript()

	/**
	 * Registers the register slug resolver alias.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerRegisterSlugResolver(IRegistrationContext $context): void {
		// Which slug this instance's registers actually answer to.
		//
		// Register slugs live in `openregister_registers`, and nine fleet apps
		// ship a repair step that renames theirs. This app's own
		// {@see \OCA\Integriq\Repair\MigrateRegisterSlug} is one of them. The
		// step runs per instance, so `openconnector` and `integriq` are both
		// live across the estate on any given day, and a literal is wrong on
		// whichever half has not caught up. The old-slug case is the quiet one:
		// OpenRegister finds no register row, matches no objects, and returns an
		// empty set that is byte-for-byte what a healthy empty register returns.
		// No exception, no 404, no log line.
		//
		// An ALIAS rather than a factory, and the difference matters here.
		// Both sides are strings, so neither triggers an autoload while
		// `register()` runs, which is the constraint the autoloading prelude at
		// the top of this method exists for: apps register in sorted order and
		// `integriq` sorts before `openregister`, so a factory body that named
		// the concrete class would be evaluated lazily but the class reference
		// in a closure signature would not. The alias is resolved on first
		// injection, by which time OpenRegister is autoloadable.
		//
		// Resolved live in four leaf containers on a running instance before
		// this was written, not assumed from the registration: OpenRegister
		// registers the resolver in its OWN container, so nothing of that
		// registration reaches here. What reaches here is this alias plus
		// autowiring of the concrete class.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\OpenRegister\Service\RegisterSlugResolver'
		);
	}//end registerRegisterSlugResolver()

	/**
	 * Register the feature-detected `nextcloud-event-triggers` listeners (Tables and Forms).
	 *
	 * The file and calendar listeners are registered by
	 * {@see EventListenerRegistrar::registerListeners()}, unconditionally (Decision 1):
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
	 * @param EventListenerRegistrar $listeners The registrar of the listener classes.
	 * @param IEventDispatcher $dispatcher The NC event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-file-events-must-be-normalized-to-cloudevents-req-001
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
	 */
	private function registerNextcloudEventTriggers(EventListenerRegistrar $listeners, IEventDispatcher $dispatcher): void {
		// Tables (REQ-003) — feature-detected.
		try {
			$appManager = $this->getContainer()->get(\OCP\App\IAppManager::class);
			if ($this->appEnabledForAnyone(appManager: $appManager, appId: 'tables') === true) {
				$listeners->registerTablesListeners(dispatcher: $dispatcher);
			}

			// Forms (REQ-004) — feature-detected.
			if ($this->appEnabledForAnyone(appManager: $appManager, appId: 'forms') === true) {
				$listeners->registerFormsListeners(dispatcher: $dispatcher);
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
	 *                                      signature symmetry with the other registration methods).
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
	 * Contribute this app's mapping functions to OpenRegister's transformation engine.
	 *
	 * @param IEventDispatcher $dispatcher The NC event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	private function registerMappingFunctions(IEventDispatcher $dispatcher): void {
		// Same shape, same reasoning, for mapping: the transformation engine is
		// OpenRegister's, but three Twig functions need services only this app
		// has — callSource (CallService) and the two synchronisation-contract
		// id lookups. Contributing them keeps the dependency pointing the right
		// way; OpenRegister must load on an instance without Integriq.
		$dispatcher->addServiceListener(
			eventName: \OCA\OpenRegister\Service\RegisterMappingFunctionsEvent::class,
			className: \OCA\Integriq\Listener\MappingFunctionRegistrationListener::class
		);

	}//end registerMappingFunctions()

	/**
	 * Adopt the AppHost boilerplate generics that are a clean, behaviour-preserving win.
	 *
	 * The factories of the adopted classes live in their components' registrars
	 * ({@see registerPreferencesController()} for the preferences controller, {@see \OCA\Integriq\Settings\SettingsRegistrar},
	 * {@see \OCA\Integriq\Sections\SectionsRegistrar} and {@see \OCA\Integriq\Repair\RepairRegistrar}).
	 * This method keeps the factory of the OpenRegister action-auth service they share.
	 *
	 * ADR-040. The per-user Preferences controller, the admin Settings/Section
	 * pair, and the ADR-023 action-matrix repair step are adopted here — each
	 * a one-line subclass of an OpenRegister AppHost generic, with the
	 * per-app collaborators (appId, section metadata, translated section name,
	 * app-scoped action-auth service) injected by those factories.
	 * `OCA\Integriq\Controller\PreferencesController` was a byte-for-byte
	 * copy of OpenRegister's engine-owned {@see GenericPreferencesController}
	 * (same `pref_` user-value namespace, same `[a-z0-9-]{0,64}` key
	 * sanitisation, same `{value: string|null}` envelope, same per-session
	 * user scoping), so it is deleted and the `/api/preferences/{key}`
	 * GET/PUT routes now resolve to the leaf-namespaced class
	 * `OCA\Integriq\AppHost\Controller\GenericPreferencesController`,
	 * registered as a service that constructs the OpenRegister generic with
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


	}//end registerAppHostBoilerplate()

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
		(new ServiceRegistrar())->boot(context: $context);

	}//end boot()

}//end class
