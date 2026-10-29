<?php

/**
 * Integriq OutboundRegistrar.
 *
 * Registers the container bindings the Outbound component offers to the
 * rest of the app.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound
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

namespace OCA\Integriq\Outbound;

use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Outbound\Call\CallRecorderInterface;
use OCA\Integriq\Outbound\Call\CallReplayService;
use OCA\Integriq\Outbound\Call\CallReplayServiceInterface;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\OptOutRegistryInterface;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\Identity\SenderIdentityServiceInterface;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Outbound contract to the class that implements it, so callers
 * outside the Outbound component depend on the contract only.
 */
final class OutboundRegistrar {
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
		$context->registerServiceAlias(OptOutRegistryInterface::class, OptOutRegistry::class);
		$context->registerServiceAlias(CallReplayServiceInterface::class, CallReplayService::class);
		$context->registerServiceAlias(UnsubscribeTokenServiceInterface::class, UnsubscribeTokenService::class);
		$context->registerServiceAlias(SenderIdentityServiceInterface::class, SenderIdentityService::class);
		$context->registerServiceAlias(CallRecorderInterface::class, CallRecorder::class);
	}
}
