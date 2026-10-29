<?php

/**
 * Integriq AuthRegistrar.
 *
 * Registers the container bindings the Auth component offers to the
 * rest of the app.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth
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

namespace OCA\Integriq\Auth;

use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Auth\Idp\IdpLoginServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Auth contract to the class that implements it, so callers
 * outside the Auth component depend on the contract only.
 */
final class AuthRegistrar {
	/**
	 * Register the contract aliases on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it binds each contract to its class and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(IdpLoginServiceInterface::class, IdpLoginService::class);
	}
}
