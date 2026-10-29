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
use OCA\Integriq\Service\ConnectionRegistryService;
use OCA\Integriq\Service\ConnectionRegistryServiceInterface;
use OCA\Integriq\Service\DSOParserService;
use OCA\Integriq\Service\DSOParserServiceInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\Integriq\Service\DigitalPost\DigitalPostServiceInterface;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationServiceInterface;
use OCA\Integriq\Service\DsoIngestService;
use OCA\Integriq\Service\DsoIngestServiceInterface;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\EndpointServiceInterface;
use OCA\Integriq\Service\EudiCredentialOfferService;
use OCA\Integriq\Service\EudiCredentialOfferServiceInterface;
use OCA\Integriq\Service\EudiStatusListService;
use OCA\Integriq\Service\EudiStatusListServiceInterface;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeJobRunner;
use OCA\Integriq\Service\Exchange\ExchangeJobRunnerInterface;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeJobServiceInterface;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionServiceInterface;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\ExecutionTraceServiceInterface;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\FlowRunnerServiceInterface;
use OCA\Integriq\Service\IBabsConnectorService;
use OCA\Integriq\Service\IBabsConnectorServiceInterface;
use OCA\Integriq\Service\IwmoIjwSyncService;
use OCA\Integriq\Service\IwmoIjwSyncServiceInterface;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\JobServiceInterface;
use OCA\Integriq\Service\JobToFlowGenerator;
use OCA\Integriq\Service\JobToFlowGeneratorInterface;
use OCA\Integriq\Service\KissSyncService;
use OCA\Integriq\Service\KissSyncServiceInterface;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\Integriq\Service\Lti\LtiAgsServiceInterface;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Service\Lti\LtiKeyServiceInterface;
use OCA\Integriq\Service\Lti\LtiLaunchService;
use OCA\Integriq\Service\Lti\LtiLaunchServiceInterface;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigratorInterface;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\Integriq\Service\NotificatiesSubscriberServiceInterface;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandlerInterface;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\Integriq\Service\OpenFormulierenIntakeServiceInterface;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Service\PaymentIntentServiceInterface;
use OCA\Integriq\Service\PeppolTransmissionService;
use OCA\Integriq\Service\PeppolTransmissionServiceInterface;
use OCA\Integriq\Service\PromotionService;
use OCA\Integriq\Service\PromotionServiceInterface;
use OCA\Integriq\Service\RuleToFlowGenerator;
use OCA\Integriq\Service\RuleToFlowGeneratorInterface;
use OCA\Integriq\Service\ServiceRegistrar;
use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Service\SmsDispatchServiceInterface;
use OCA\Integriq\Service\SoftwareCatalogueService;
use OCA\Integriq\Service\SoftwareCatalogueServiceInterface;
use OCA\Integriq\Service\StufZknSyncService;
use OCA\Integriq\Service\StufZknSyncServiceInterface;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCA\Integriq\Service\StufZkn\StufProviderReceiverInterface;
use OCA\Integriq\Service\StufZkn\StufZdsOutbound;
use OCA\Integriq\Service\StufZkn\StufZdsOutboundInterface;
use OCA\Integriq\Service\SynchronizationFlowGenerator;
use OCA\Integriq\Service\SynchronizationFlowGeneratorInterface;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\Integriq\Service\UserService;
use OCA\Integriq\Service\UserServiceInterface;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\UwlrEduVServiceInterface;
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
	];

	/**
	 * Each contract is aliased to its implementation, and nothing else is registered.
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
		$context->expects($this->never())->method('registerService');

		(new ServiceRegistrar())->register(context: $context);

		$this->assertSame(self::EXPECTED, $aliases);
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
