<?php

/**
 * Integriq RepairRegistrar.
 *
 * Registers the repair steps that need a factory.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
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

namespace OCA\Integriq\Repair;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the services the Repair component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class RepairRegistrar {
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
		$context->registerServiceAlias(InitializeRegisterInterface::class, InitializeRegister::class);
		$this->registerInitializeActions(context: $context);
	}//end register()

	/**
	 * Registers the InitializeActions repair step.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerInitializeActions(IRegistrationContext $context): void {
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
					appId: AppIdentity::APP_ID,
					actionAuth: $c->get(GenericActionAuthService::class),
					appManager: $c->get(\OCP\App\IAppManager::class),
					logger: $c->get(\Psr\Log\LoggerInterface::class)
				);
			}
		);
	}//end registerInitializeActions()
}
