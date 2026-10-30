<?php

/**
 * Tests for the Service component's contract bindings.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
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

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365AdapterInterface;
use OCA\Integriq\Service\AgentTools\AgentBatchGate;
use OCA\Integriq\Service\AgentTools\AgentBatchGateInterface;
use OCA\Integriq\Service\AgentTools\HermiqVerdictClient;
use OCA\Integriq\Service\AgentTools\HttpHermiqVerdictClient;
use OCA\Integriq\Service\ApprovalDecisionService;
use OCA\Integriq\Service\ApprovalDecisionServiceInterface;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\ApprovalServiceInterface;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\AuthenticationServiceInterface;
use OCA\Integriq\Service\BankfeedSyncService;
use OCA\Integriq\Service\BankfeedSyncServiceInterface;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\BrokeredCallServiceInterface;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\CardfeedSyncService;
use OCA\Integriq\Service\CardfeedSyncServiceInterface;
use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Service\CatalogRegistryServiceInterface;
use OCA\Integriq\Service\ConfigurationImportPreviewService;
use OCA\Integriq\Service\ConfigurationImportPreviewServiceInterface;
use OCA\Integriq\Service\ConfigurationService;
use OCA\Integriq\Service\ConfigurationServiceInterface;
use OCA\Integriq\Service\ConnectionAlertService;
use OCA\Integriq\Service\ConnectionAlertServiceInterface;
use OCA\Integriq\Service\ConnectionMonitorService;
use OCA\Integriq\Service\ConnectionMonitorServiceInterface;
use OCA\Integriq\Service\ConnectionProbeService;
use OCA\Integriq\Service\ConnectionProbeServiceInterface;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCA\Integriq\Service\ConnectionRegistryServiceInterface;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\ConnectionStoreInterface;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use OCA\Integriq\Service\DSOParserService;
use OCA\Integriq\Service\DSOParserServiceInterface;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\Datasource\DashboardDatasourceService;
use OCA\Integriq\Service\Datasource\DashboardDatasourceServiceInterface;
use OCA\Integriq\Service\DemoDataService;
use OCA\Integriq\Service\DemoDataServiceInterface;
use OCA\Integriq\Service\DigitalPost\BerichtenboxHealth;
use OCA\Integriq\Service\DigitalPost\BerichtenboxHealthInterface;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettings;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettingsInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccount;
use OCA\Integriq\Service\DigitalPost\DigitalPostAccountInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\DigitalPost\DigitalPostServiceInterface;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationServiceInterface;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\DsoIngestServiceInterface;
use OCA\Integriq\Service\Dso\DsoActivityOverview;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcherInterface;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Dso\DsoUnmappedActivities;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\Integriq\Service\EndpointCacheServiceInterface;
use OCA\Integriq\Service\EndpointCorsPolicy;
use OCA\Integriq\Service\EndpointCorsPolicyInterface;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\EndpointServiceInterface;
use OCA\Integriq\Service\EnvironmentService;
use OCA\Integriq\Service\EnvironmentServiceInterface;
use OCA\Integriq\Service\EudiCredentialOfferService;
use OCA\Integriq\Service\EudiCredentialOfferServiceInterface;
use OCA\Integriq\Service\EudiIssuerKeyService;
use OCA\Integriq\Service\EudiIssuerKeyServiceInterface;
use OCA\Integriq\Service\EudiStatusListService;
use OCA\Integriq\Service\EudiStatusListServiceInterface;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeJobRunner;
use OCA\Integriq\Service\Exchange\ExchangeJobRunnerInterface;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeJobServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeReadModel;
use OCA\Integriq\Service\Exchange\ExchangeReadModelInterface;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionServiceInterface;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\ExecutionTraceServiceInterface;
use OCA\Integriq\Service\FlowGraphMigrationService;
use OCA\Integriq\Service\FlowGraphMigrationServiceInterface;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\FlowRunnerServiceInterface;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Service\Forms\FormsSyncAdapterInterface;
use OCA\Integriq\Service\FscCallService;
use OCA\Integriq\Service\FscCallServiceInterface;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\ExecutionTraceContextInterface;
use OCA\Integriq\Service\IBabsConnectorService;
use OCA\Integriq\Service\IBabsConnectorServiceInterface;
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
use OCA\Integriq\Service\IwmoIjwSyncService;
use OCA\Integriq\Service\IwmoIjwSyncServiceInterface;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\JobServiceInterface;
use OCA\Integriq\Service\JobToFlowGenerator;
use OCA\Integriq\Service\JobToFlowGeneratorInterface;
use OCA\Integriq\Service\KissSyncService;
use OCA\Integriq\Service\KissSyncServiceInterface;
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
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCA\Integriq\Service\Lti\LtiPlatformLoginServiceInterface;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcher;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcherInterface;
use OCA\Integriq\Service\Mail\MailIntakeService;
use OCA\Integriq\Service\Mail\MailIntakeServiceInterface;
use OCA\Integriq\Service\Mail\MailboxSourceHandler;
use OCA\Integriq\Service\Mail\MailboxSourceHandlerInterface;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigratorInterface;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Service\Mtls\MtlsConfigResolverInterface;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\Integriq\Service\NotificatiesSubscriberServiceInterface;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandlerInterface;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use OCA\Integriq\Service\Objecten\ObjectWriteHandlerInterface;
use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjectenTokenServiceInterface;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandlerInterface;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\Integriq\Service\OpenFormulierenIntakeServiceInterface;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\OsoServiceInterface;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\Integriq\Service\Ownership\RecordOwnershipServiceInterface;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Service\PaymentIntentServiceInterface;
use OCA\Integriq\Service\PeppolTransmissionService;
use OCA\Integriq\Service\PeppolTransmissionServiceInterface;
use OCA\Integriq\Service\Portal\ActivityOverviewInterface;
use OCA\Integriq\Service\Portal\PortalConnectionAlertsInterface;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
use OCA\Integriq\Service\Portal\UnmappedActivitiesInterface;
use OCA\Integriq\Service\PromotionService;
use OCA\Integriq\Service\PromotionServiceInterface;
use OCA\Integriq\Service\RecordProvenanceService;
use OCA\Integriq\Service\RecordProvenanceServiceInterface;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandler;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandlerInterface;
use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCA\Integriq\Service\Registry\SubscriptionRosterInterface;
use OCA\Integriq\Service\ResponseDecoder;
use OCA\Integriq\Service\ResponseDecoderInterface;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\RodServiceInterface;
use OCA\Integriq\Service\RuleToFlowGenerator;
use OCA\Integriq\Service\RuleToFlowGeneratorInterface;
use OCA\Integriq\Service\RunSummaryService;
use OCA\Integriq\Service\RunSummaryServiceInterface;
use OCA\Integriq\Service\SearchService;
use OCA\Integriq\Service\SearchServiceInterface;
use OCA\Integriq\Service\SecurityService;
use OCA\Integriq\Service\SecurityServiceInterface;
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
use OCA\Integriq\Service\ServiceRegistrar;
use OCA\Integriq\Service\SettingsService;
use OCA\Integriq\Service\SettingsServiceInterface;
use OCA\Integriq\Service\SignedPayloadVerifierInterface;
use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Service\SmsDispatchServiceInterface;
use OCA\Integriq\Service\SoftwareCatalogueService;
use OCA\Integriq\Service\SoftwareCatalogueServiceInterface;
use OCA\Integriq\Service\SourceDestructionService;
use OCA\Integriq\Service\SourceDestructionServiceInterface;
use OCA\Integriq\Service\SourceMappingService;
use OCA\Integriq\Service\SourceMappingServiceInterface;
use OCA\Integriq\Service\SourceTestService;
use OCA\Integriq\Service\SourceTestServiceInterface;
use OCA\Integriq\Service\StufZknSyncService;
use OCA\Integriq\Service\StufZknSyncServiceInterface;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufProviderReceiverInterface;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClient;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClientInterface;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiverInterface;
use OCA\Integriq\Service\StufZkn\StufZdsOutbound;
use OCA\Integriq\Service\StufZkn\StufZdsOutboundInterface;
use OCA\Integriq\Service\SyncItemDeadLetterService;
use OCA\Integriq\Service\SyncItemDeadLetterServiceInterface;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\SynchronizationFlowGenerator;
use OCA\Integriq\Service\SynchronizationFlowGeneratorInterface;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationLogServiceInterface;
use OCA\Integriq\Service\SynchronizationRunProgressService;
use OCA\Integriq\Service\SynchronizationRunProgressServiceInterface;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
use OCA\Integriq\Service\Tables\TablesSyncAdapterInterface;
use OCA\Integriq\Service\UserService;
use OCA\Integriq\Service\UserServiceInterface;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\UwlrEduVServiceInterface;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Service\VerzuimloketServiceInterface;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Service\WebhookSignatureServiceInterface;
use OCA\Integriq\Service\ZgwVersionTranslationService;
use OCA\Integriq\Service\ZgwVersionTranslationServiceInterface;
use OCA\Integriq\Service\Zgw\ZgwSetInstaller;
use OCA\Integriq\Service\Zgw\ZgwSetInstallerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The registrar binds every Service contract to the class that implements it.
 */
