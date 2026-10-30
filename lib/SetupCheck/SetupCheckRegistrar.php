<?php

/**
 * Integriq SetupCheckRegistrar.
 *
 * Registers the setup checks of the SetupCheck component.
 *
 * @category SetupCheck
 * @package  OCA\Integriq\SetupCheck
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

namespace OCA\Integriq\SetupCheck;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the SetupCheck component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class SetupCheckRegistrar {
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
		$this->registerSetupChecks(context: $context);
	}//end register()

	/**
	 * Registers the setup checks, in the order the admin page lists them.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSetupChecks(IRegistrationContext $context): void {
		// Fail-loud admin signal when the required OpenRegister app is absent.
		// The check uses IAppManager only (no OCA\OpenRegister\* reference) so it
		// is safe to run while OpenRegister is disabled (REQ-ADM-003).
		$context->registerSetupCheck(OpenRegisterDependencyCheck::class);
		// Since gate 23 every credentialed inbound call is checked by
		// OpenRegister; an OpenRegister older than 2.1.35 refuses them all
		// with 401, and info.xml cannot say so. This check does.
		$context->registerSetupCheck(OpenRegisterEntryPointsCheck::class);
		$context->registerSetupCheck(DigitalPostAccountCheck::class);
		$context->registerSetupCheck(BerichtenboxCheck::class);
	}//end registerSetupChecks()
}
