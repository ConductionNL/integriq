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
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Adapter\DataInfra\S3Adapter;
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
use OCA\Integriq\Service\Dso\DsoActivityOverview;
use OCA\Integriq\Service\Dso\DsoActivityTable;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcherInterface;
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
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\ExecutionTraceContextInterface;
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
use OCA\Integriq\Service\Portal\ActivityOverviewInterface;
use OCA\Integriq\Service\Portal\PortalConnectionAlertsInterface;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
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
	}//end registerContracts()

	/**
	 * Binds the contracts of the supporting services other components call.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerSupportingContracts(IRegistrationContext $context): void {
		$context->registerServiceAlias(StufZdsConfirmationReceiverInterface::class, StufZdsConfirmationReceiver::class);
		$context->registerServiceAlias(FscCallServiceInterface::class, FscCallService::class);
		$context->registerServiceAlias(ConnectionStoreInterface::class, ConnectionStore::class);
		$context->registerServiceAlias(ObjectWriteHandlerInterface::class, ObjectWriteHandler::class);
		$context->registerServiceAlias(PortalConnectionInterface::class, DsoConnection::class);
		$context->registerServiceAlias(ZgwSetInstallerInterface::class, ZgwSetInstaller::class);
		$context->registerServiceAlias(AuthenticationConfigAuditorInterface::class, AuthenticationConfigAuditor::class);
		$context->registerServiceAlias(StufZdsCaseClientInterface::class, StufZdsCaseClient::class);
		$context->registerServiceAlias(MailboxSourceHandlerInterface::class, MailboxSourceHandler::class);
		$context->registerServiceAlias(ObjectenTokenServiceInterface::class, ObjectenTokenService::class);
		$context->registerServiceAlias(RunSummaryServiceInterface::class, RunSummaryService::class);
		$context->registerServiceAlias(SignedPayloadVerifierInterface::class, DSOSignatureVerifierService::class);
		$context->registerServiceAlias(ConnectionProbeServiceInterface::class, ConnectionProbeService::class);
		$context->registerServiceAlias(WebhookSignatureServiceInterface::class, WebhookSignatureService::class);
		$context->registerServiceAlias(SyncItemDeadLetterServiceInterface::class, SyncItemDeadLetterService::class);
		$context->registerServiceAlias(SynchronizationRunProgressServiceInterface::class, SynchronizationRunProgressService::class);
		$context->registerServiceAlias(DigitalPostAccountInterface::class, DigitalPostAccount::class);
		$context->registerServiceAlias(AuthenticationConfigRemoverInterface::class, AuthenticationConfigRemover::class);
		$context->registerServiceAlias(MtlsConfigResolverInterface::class, MtlsConfigResolver::class);
		$context->registerServiceAlias(RecordOwnershipServiceInterface::class, RecordOwnershipService::class);
		$context->registerServiceAlias(DashboardDatasourceServiceInterface::class, DashboardDatasourceService::class);
		$context->registerServiceAlias(ExecutionTraceContextInterface::class, ExecutionTraceContext::class);
		$context->registerServiceAlias(SourceMappingServiceInterface::class, SourceMappingService::class);
		$context->registerServiceAlias(MailIntakeServiceInterface::class, MailIntakeService::class);
		$context->registerServiceAlias(FlowGraphMigrationServiceInterface::class, FlowGraphMigrationService::class);
		$context->registerServiceAlias(WebhookConnectionInterface::class, WebhookConnection::class);
		$context->registerServiceAlias(SynchronizationLogServiceInterface::class, SynchronizationLogService::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(BerichtenboxHealthInterface::class, BerichtenboxHealth::class);
		$context->registerServiceAlias(ZgwVersionTranslationServiceInterface::class, ZgwVersionTranslationService::class);
		$context->registerServiceAlias(SourceDestructionServiceInterface::class, SourceDestructionService::class);
		$context->registerServiceAlias(UnmappedActivitiesInterface::class, DsoUnmappedActivities::class);
		$context->registerServiceAlias(WebhookTrustMigratorInterface::class, WebhookTrustMigrator::class);
		$context->registerServiceAlias(Microsoft365AdapterInterface::class, Microsoft365Adapter::class);
		$context->registerServiceAlias(CtiEventIntakeInterface::class, CtiEventIntake::class);
		$context->registerServiceAlias(IntakeGroupsInterface::class, IntakeGroups::class);
		$context->registerServiceAlias(ObjecttypeEndpointHandlerInterface::class, ObjecttypeEndpointHandler::class);
		$context->registerServiceAlias(ActionAuthServiceInterface::class, ActionAuthService::class);
		$context->registerServiceAlias(LtiNrpsServiceInterface::class, LtiNrpsService::class);
		$context->registerServiceAlias(RecordProvenanceServiceInterface::class, RecordProvenanceService::class);
		$context->registerServiceAlias(SubscriptionRequestHandlerInterface::class, SubscriptionRequestHandler::class);
		$context->registerServiceAlias(SubscriptionRosterInterface::class, SubscriptionRoster::class);
		$context->registerServiceAlias(AgentBatchGateInterface::class, AgentBatchGate::class);
		$context->registerServiceAlias(ActivityOverviewInterface::class, DsoActivityOverview::class);
		$context->registerServiceAlias(WebhookGateInterface::class, WebhookGate::class);
		$context->registerServiceAlias(EnvironmentServiceInterface::class, EnvironmentService::class);
		$context->registerServiceAlias(FormsSyncAdapterInterface::class, FormsSyncAdapter::class);
		$context->registerServiceAlias(PortalConnectionAlertsInterface::class, DsoConnectionAlerts::class);
		$context->registerServiceAlias(IntakeDocumentDispatcherInterface::class, IntakeDocumentDispatcher::class);
		$context->registerServiceAlias(EndpointCorsPolicyInterface::class, EndpointCorsPolicy::class);
		$context->registerServiceAlias(SourceTestServiceInterface::class, SourceTestService::class);
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
		$context->registerServiceAlias(EgressGuardInterface::class, EgressGuard::class);
		$context->registerServiceAlias(SecurityServiceInterface::class, SecurityService::class);
		$context->registerServiceAlias(ResponseDecoderInterface::class, ResponseDecoder::class);
		$context->registerServiceAlias(OpenRegisterCredentialBridgeInterface::class, OpenRegisterCredentialBridge::class);
		$context->registerServiceAlias(WebhookProfilesInterface::class, WebhookProfiles::class);
		$context->registerServiceAlias(InlineSecretMigrationPlannerInterface::class, InlineSecretMigrationPlanner::class);
		$context->registerServiceAlias(InlineSecretMigrationExecutorInterface::class, InlineSecretMigrationExecutor::class);
		$context->registerServiceAlias(InlineSecretMigrationFactoryInterface::class, InlineSecretMigrationFactory::class);
		$context->registerServiceAlias(RegistryUpdateClientInterface::class, RegistryUpdateClient::class);
		$context->registerServiceAlias(AgentActionStoreInterface::class, AgentActionStore::class);
		$context->registerServiceAlias(CaseReferenceDetectorInterface::class, CaseReferenceDetector::class);
		$context->registerServiceAlias(LocalDeleteGuardInterface::class, LocalDeleteGuard::class);
		$context->registerServiceAlias(MessageParserInterface::class, MessageParser::class);
		$context->registerServiceAlias(DeadLetterProjectionInterface::class, DeadLetterProjection::class);
		$context->registerServiceAlias(MtlsTransportServiceInterface::class, MtlsTransportService::class);
		$context->registerServiceAlias(ExchangeTargetCatalogueInterface::class, ExchangeTargetCatalogue::class);
		$context->registerServiceAlias(MessageValidationServiceInterface::class, MessageValidationService::class);
		$context->registerServiceAlias(DigitalPostProviderRegistryInterface::class, DigitalPostProviderRegistry::class);
		$context->registerServiceAlias(DocumentConnectionGuardInterface::class, DocumentConnectionGuard::class);
		$context->registerServiceAlias(SubscriptionRegistryInterface::class, SubscriptionRegistry::class);
		$context->registerServiceAlias(DocumentProviderRegistryInterface::class, DocumentGenerationProviderRegistry::class);
		$context->registerServiceAlias(ToolPlatformDetailsInterface::class, LtiPlatformDetailsService::class);
		$context->registerServiceAlias(ActivityMappingTableInterface::class, DsoActivityTable::class);
		$context->registerServiceAlias(ApiVersionNegotiationInterface::class, ZgwVersionNegotiationService::class);
		$context->registerServiceAlias(SubscriptionSigningPolicyInterface::class, SubscriptionSigningPolicy::class);
	}//end registerInjectedHelpers()
}