class ServiceRegistrarTest extends TestCase {
	private const EXPECTED = [
		CallServiceInterface::class            => CallService::class,
		EndpointServiceInterface::class        => EndpointService::class,
		EventServiceInterface::class           => EventService::class,
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
		DsoIngestServiceInterface::class => DsoIngestService::class,
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
		DSOParserServiceInterface::class => DSOParserService::class,
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
		DsoAttachmentFetcherInterface::class => DsoAttachmentFetcher::class,
		ConnectionAlertServiceInterface::class => ConnectionAlertService::class,
		SynchronizationContractServiceInterface::class => SynchronizationContractService::class,
		BerichtenboxSourceSettingsInterface::class => BerichtenboxSourceSettings::class,
		ConnectionMonitorServiceInterface::class => ConnectionMonitorService::class,
		TablesSyncAdapterInterface::class => TablesSyncAdapter::class,
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
		ExecutionTraceContextInterface::class => ExecutionTraceContext::class,
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
		IntakeGroupsInterface::class => IntakeGroups::class,
		ObjecttypeEndpointHandlerInterface::class => ObjecttypeEndpointHandler::class,
		ActionAuthServiceInterface::class => ActionAuthService::class,
		LtiNrpsServiceInterface::class => LtiNrpsService::class,
		RecordProvenanceServiceInterface::class => RecordProvenanceService::class,
		SubscriptionRequestHandlerInterface::class => SubscriptionRequestHandler::class,
		SubscriptionRosterInterface::class => SubscriptionRoster::class,
		AgentBatchGateInterface::class => AgentBatchGate::class,
		ActivityOverviewInterface::class => DsoActivityOverview::class,
		WebhookGateInterface::class => WebhookGate::class,
		EnvironmentServiceInterface::class => EnvironmentService::class,
		FormsSyncAdapterInterface::class => FormsSyncAdapter::class,
		PortalConnectionAlertsInterface::class => DsoConnectionAlerts::class,
		IntakeDocumentDispatcherInterface::class => IntakeDocumentDispatcher::class,
		EndpointCorsPolicyInterface::class => EndpointCorsPolicy::class,
		SourceTestServiceInterface::class => SourceTestService::class,
		EgressGuardInterface::class => EgressGuard::class,
		SecurityServiceInterface::class => SecurityService::class,
		ResponseDecoderInterface::class => ResponseDecoder::class,
		OpenRegisterCredentialBridgeInterface::class => OpenRegisterCredentialBridge::class,
		WebhookProfilesInterface::class => WebhookProfiles::class,
		InlineSecretMigrationPlannerInterface::class => InlineSecretMigrationPlanner::class,
		InlineSecretMigrationExecutorInterface::class => InlineSecretMigrationExecutor::class,
		InlineSecretMigrationFactoryInterface::class => InlineSecretMigrationFactory::class,
		HermiqVerdictClient::class => HttpHermiqVerdictClient::class,
	];

