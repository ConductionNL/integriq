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

use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettings;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettingsInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\DigitalPost\DigitalPostServiceInterface;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationServiceInterface;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcherInterface;
use OCA\Integriq\Service\Exchange\ExchangeJobRunner;
use OCA\Integriq\Service\Exchange\ExchangeJobRunnerInterface;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeJobServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeReadModel;
use OCA\Integriq\Service\Exchange\ExchangeReadModelInterface;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionServiceInterface;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiAgsServiceInterface;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiKeyServiceInterface;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiLaunchServiceInterface;
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCA\Integriq\Service\Lti\LtiPlatformLoginServiceInterface;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigratorInterface;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandlerInterface;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufProviderReceiverInterface;
use OCA\Integriq\Service\StufZkn\StufZdsOutbound;
use OCA\Integriq\Service\StufZkn\StufZdsOutboundInterface;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
use OCA\Integriq\Service\Tables\TablesSyncAdapterInterface;
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
		$context->registerServiceAlias(DsoIngestServiceInterface::class, DsoIngestService::class);
		$context->registerServiceAlias(AuthenticationServiceInterface::class, AuthenticationService::class);
		$context->registerServiceAlias(SoftwareCatalogueServiceInterface::class, SoftwareCatalogueService::class);
		$context->registerServiceAlias(OpenFormulierenIntakeServiceInterface::class, OpenFormulierenIntakeService::class);
		$context->registerServiceAlias(LtiKeyServiceInterface::class, LtiKeyService::class);
		$context->registerServiceAlias(CardfeedSyncServiceInterface::class, CardfeedSyncService::class);
		$context->registerServiceAlias(ExchangeJobServiceInterface::class, ExchangeJobService::class);
		$context->registerServiceAlias(PeppolTransmissionServiceInterface::class, PeppolTransmissionService::class);
		$context->registerServiceAlias(LtiAgsServiceInterface::class, LtiAgsService::class);
		$context->registerServiceAlias(IwmoIjwSyncServiceInterface::class, IwmoIjwSyncService::class);
		$context->registerServiceAlias(StufZdsOutboundInterface::class, StufZdsOutbound::class);
		$context->registerServiceAlias(SmsDispatchServiceInterface::class, SmsDispatchService::class);
		$context->registerServiceAlias(StufProviderReceiverInterface::class, StufProviderReceiver::class);
		$context->registerServiceAlias(DigitalPostServiceInterface::class, DigitalPostService::class);
		$context->registerServiceAlias(ExchangeRejectionServiceInterface::class, ExchangeRejectionService::class);
		$context->registerServiceAlias(ConfigurationImportPreviewServiceInterface::class, ConfigurationImportPreviewService::class);
		$context->registerServiceAlias(ExecutionTraceServiceInterface::class, ExecutionTraceService::class);
		$context->registerServiceAlias(DocumentGenerationServiceInterface::class, DocumentGenerationService::class);
		$context->registerServiceAlias(ObjectEndpointHandlerInterface::class, ObjectEndpointHandler::class);
		$context->registerServiceAlias(ExchangeJobRunnerInterface::class, ExchangeJobRunner::class);
		$context->registerServiceAlias(ConnectionRegistryServiceInterface::class, ConnectionRegistryService::class);
		$context->registerServiceAlias(EudiStatusListServiceInterface::class, EudiStatusListService::class);
		$context->registerServiceAlias(DSOParserServiceInterface::class, DSOParserService::class);
		$context->registerServiceAlias(ApprovalDecisionServiceInterface::class, ApprovalDecisionService::class);
		$context->registerServiceAlias(UwlrEduVServiceInterface::class, UwlrEduVService::class);
		$context->registerServiceAlias(PaymentIntentServiceInterface::class, PaymentIntentService::class);
		$context->registerServiceAlias(EudiIssuerKeyServiceInterface::class, EudiIssuerKeyService::class);
		$context->registerServiceAlias(VerzuimloketServiceInterface::class, VerzuimloketService::class);
		$context->registerServiceAlias(LtiPlatformLoginServiceInterface::class, LtiPlatformLoginService::class);
		$context->registerServiceAlias(RodServiceInterface::class, RodService::class);
		$context->registerServiceAlias(EndpointCacheServiceInterface::class, EndpointCacheService::class);
		$context->registerServiceAlias(OsoServiceInterface::class, OsoService::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(ExchangeReadModelInterface::class, ExchangeReadModel::class);
		$context->registerServiceAlias(SearchServiceInterface::class, SearchService::class);
		$context->registerServiceAlias(DsoAttachmentFetcherInterface::class, DsoAttachmentFetcher::class);
		$context->registerServiceAlias(ConnectionAlertServiceInterface::class, ConnectionAlertService::class);
		$context->registerServiceAlias(SynchronizationContractServiceInterface::class, SynchronizationContractService::class);
		$context->registerServiceAlias(BerichtenboxSourceSettingsInterface::class, BerichtenboxSourceSettings::class);
		$context->registerServiceAlias(ConnectionMonitorServiceInterface::class, ConnectionMonitorService::class);
		$context->registerServiceAlias(TablesSyncAdapterInterface::class, TablesSyncAdapter::class);
	}
}
