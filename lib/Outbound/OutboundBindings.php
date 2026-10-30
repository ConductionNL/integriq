<?php

/**
 * Integriq OutboundBindings.
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

use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Outbound\Call\BodyCapturePolicyInterface;
use OCA\Integriq\Outbound\Call\CallDispatcherInterface;
use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Outbound\Call\CallRecorderInterface;
use OCA\Integriq\Outbound\Call\CallReplayService;
use OCA\Integriq\Outbound\Call\CallReplayServiceInterface;
use OCA\Integriq\Outbound\Call\CallServiceDispatcher;
use OCA\Integriq\Outbound\Call\VerdictService;
use OCA\Integriq\Outbound\Call\VerdictServiceInterface;
use OCA\Integriq\Outbound\Identity\DnsResolverInterface;
use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use OCA\Integriq\Outbound\Identity\DomainAlignmentCheckerInterface;
use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\Identity\HoldQueueInterface;
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\OptOutCategoriesInterface;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\OptOutRegistryInterface;
use OCA\Integriq\Outbound\Identity\OptOutTableMigrator;
use OCA\Integriq\Outbound\Identity\OptOutTableMigratorInterface;
use OCA\Integriq\Outbound\Identity\RecipientKey;
use OCA\Integriq\Outbound\Identity\RecipientKeyInterface;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\Identity\SenderIdentityServiceInterface;
use OCA\Integriq\Outbound\Identity\SystemDnsResolver;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each Outbound contract to the class that implements it, so callers
 * outside the Outbound component depend on the contract only.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 *
 * Naming every class it wires is what a registrar is for, so the coupling
 * between objects is high by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class OutboundBindings {
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
		$context->registerServiceAlias(OutboundRetryServiceInterface::class, OutboundRetryService::class);
		$context->registerServiceAlias(OptOutCategoriesInterface::class, OptOutCategories::class);
		$context->registerServiceAlias(OutboundSendGateInterface::class, OutboundSendGate::class);
		$context->registerServiceAlias(DomainAlignmentCheckerInterface::class, DomainAlignmentChecker::class);
		$context->registerServiceAlias(OptOutTableMigratorInterface::class, OptOutTableMigrator::class);
		$context->registerServiceAlias(HoldQueueInterface::class, HoldQueue::class);
		$context->registerServiceAlias(RecipientKeyInterface::class, RecipientKey::class);
		$context->registerServiceAlias(VerdictServiceInterface::class, VerdictService::class);
		$context->registerServiceAlias(ForwardServiceInterface::class, ForwardService::class);
		$context->registerServiceAlias(LastContactQueryInterface::class, LastContactQuery::class);
		$context->registerServiceAlias(BodyCapturePolicyInterface::class, BodyCapturePolicy::class);
		$context->registerServiceAlias(MessageBodyReaderInterface::class, MessageBodyReader::class);
		$this->registerAppOwnedBindings(context: $context);
	}
	/**
	 * Binds the two app-owned interfaces that constructors take bare.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerAppOwnedBindings(IRegistrationContext $context): void {
		// Two app-owned interfaces injected BARE as required constructor
		// parameters, with exactly one implementation each and nothing binding
		// them. Without these lines Nextcloud's container cannot construct
		// DomainAlignmentChecker or CallReplayService, so SenderIdentityController
		// and CallLogController cannot be built and their NINE routes answer 500 —
		// including `GET /unsubscribe/{token}`, the opt-out link in outbound mail,
		// which is the one a recipient follows rather than an operator.
		//
		// Nothing caught it: psalm and phpstan are green because the types are
		// right, the unit suite is green because it binds its own fixtures, and
		// the route-reachability gate checks that a route resolves to a class and
		// method, not that the class can be INSTANTIATED. Both interfaces arrived
		// with their implementation and a test fixture and missed only this line
		// (integriq#1983 review 5278999788, from PRs #2060 and #2063).
		//
		// AppOwnedInterfaceBindingTest pins the property rather than these two
		// instances, so the next bare interface fails a test instead of a route.
		$context->registerServiceAlias(DnsResolverInterface::class, SystemDnsResolver::class);
		$context->registerServiceAlias(CallDispatcherInterface::class, CallServiceDispatcher::class);
	}//end registerAppOwnedBindings()
}
