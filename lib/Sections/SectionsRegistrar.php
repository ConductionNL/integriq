<?php

/**
 * Integriq SectionsRegistrar.
 *
 * Registers the admin settings section.
 *
 * @category Sections
 * @package  OCA\Integriq\Sections
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

namespace OCA\Integriq\Sections;

use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Sections\IntegriqAdmin as IntegriqAdminSection;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the services the Sections component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class SectionsRegistrar {
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
		$this->registerAdminSection(context: $context);
	}//end register()

	/**
	 * Registers the admin settings section.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerAdminSection(IRegistrationContext $context): void {
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
					appId: AppIdentity::APP_ID,
					iconFile: 'app-dark.svg',
					priority: 97,
					urlGenerator: $c->get(\OCP\IURLGenerator::class)
				);
			}
		);
	}//end registerAdminSection()
}
