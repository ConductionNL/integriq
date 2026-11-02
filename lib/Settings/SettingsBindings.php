<?php

/**
 * Integriq SettingsBindings.
 *
 * Registers the admin settings form.
 *
 * @category Settings
 * @package  OCA\Integriq\Settings
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

namespace OCA\Integriq\Settings;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Settings\IntegriqAdmin as IntegriqAdminSettings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the services the Settings component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class SettingsBindings {
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
		$this->registerAdminSettings(context: $context);
	}//end register()

	/**
	 * Registers the admin settings form, pinned to the pre-adoption metadata.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerAdminSettings(IRegistrationContext $context): void {
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
					appId: AppIdentity::APP_ID,
					sectionId: 'integriq',
					priority: 10,
					appManager: $c->get(\OCP\App\IAppManager::class),
					initialState: $c->get(\OCP\AppFramework\Services\IInitialState::class),
					appConfig: $c->get(\OCP\IAppConfig::class)
				);
			}
		);
	}//end registerAdminSettings()
}
