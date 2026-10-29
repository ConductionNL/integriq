<?php

/**
 * Integriq ControllerRegistrar.
 *
 * Registers the controllers that need a factory.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\OpenRegister\AppHost\Controller\GenericHealthController;
use OCA\OpenRegister\AppHost\Controller\GenericMetricsController;
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Registers the services the Controller component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 *
 * Naming every class it wires is what a registrar is for, so the coupling
 * between objects is high by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class ControllerRegistrar {
	/**
	 * Register this component's services on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's services and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerObservabilityControllers(context: $context);
		$this->registerPreferencesController(context: $context);
	}//end register()

	/**
	 * Registers the health and metrics controllers over the OpenRegister AppHost engine.
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
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	private function registerObservabilityControllers(IRegistrationContext $context): void {
		// Build the thin integriq HealthController subclass (URL
		// /api/health, route name health#index — both unchanged) with the
		// engine collaborators resolved from OpenRegister's app container,
		// scoped to this app's manifest via appName.
		$context->registerService(
			HealthController::class,
			static function (ContainerInterface $c) {
				$appManager = $c->get(\OCP\App\IAppManager::class);
				// See this method's docblock: this assert() is
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
					$delegate = new GenericHealthController(
						appName: AppIdentity::APP_ID,
						request: $c->get(IRequest::class),
						manifestLoader: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
						executor: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor::class)
					);
				}

				return new HealthController(
					appName: AppIdentity::APP_ID,
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
				// See this method's docblock: this assert() is
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
					$delegate = new GenericMetricsController(
						appName: AppIdentity::APP_ID,
						request: $c->get(IRequest::class),
						manifestLoader: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\ManifestLoader::class),
						engine: $orContainer->get(\OCA\OpenRegister\AppHost\Observability\MetricsEngine::class)
					);
				}

				return new MetricsController(
					appName: AppIdentity::APP_ID,
					request: $c->get(IRequest::class),
					appManager: $appManager,
					delegate: $delegate
				);
			}
		);
	}//end registerObservabilityControllers()

	/**
	 * Registers the AppHost preferences controller.
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
}
