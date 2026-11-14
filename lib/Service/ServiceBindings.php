<?php

/**
 * Integriq ServiceBindings.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Adapter\DataInfra\FtpsAdapter;
use OCA\Integriq\Service\Adapter\DataInfra\S3Adapter;
use OCA\Integriq\Service\Adapter\DataInfra\SftpAdapter;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuard;
use OCA\Integriq\Service\Adapter\Saas\DocumentConnectionGuardInterface;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365AdapterInterface;
use OCA\Integriq\Service\AgentTools\AgentActionStore;
use OCA\Integriq\Service\AgentTools\AgentActionStoreInterface;
use OCA\Integriq\Service\AgentTools\AgentBatchGate;
use OCA\Integriq\Service\AgentTools\AgentBatchGateInterface;
use OCA\Integriq\Service\AgentTools\DeadLetterProjection;
use OCA\Integriq\Service\AgentTools\DeadLetterProjectionInterface;
use OCA\Integriq\Service\AgentTools\HermiqVerdictClient;
use OCA\Integriq\Service\AgentTools\HttpHermiqVerdictClient;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use OCA\Integriq\Service\Datasource\DashboardDatasourceService;
use OCA\Integriq\Service\Datasource\DashboardDatasourceServiceInterface;
use OCA\Integriq\Service\DigitalPost\BerichtenboxHealth;
use OCA\Integriq\Service\DigitalPost\BerichtenboxHealthInterface;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettings;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettingsInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccountInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistryInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\DigitalPost\DigitalPostServiceInterface;
use OCA\Integriq\Service\DigitalPost\LogDigitalPostProvider;
use OCA\Integriq\Service\DigitalPost\PostexProvider;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationProviderRegistry;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationServiceInterface;
use OCA\Integriq\Service\DocumentGeneration\DocumentProviderRegistryInterface;
use OCA\Integriq\Service\Event\EventForwardingGuardInterface;
use OCA\Integriq\Service\Event\EventLoopGuard;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Dso\DsoUnmappedActivities;
use OCA\Integriq\Service\Exchange\ExchangeJobRunner;
use OCA\Integriq\Service\Exchange\ExchangeJobRunnerInterface;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeJobServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeReadModel;
use OCA\Integriq\Service\Exchange\ExchangeReadModelInterface;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogueInterface;
use OCA\Integriq\Service\Forms\FormsClientInterface;
use OCA\Integriq\Service\Forms\FormsOcsClient;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Service\Forms\FormsSyncAdapterInterface;
use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\Service\Intake\IntakeGroupsInterface;
use OCA\Integriq\Service\Intake\WebhookConnection;
use OCA\Integriq\Service\Intake\WebhookConnectionInterface;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use OCA\Integriq\Service\Intake\WebhookTrustMigrator;
use OCA\Integriq\Service\Intake\WebhookTrustMigratorInterface;
use OCA\Integriq\Service\Integration\SynchronizationContractProvider;
use OCA\Integriq\Service\Kiss\CallerSearchInterface;
use OCA\Integriq\Service\Kiss\ConfiguredCallerSearch;
use OCA\Integriq\Service\Kiss\CtiEventIntake;
use OCA\Integriq\Service\Kiss\CtiEventIntakeInterface;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiAgsServiceInterface;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiKeyServiceInterface;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiLaunchServiceInterface;
use OCA\Integriq\Service\Lti\LtiNrpsService;
use OCA\Integriq\Service\Lti\LtiNrpsServiceInterface;
use OCA\Integriq\Service\Lti\LtiPlatformDetailsService;
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCA\Integriq\Service\Lti\LtiPlatformLoginServiceInterface;
use OCA\Integriq\Service\Lti\ToolPlatformDetailsInterface;
use OCA\Integriq\Service\Mail\CaseReferenceDetector;
use OCA\Integriq\Service\Mail\CaseReferenceDetectorInterface;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcher;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcherInterface;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MailIntakeServiceInterface;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailboxSourceHandlerInterface;
use OCA\Integriq\Service\Mail\MessageParser;
use OCA\Integriq\Service\Mail\MessageParserInterface;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigratorInterface;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsConfigResolverInterface;
use OCA\Integriq\Service\Mtls\MtlsTransportService;
use OCA\Integriq\Service\Mtls\MtlsTransportServiceInterface;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandlerInterface;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use OCA\Integriq\Service\Objecten\ObjectWriteHandlerInterface;
use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjectenTokenServiceInterface;
use OCA\Integriq\Service\Objecten\ObjectenWiring;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandlerInterface;
use OCA\Integriq\Service\Oso\LogOsoProvider;
use OCA\Integriq\Service\Oso\OsoKennisnetClient;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\Ownership\LocalDeleteGuard;
use OCA\Integriq\Service\Ownership\LocalDeleteGuardInterface;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\Integriq\Service\Ownership\RecordOwnershipServiceInterface;
use OCA\Integriq\Service\Portal\ActivityMappingTableInterface;
use OCA\Integriq\Service\Portal\ActivityOverview;
use OCA\Integriq\Service\Portal\ActivityOverviewInterface;
use OCA\Integriq\Service\Portal\PortalConnectionAlertsInterface;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
use OCA\Integriq\Service\Portal\PortalRequestIntakeInterface;
use OCA\Integriq\Service\Portal\PortalRequestParserInterface;
use OCA\Integriq\Service\Portal\RequestAttachmentFetcherInterface;
use OCA\Integriq\Service\Portal\UnmappedActivitiesInterface;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\Integriq\Service\Registry\KvkMutatieProvider;
use OCA\Integriq\Service\Registry\LogSubscriptionProvider;
use OCA\Integriq\Service\Registry\RegistryUpdateClient;
use OCA\Integriq\Service\Registry\RegistryUpdateClientInterface;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Registry\SubscriptionRegistryInterface;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandler;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandlerInterface;
use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCA\Integriq\Service\Registry\SubscriptionRosterInterface;
use OCA\Integriq\Service\Rod\LogRodProvider;
use OCA\Integriq\Service\Rod\RodEdukoppelingClient;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\Security\AuthenticationConfigAuditor;
use OCA\Integriq\Service\Security\AuthenticationConfigAuditorInterface;
use OCA\Integriq\Service\Security\AuthenticationConfigRemover;
use OCA\Integriq\Service\Security\AuthenticationConfigRemoverInterface;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutorInterface;
use OCA\Integriq\Service\Security\InlineSecretMigrationFactory;
use OCA\Integriq\Service\Security\InlineSecretMigrationFactoryInterface;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlannerInterface;
use OCA\Integriq\Service\Security\PlaceholderSecretRepair;
use OCA\Integriq\Service\Security\PlaceholderSecretRepairInterface;
use OCA\Integriq\Service\Security\SecretRedactorInterface;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\Sms\E164PhoneNumberNormaliser;
use OCA\Integriq\Service\Sms\PhoneNumberNormaliserInterface;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufProviderReceiverInterface;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClient;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClientInterface;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiverInterface;
use OCA\Integriq\Service\StufZkn\StufZdsOutbound;
use OCA\Integriq\Service\StufZkn\StufZdsOutboundInterface;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicy;
use OCA\Integriq\Service\Subscriptions\SubscriptionSigningPolicyInterface;
use OCA\Integriq\Service\Tables\TablesClientInterface;
use OCA\Integriq\Service\Tables\TablesOcsClient;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
use OCA\Integriq\Service\Tables\TablesSyncAdapterInterface;
use OCA\Integriq\Service\UwlrEduV\LogUwlrEduVProvider;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVKennisnetClient;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry;
use OCA\Integriq\Service\Verzuimloket\LogVerzuimloketProvider;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEdukoppelingClient;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallerInterface;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Service contract to the class that implements it, so callers
 * outside the Service component depend on the contract only.
 *
 * Naming every contract and its class is this class's whole job, so its
 * coupling grows with the number of contracts by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class ServiceBindings {

	/**
	 * The contracts of the services other components call most, bound to their class in this order.
	 *
	 * @var array<class-string, class-string>
	 */
	private const CONTRACTS = [
		CallServiceInterface::class => CallService::class,
		EndpointServiceInterface::class => EndpointService::class,
		EventServiceInterface::class => EventService::class,
		SynchronizationServiceInterface::class => SynchronizationService::class,
		ConfigurationServiceInterface::class => ConfigurationService::class,
		BrokeredCallServiceInterface::class => BrokeredCallService::class,
		LegacyToRegisterMigratorInterface::class => LegacyToRegisterMigrator::class,
		EudiCredentialOfferServiceInterface::class => EudiCredentialOfferService::class,
		MappingServiceInterface::class => MappingService::class,
		ApprovalServiceInterface::class => ApprovalService::class,
		BankfeedSyncServiceInterface::class => BankfeedSyncService::class,
		LtiLaunchServiceInterface::class => LtiLaunchService::class,
		IBabsConnectorServiceInterface::class => IBabsConnectorService::class,
		JobServiceInterface::class => JobService::class,
		CatalogRegistryServiceInterface::class => CatalogRegistryService::class,
		UserServiceInterface::class => UserService::class,
		NotificatiesSubscriberServiceInterface::class => NotificatiesSubscriberService::class,
		SynchronizationFlowGeneratorInterface::class => SynchronizationFlowGenerator::class,
		PromotionServiceInterface::class => PromotionService::class,
		StufZknSyncServiceInterface::class => StufZknSyncService::class,
		RuleToFlowGeneratorInterface::class => RuleToFlowGenerator::class,
		FlowRunnerServiceInterface::class => FlowRunnerService::class,
		KissSyncServiceInterface::class => KissSyncService::class,
		JobToFlowGeneratorInterface::class => JobToFlowGenerator::class,
		PortalRequestIntakeInterface::class => DsoIngestService::class,
		AuthenticationServiceInterface::class => AuthenticationService::class,
		SoftwareCatalogueServiceInterface::class => SoftwareCatalogueService::class,
		OpenFormulierenIntakeServiceInterface::class => OpenFormulierenIntakeService::class,
		LtiKeyServiceInterface::class => LtiKeyService::class,
		CardfeedSyncServiceInterface::class => CardfeedSyncService::class,
		ExchangeJobServiceInterface::class => ExchangeJobService::class,
		PeppolTransmissionServiceInterface::class => PeppolTransmissionService::class,
		LtiAgsServiceInterface::class => LtiAgsService::class,
		IwmoIjwSyncServiceInterface::class => IwmoIjwSyncService::class,
		StufZdsOutboundInterface::class => StufZdsOutbound::class,
		SmsDispatchServiceInterface::class => SmsDispatchService::class,
		StufProviderReceiverInterface::class => StufProviderReceiver::class,
		DigitalPostServiceInterface::class => DigitalPostService::class,
		ExchangeRejectionServiceInterface::class => ExchangeRejectionService::class,
		ConfigurationImportPreviewServiceInterface::class => ConfigurationImportPreviewService::class,
		ExecutionTraceServiceInterface::class => ExecutionTraceService::class,
		DocumentGenerationServiceInterface::class => DocumentGenerationService::class,
		ObjectEndpointHandlerInterface::class => ObjectEndpointHandler::class,
		ExchangeJobRunnerInterface::class => ExchangeJobRunner::class,
		ConnectionRegistryServiceInterface::class => ConnectionRegistryService::class,
		EudiStatusListServiceInterface::class => EudiStatusListService::class,
		PortalRequestParserInterface::class => DSOParserService::class,
		ApprovalDecisionServiceInterface::class => ApprovalDecisionService::class,
		UwlrEduVServiceInterface::class => UwlrEduVService::class,
		PaymentIntentServiceInterface::class => PaymentIntentService::class,
		EudiIssuerKeyServiceInterface::class => EudiIssuerKeyService::class,
		VerzuimloketServiceInterface::class => VerzuimloketService::class,
		LtiPlatformLoginServiceInterface::class => LtiPlatformLoginService::class,
		RodServiceInterface::class => RodService::class,
		EndpointCacheServiceInterface::class => EndpointCacheService::class,
		OsoServiceInterface::class => OsoService::class,
		SettingsServiceInterface::class => SettingsService::class,
		ExchangeReadModelInterface::class => ExchangeReadModel::class,
		SearchServiceInterface::class => SearchService::class,
		RequestAttachmentFetcherInterface::class => DsoAttachmentFetcher::class,
		ConnectionAlertServiceInterface::class => ConnectionAlertService::class,
		SynchronizationContractServiceInterface::class => SynchronizationContractService::class,
		BerichtenboxSourceSettingsInterface::class => BerichtenboxSourceSettings::class,
		ConnectionMonitorServiceInterface::class => ConnectionMonitorService::class,
		TablesSyncAdapterInterface::class => TablesSyncAdapter::class,
	];

	/**
	 * The contracts of the supporting services other components call, bound in this order.
	 *
	 * @var array<class-string, class-string>
	 */
	private const SUPPORTING_CONTRACTS = [
		StufZdsConfirmationReceiverInterface::class => StufZdsConfirmationReceiver::class,
		FscCallServiceInterface::class => FscCallService::class,
		ConnectionStoreInterface::class => ConnectionStore::class,
		ObjectWriteHandlerInterface::class => ObjectWriteHandler::class,
		PortalConnectionInterface::class => DsoConnection::class,
		ZgwSetInstallerInterface::class => ZgwSetInstaller::class,
		AuthenticationConfigAuditorInterface::class => AuthenticationConfigAuditor::class,
		StufZdsCaseClientInterface::class => StufZdsCaseClient::class,
		MailboxSourceHandlerInterface::class => MailboxSourceHandler::class,
		ObjectenTokenServiceInterface::class => ObjectenTokenService::class,
		RunSummaryServiceInterface::class => RunSummaryService::class,
		SignedPayloadVerifierInterface::class => DSOSignatureVerifierService::class,
		ConnectionProbeServiceInterface::class => ConnectionProbeService::class,
		WebhookSignatureServiceInterface::class => WebhookSignatureService::class,
		SyncItemDeadLetterServiceInterface::class => SyncItemDeadLetterService::class,
		SynchronizationRunProgressServiceInterface::class => SynchronizationRunProgressService::class,
		DigitalPostAccountInterface::class => DigitalPostAccount::class,
		AuthenticationConfigRemoverInterface::class => AuthenticationConfigRemover::class,
		MtlsConfigResolverInterface::class => MtlsConfigResolver::class,
		RecordOwnershipServiceInterface::class => RecordOwnershipService::class,
		DashboardDatasourceServiceInterface::class => DashboardDatasourceService::class,
		SourceMappingServiceInterface::class => SourceMappingService::class,
		MailIntakeServiceInterface::class => MailIntakeService::class,
		FlowGraphMigrationServiceInterface::class => FlowGraphMigrationService::class,
		WebhookConnectionInterface::class => WebhookConnection::class,
		SynchronizationLogServiceInterface::class => SynchronizationLogService::class,
		DemoDataServiceInterface::class => DemoDataService::class,
		BerichtenboxHealthInterface::class => BerichtenboxHealth::class,
		ZgwVersionTranslationServiceInterface::class => ZgwVersionTranslationService::class,
		SourceDestructionServiceInterface::class => SourceDestructionService::class,
		UnmappedActivitiesInterface::class => DsoUnmappedActivities::class,
		WebhookTrustMigratorInterface::class => WebhookTrustMigrator::class,
		Microsoft365AdapterInterface::class => Microsoft365Adapter::class,
		CtiEventIntakeInterface::class => CtiEventIntake::class,
		CallerSearchInterface::class => ConfiguredCallerSearch::class,
		IntakeGroupsInterface::class => IntakeGroups::class,
		ObjecttypeEndpointHandlerInterface::class => ObjecttypeEndpointHandler::class,
		ActionAuthServiceInterface::class => ActionAuthService::class,
		LtiNrpsServiceInterface::class => LtiNrpsService::class,
		RecordProvenanceServiceInterface::class => RecordProvenanceService::class,
		SubscriptionRequestHandlerInterface::class => SubscriptionRequestHandler::class,
		SubscriptionRosterInterface::class => SubscriptionRoster::class,
		AgentBatchGateInterface::class => AgentBatchGate::class,
		ActivityOverviewInterface::class => ActivityOverview::class,
		WebhookGateInterface::class => WebhookGate::class,
		EnvironmentServiceInterface::class => EnvironmentService::class,
		FormsSyncAdapterInterface::class => FormsSyncAdapter::class,
		PortalConnectionAlertsInterface::class => DsoConnectionAlerts::class,
		IntakeDocumentDispatcherInterface::class => IntakeDocumentDispatcher::class,
		EndpointCorsPolicyInterface::class => EndpointCorsPolicy::class,
		SourceTestServiceInterface::class => SourceTestService::class,
		SynchronizationLookupInterface::class => SynchronizationService::class,
	];

	/**
	 * The helpers callers in other components used to build with `new` or call statically, bound in this order.
	 *
	 * @var array<class-string, class-string>
	 */
	private const INJECTED_HELPERS = [
		EgressGuardInterface::class => EgressGuard::class,
		SecretRedactorInterface::class => SensitiveFieldRegistry::class,
		SecurityServiceInterface::class => SecurityService::class,
		ResponseDecoderInterface::class => ResponseDecoder::class,
		OpenRegisterCredentialBridgeInterface::class => OpenRegisterCredentialBridge::class,
		WebhookProfilesInterface::class => WebhookProfiles::class,
		InlineSecretMigrationPlannerInterface::class => InlineSecretMigrationPlanner::class,
		InlineSecretMigrationExecutorInterface::class => InlineSecretMigrationExecutor::class,
		InlineSecretMigrationFactoryInterface::class => InlineSecretMigrationFactory::class,
		PlaceholderSecretRepairInterface::class => PlaceholderSecretRepair::class,
		RegistryUpdateClientInterface::class => RegistryUpdateClient::class,
		AgentActionStoreInterface::class => AgentActionStore::class,
		CaseReferenceDetectorInterface::class => CaseReferenceDetector::class,
		LocalDeleteGuardInterface::class => LocalDeleteGuard::class,
		MessageParserInterface::class => MessageParser::class,
		DeadLetterProjectionInterface::class => DeadLetterProjection::class,
		MtlsTransportServiceInterface::class => MtlsTransportService::class,
		ExchangeTargetCatalogueInterface::class => ExchangeTargetCatalogue::class,
		MessageValidationServiceInterface::class => MessageValidationService::class,
		DigitalPostProviderRegistryInterface::class => DigitalPostProviderRegistry::class,
		DocumentConnectionGuardInterface::class => DocumentConnectionGuard::class,
		SubscriptionRegistryInterface::class => SubscriptionRegistry::class,
		DocumentProviderRegistryInterface::class => DocumentGenerationProviderRegistry::class,
		ToolPlatformDetailsInterface::class => LtiPlatformDetailsService::class,
		ActivityMappingTableInterface::class => DsoActivityTable::class,
		ApiVersionNegotiationInterface::class => ZgwVersionNegotiationService::class,
		SubscriptionSigningPolicyInterface::class => SubscriptionSigningPolicy::class,
		PhoneNumberNormaliserInterface::class => E164PhoneNumberNormaliser::class,
		CloudEventEmitterInterface::class => EventService::class,
	];
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
		$this->registerContracts(context: $context);
		$this->registerSupportingContracts(context: $context);
		$this->registerInjectedHelpers(context: $context);
		$this->registerSettingsService(context: $context);
		$this->registerSubscriptionRegistry(context: $context);
		$this->registerDigitalPostAndRodRegistries(context: $context);
		$this->registerVerzuimloketAndOsoRegistries(context: $context);
		$this->registerUwlrRegistry(context: $context);
		$this->registerAgentToolClient(context: $context);
		$this->registerObjectenApis(context: $context);
		$this->registerTablesAndFormsClients(context: $context);
	}
	/**
	 * Register integriq-side IntegrationProviders with OR's IntegrationRegistry.
	 *
	 * Per OR's pluggable-integration-registry spec (AD-1), apps register their
	 * providers at boot — OR's registry is a shared per-request service so all
	 * apps see the same instance.
	 *
	 * Currently registered:
	 *   - SynchronizationContractProvider — surfaces SyncContract leaves on the
	 *     OR objects they synchronise (GH #824).
	 *   - AzureVirtualDesktopAdapter, SharePointOnlineAdapter, Microsoft365Adapter,
	 *     S3Adapter — one reference adapter per connector-category spec
	 *     (endpoint-workspace, document-cms, saas-productivity, data-infra),
	 *     proving the `AbstractCategoryAdapterProvider` registration pattern
	 *     (openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding).
	 *
	 * Soft-fails if OR's IntegrationRegistry isn't available (e.g. when
	 * integriq is loaded but openregister isn't enabled yet) so boot
	 * doesn't crash on a stale install.
	 *
	 * @param IBootContext $context The app's boot context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-integrationprovider-boot-time-registration-with-or-integrationregistry-req-002
	 * @spec openspec/changes/archive/2026-09-29-connector-category-adapter-scaffolding/tasks.md#task-2
	 */
	public function boot(IBootContext $context): void {
		if (class_exists(IntegrationRegistry::class) === false) {
			return;
		}

		try {
			$container = $context->getServerContainer();
			$registry = $container->get(IntegrationRegistry::class);
			$registry->addProvider($container->get(SynchronizationContractProvider::class));
			$registry->addProvider($container->get(AzureVirtualDesktopAdapter::class));
			$registry->addProvider($container->get(SharePointOnlineAdapter::class));
			$registry->addProvider($container->get(Microsoft365Adapter::class));
			$registry->addProvider($container->get(S3Adapter::class));
			// Sources-sftp-adapter: a partner's SFTP or FTPS server (REQ-SFTP-001).
			$registry->addProvider($container->get(SftpAdapter::class));
			$registry->addProvider($container->get(FtpsAdapter::class));
		} catch (\Throwable $e) {
			// Don't crash boot — log and continue. The provider just won't appear
			// in object sidebars on this instance until the registry resolves.
			try {
				$context->getServerContainer()
					->get('Psr\Log\LoggerInterface')
					->warning(
						'integriq: failed to register IntegrationProviders with OR — ' . $e->getMessage(),
						['exception' => $e]
					);
			} catch (\Throwable) {
				// Logger unavailable, ignore.
			}
		}//end try
	}//end boot()

	/**
	 * Registers the settings service factory.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSettingsService(IRegistrationContext $context): void {
		// Register services.
		$context->registerService(
			SettingsService::class,
			function ($c) {
				return new SettingsService(
					db: $c->get('OCP\IDBConnection'),
					config: $c->get('OCP\IAppConfig'),
					logger: $c->get('Psr\Log\LoggerInterface')
				);
			}
		);
	}//end registerSettingsService()

	/**
	 * Registers the registry subscription bindings.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSubscriptionRegistry(IRegistrationContext $context): void {
		// The registry subscription bindings, keyed by registry id. The `log`
		// binding is last, so a real registry always wins its own id and the
		// development binding only answers to `log`.
		$context->registerService(
			SubscriptionRegistry::class,
			static function ($c): SubscriptionRegistry {
				return new SubscriptionRegistry(
					providers: [
						$c->get(BrpVolgindicatieProvider::class),
						$c->get(KvkMutatieProvider::class),
						$c->get(LogSubscriptionProvider::class),
					]
				);
			}
		);
	}//end registerSubscriptionRegistry()

	/**
	 * Registers the digital post and ROD provider registries.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerDigitalPostAndRodRegistries(IRegistrationContext $context): void {
		// The digital post bindings. `log` is registered last so a real binding
		// always wins its own id and the development one only ever answers to
		// `log`.
		$context->registerService(
			DigitalPostProviderRegistry::class,
			static function ($c): DigitalPostProviderRegistry {
				return new DigitalPostProviderRegistry(
					providers: [
						$c->get(BerichtenboxProvider::class),
						$c->get(PostexProvider::class),
						$c->get(LogDigitalPostProvider::class),
					]
				);
			}
		);

		// The ROD (DUO Register Onderwijsdeelnemers) provider bindings. `log`
		// is registered last for the same reason as the digital post bindings
		// above: a real binding always wins its own id.
		$context->registerService(
			RodProviderRegistry::class,
			static function ($c): RodProviderRegistry {
				return new RodProviderRegistry(
					providers: [
						$c->get(RodEdukoppelingClient::class),
						$c->get(LogRodProvider::class),
					]
				);
			}
		);
	}//end registerDigitalPostAndRodRegistries()

	/**
	 * Registers the Verzuimloket and OSO provider registries.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerVerzuimloketAndOsoRegistries(IRegistrationContext $context): void {
		// The Verzuimloket (DUO VSV-M2M) provider bindings. `log` is registered
		// last for the same reason as the digital post bindings above.
		$context->registerService(
			VerzuimloketProviderRegistry::class,
			static function ($c): VerzuimloketProviderRegistry {
				return new VerzuimloketProviderRegistry(
					providers: [
						$c->get(VerzuimloketEdukoppelingClient::class),
						$c->get(LogVerzuimloketProvider::class),
					]
				);
			}
		);

		// The OSO export provider bindings. `log` is registered last for the
		// same reason as the digital post bindings above.
		$context->registerService(
			OsoProviderRegistry::class,
			static function ($c): OsoProviderRegistry {
				return new OsoProviderRegistry(
					providers: [
						$c->get(OsoKennisnetClient::class),
						$c->get(LogOsoProvider::class),
					]
				);
			}
		);
	}//end registerVerzuimloketAndOsoRegistries()

	/**
	 * Registers the UWLR/Edu-V provider registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerUwlrRegistry(IRegistrationContext $context): void {
		// The UWLR/Edu-V/Basispoort/Entree-content export provider bindings.
		// `log` is registered last for the same reason as the digital post
		// bindings above.
		$context->registerService(
			UwlrEduVProviderRegistry::class,
			static function ($c): UwlrEduVProviderRegistry {
				return new UwlrEduVProviderRegistry(
					providers: [
						$c->get(UwlrEduVKennisnetClient::class),
						$c->get(LogUwlrEduVProvider::class),
					]
				);
			}
		);
	}//end registerUwlrRegistry()

	/**
	 * Registers the verdict transport of the agent tools.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerAgentToolClient(IRegistrationContext $context): void {
		// The hermiq-ai-tooling change: the verdict transport, and the opt-in alias under
		// which OpenRegister's AttributeToolScanner finds the six curated agent
		// tools (OpenRegister Application, IMcpScannableServices::<appId>).
		$context->registerServiceAlias(HermiqVerdictClient::class, HttpHermiqVerdictClient::class);
	}//end registerAgentToolClient()

	/**
	 * Registers the Objecten and Objecttypen API services.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectenWiring registers its facade services through one static entry point.
	 */
	private function registerObjectenApis(IRegistrationContext $context): void {
		// The Objecten and Objecttypen APIs: every facade service is built with
		// its OpenRegister seams wired, or every route answers 401 or 404.
		ObjectenWiring::register(context: $context);
	}//end registerObjectenApis()

	/**
	 * Registers the Tables and Forms OCS clients.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerTablesAndFormsClients(IRegistrationContext $context): void {
		// Tables-bridge: bind the polymorphic Tables API seam to its concrete
		// v1-REST implementation (design.md Decision 2). `TablesOcsClient`'s
		// own dependencies (CallService, LoggerInterface) are plain
		// autowirable types, so only the interface binding needs an explicit
		// factory here.
		$context->registerService(
			TablesClientInterface::class,
			static function ($c) {
				return $c->get(TablesOcsClient::class);
			}
		);

		// Forms-connector: bind the polymorphic Forms API seam to its
		// concrete v3-REST implementation (design.md Decision 1), identical
		// shape to the TablesClientInterface binding above. `FormsOcsClient`'s
		// own dependencies (CallService, LoggerInterface) are plain
		// autowirable types, so only the interface binding needs an explicit
		// factory here. No conditional/feature-detected binding — the client
		// class itself has no `OCA\Forms\*` reference, so it is always
		// safely constructible; only *usage* is feature-detected
		// (FormsSyncAdapter::assertEnabled()).
		$context->registerService(
			FormsClientInterface::class,
			static function ($c) {
				return $c->get(FormsOcsClient::class);
			}
		);
	}//end registerTablesAndFormsClients()

	/**
	 * Binds the contracts of the services other components call most.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerContracts(IRegistrationContext $context): void {
		$this->aliasAll(context: $context, aliases: self::CONTRACTS);
	}//end registerContracts()

	/**
	 * Binds the contracts of the supporting services other components call.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSupportingContracts(IRegistrationContext $context): void {
		$this->aliasAll(context: $context, aliases: self::SUPPORTING_CONTRACTS);
	}//end registerSupportingContracts()

	/**
	 * Binds the helpers that callers in other components used to build with
	 * `new` or call statically, and now receive injected.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerInjectedHelpers(IRegistrationContext $context): void {
		$this->aliasAll(context: $context, aliases: self::INJECTED_HELPERS);
		// Not shared: each listener keeps its own chain counter.
		$context->registerService(EventForwardingGuardInterface::class, static fn (): EventLoopGuard => new EventLoopGuard(), false);
	}//end registerInjectedHelpers()

	/**
	 * Bind each contract to its class, in the order given.
	 *
	 * @param IRegistrationContext               $context The registration context.
	 * @param array<class-string, class-string> $aliases Contract => class.
	 *
	 * @return void
	 */
	private function aliasAll(IRegistrationContext $context, array $aliases): void {
		foreach ($aliases as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}//end aliasAll()
}
