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

use OCA\Integriq\Service\Adapter\DataInfra\S3Adapter;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\AgentTools\HermiqVerdictClient;
use OCA\Integriq\Service\AgentTools\HttpHermiqVerdictClient;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettings;
use OCA\Integriq\Service\DigitalPost\BerichtenboxSourceSettingsInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\DigitalPost\DigitalPostServiceInterface;
use OCA\Integriq\Service\DigitalPost\LogDigitalPostProvider;
use OCA\Integriq\Service\DigitalPost\PostexProvider;
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
use OCA\Integriq\Service\Forms\FormsClientInterface;
use OCA\Integriq\Service\Forms\FormsOcsClient;
use OCA\Integriq\Service\Integration\SynchronizationContractProvider;
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
use OCA\Integriq\Service\Objecten\ObjectenWiring;
use OCA\Integriq\Service\Oso\LogOsoProvider;
use OCA\Integriq\Service\Oso\OsoKennisnetClient;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\Integriq\Service\Registry\KvkMutatieProvider;
use OCA\Integriq\Service\Registry\LogSubscriptionProvider;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Rod\LogRodProvider;
use OCA\Integriq\Service\Rod\RodEdukoppelingClient;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufProviderReceiverInterface;
use OCA\Integriq\Service\StufZkn\StufZdsOutbound;
use OCA\Integriq\Service\StufZkn\StufZdsOutboundInterface;
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
}
