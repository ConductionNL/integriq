<?php

/**
 * Integriq ServiceRegistrar.
 *
 * Registers the container bindings the Service component offers to the
 * rest of the app.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiLaunchServiceInterface;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigratorInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Service contract to the class that implements it, so callers
 * outside the Service component depend on the contract only.
 *
 * Naming every contract and its class is this class's whole job, so its
 * coupling grows with the number of contracts by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class ServiceRegistrar {
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
		$context->registerServiceAlias(CallServiceInterface::class, CallService::class);
		$context->registerServiceAlias(EndpointServiceInterface::class, EndpointService::class);
		$context->registerServiceAlias(EventServiceInterface::class, EventService::class);
		$context->registerServiceAlias(SynchronizationServiceInterface::class, SynchronizationService::class);
		$context->registerServiceAlias(ConfigurationServiceInterface::class, ConfigurationService::class);
		$context->registerServiceAlias(BrokeredCallServiceInterface::class, BrokeredCallService::class);
		$context->registerServiceAlias(LegacyToRegisterMigratorInterface::class, LegacyToRegisterMigrator::class);
		$context->registerServiceAlias(EudiCredentialOfferServiceInterface::class, EudiCredentialOfferService::class);
		$context->registerServiceAlias(MappingServiceInterface::class, MappingService::class);
		$context->registerServiceAlias(ApprovalServiceInterface::class, ApprovalService::class);
		$context->registerServiceAlias(BankfeedSyncServiceInterface::class, BankfeedSyncService::class);
		$context->registerServiceAlias(LtiLaunchServiceInterface::class, LtiLaunchService::class);
		$context->registerServiceAlias(IBabsConnectorServiceInterface::class, IBabsConnectorService::class);
		$context->registerServiceAlias(JobServiceInterface::class, JobService::class);
		$context->registerServiceAlias(CatalogRegistryServiceInterface::class, CatalogRegistryService::class);
		$context->registerServiceAlias(UserServiceInterface::class, UserService::class);
		$context->registerServiceAlias(NotificatiesSubscriberServiceInterface::class, NotificatiesSubscriberService::class);
		$context->registerServiceAlias(SynchronizationFlowGeneratorInterface::class, SynchronizationFlowGenerator::class);
		$context->registerServiceAlias(PromotionServiceInterface::class, PromotionService::class);
		$context->registerServiceAlias(StufZknSyncServiceInterface::class, StufZknSyncService::class);
		$context->registerServiceAlias(RuleToFlowGeneratorInterface::class, RuleToFlowGenerator::class);
		$context->registerServiceAlias(FlowRunnerServiceInterface::class, FlowRunnerService::class);
		$context->registerServiceAlias(KissSyncServiceInterface::class, KissSyncService::class);
		$context->registerServiceAlias(JobToFlowGeneratorInterface::class, JobToFlowGenerator::class);
	}
}
