<?php

/**
 * Integriq IntakeRegistrar.
 *
 * Registers the container bindings the Intake component offers to the
 * rest of the app.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
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

namespace OCA\Integriq\Intake;

use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\MessagingChannelAdapter;
use OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter;
use OCA\Integriq\Intake\Adapter\TeamsChannelAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Intake contract to the class that implements it, so callers
 * outside the Intake component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class IntakeRegistrar {
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
		$context->registerServiceAlias(IntakeRoutingServiceInterface::class, IntakeRoutingService::class);
		$context->registerServiceAlias(IntakeReplyServiceInterface::class, IntakeReplyService::class);
		$context->registerServiceAlias(IntakeChannelRegistryInterface::class, IntakeChannelRegistry::class);
		$this->registerChannelRegistry(context: $context);
	}
	/**
	 * Registers the intake channel registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerChannelRegistry(IRegistrationContext $context): void {
		// The intake channel registry: one keyed list of the channel adapters
		// this instance has. Registered explicitly rather than autowired, for
		// the same reason as the property-source registry above: the order,
		// and therefore the first-wins collision policy, is readable in one
		// place instead of depending on discovery order
		// (openspec/changes/intake-channels-beyond-mail).
		$context->registerService(
			IntakeChannelRegistry::class,
			static function ($c): IntakeChannelRegistry {
				return new IntakeChannelRegistry(
					logger: $c->get('Psr\Log\LoggerInterface'),
					adapters: [
						$c->get(FormSubmissionAdapter::class),
						$c->get(MessagingChannelAdapter::class),
						$c->get(PublicSpaceReportAdapter::class),
						$c->get(TeamsChannelAdapter::class),
					]
				);
			}
		);
	}//end registerChannelRegistry()
}
