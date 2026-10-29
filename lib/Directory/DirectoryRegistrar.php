<?php

/**
 * Integriq DirectoryRegistrar.
 *
 * Registers the container bindings the Directory component offers to the
 * rest of the app.
 *
 * @category Directory
 * @package  OCA\Integriq\Directory
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

namespace OCA\Integriq\Directory;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Directory contract to the class that implements it, so callers
 * outside the Directory component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class DirectoryRegistrar {
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
		$context->registerServiceAlias(DirectorySyncServiceInterface::class, DirectorySyncService::class);
		$context->registerServiceAlias(ScimProvisioningServiceInterface::class, ScimProvisioningService::class);
	}
}