	/**
	 * The services the registrar builds with a factory, in registration order.
	 */
	private const FACTORIES = [
		'OCA\\Integriq\\Service\\SettingsService',
		'OCA\\Integriq\\Service\\Registry\\SubscriptionRegistry',
		'OCA\\Integriq\\Service\\DigitalPost\\DigitalPostProviderRegistry',
		'OCA\\Integriq\\Service\\Rod\\RodProviderRegistry',
		'OCA\\Integriq\\Service\\Verzuimloket\\VerzuimloketProviderRegistry',
		'OCA\\Integriq\\Service\\Oso\\OsoProviderRegistry',
		'OCA\\Integriq\\Service\\UwlrEduV\\UwlrEduVProviderRegistry',
		'OCA\\Integriq\\Service\\Objecten\\ObjecttypeRegistry',
		'OCA\\Integriq\\Service\\Objecten\\ObjectenTokenService',
		'OCA\\Integriq\\Service\\Objecten\\ObjecttypeEndpointHandler',
		'OCA\\Integriq\\Service\\Objecten\\ObjectEndpointHandler',
		'OCA\\Integriq\\Service\\Objecten\\ObjectWriteHandler',
		'OCA\\Integriq\\Service\\Tables\\TablesClientInterface',
		'OCA\\Integriq\\Service\\Forms\\FormsClientInterface',
	];

	/**
	 * Each contract is aliased to its implementation, and the only other registrations are the factories.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testRegistersEachContractAlias(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$factories = [];
		$context->method('registerService')->willReturnCallback(
			function (string $name) use (&$factories): void {
				$factories[] = $name;
			}
		);

		(new ServiceRegistrar())->register(context: $context);

		$this->assertSame(self::EXPECTED, $aliases);
		$this->assertSame(self::FACTORIES, $factories);
	}

	/**
	 * Every bound class implements its contract, so the alias resolves to a valid instance.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testEachImplementationImplementsItsContract(): void {
		foreach (self::EXPECTED as $contract => $implementation) {
			$this->assertTrue(interface_exists($contract), $contract);
			$this->assertContains($contract, class_implements($implementation), $implementation);
		}
	}

	/**
	 * Constants other components read stay reachable through the class as well as the contract.
	 *
	 * @return void
	 *
	 * @spec exclude refactoring guard, the constant values are unchanged from before the contracts existed
	 */
	public function testMovedConstantsKeepTheirValuesOnTheClass(): void {
		$this->assertSame(0.10, SynchronizationService::DEFAULT_DELETION_RATIO_THRESHOLD);
		$this->assertSame('no_contract', SynchronizationService::DESTRUCTION_NO_CONTRACT);
		$this->assertSame(3, SynchronizationService::MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD);
		$this->assertSame('/nextcloud/', EventService::NEXTCLOUD_SOURCE_PREFIX);
		$this->assertSame(5, (new \ReflectionClass(EventService::class))->getConstant('DEFAULT_MAX_RETRIES'));
	}
}
