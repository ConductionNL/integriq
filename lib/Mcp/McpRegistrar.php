<?php

/**
 * Integriq McpRegistrar.
 *
 * Registers the services of the Mcp component.
 *
 * @category Mcp
 * @package  OCA\Integriq\Mcp
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

namespace OCA\Integriq\Mcp;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the Mcp component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class McpRegistrar {
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
		$this->registerScannableServices(context: $context);
	}//end register()

	/**
	 * Registers the alias under which OpenRegister finds the agent tools.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerScannableServices(IRegistrationContext $context): void {
		$context->registerService(
			'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::integriq',
			static fn ($c) => $c->get(IntegriqScannableServices::class)
		);
	}//end registerScannableServices()
}
