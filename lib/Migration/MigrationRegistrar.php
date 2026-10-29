<?php

/**
 * Integriq MigrationRegistrar.
 *
 * Registers the services of the Migration component.
 *
 * @category Migration
 * @package  OCA\Integriq\Migration
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

namespace OCA\Integriq\Migration;

use OCA\Integriq\Migration\Source\FileMigrationSource;
use OCA\Integriq\Migration\Source\RedmineMigrationSource;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the Migration component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class MigrationRegistrar {
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
		$this->registerSourceRegistry(context: $context);
	}//end register()

	/**
	 * Registers the migration source registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSourceRegistry(IRegistrationContext $context): void {
		// The migration source adapters. A second incumbent is a class beside
		// the Redmine one and a line here: no engine change, no consumer change.
		$context->registerService(
			MigrationSourceRegistry::class,
			static function ($c): MigrationSourceRegistry {
				return new MigrationSourceRegistry(
					adapters: [
						$c->get(FileMigrationSource::class),
						$c->get(RedmineMigrationSource::class),
					]
				);
			}
		);
	}//end registerSourceRegistry()
}
