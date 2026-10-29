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
use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Registers the services the Controller component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
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
		$this->registerPreferencesController(context: $context);
	}//end register()

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
