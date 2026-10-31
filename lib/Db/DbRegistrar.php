<?php

/**
 * Integriq DbRegistrar.
 *
 * Registers the container bindings the Db component offers to the
 * rest of the app.
 *
 * @category Db
 * @package  OCA\Integriq\Db
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

namespace OCA\Integriq\Db;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Db contract to the class that implements it, so callers
 * outside the Db component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class DbRegistrar {
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
		$context->registerServiceAlias(OptOutMapperInterface::class, OptOutMapper::class);
		$context->registerServiceAlias(OptOutLogMapperInterface::class, OptOutLogMapper::class);
		$context->registerServiceAlias(UnsubscribeShortLinkMapperInterface::class, UnsubscribeShortLinkMapper::class);
	}
}
