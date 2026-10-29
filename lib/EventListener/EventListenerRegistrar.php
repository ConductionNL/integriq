<?php

/**
 * Integriq EventListenerRegistrar.
 *
 * Registers the listeners of the EventListener component.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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

namespace OCA\Integriq\EventListener;

use OCA\DAV\Events\CachedCalendarObjectCreatedEvent;
use OCA\DAV\Events\CachedCalendarObjectDeletedEvent;
use OCA\DAV\Events\CachedCalendarObjectUpdatedEvent;
use OCA\Forms\Events\FormSubmittedEvent;
use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Event\DigitalPostSendRequestedEvent;
use OCA\Integriq\Event\DocumentFetchRequestedEvent;
use OCA\Integriq\Event\DocumentRenderRequestedEvent;
use OCA\Integriq\Event\DocumentSearchRequestedEvent;
use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\Integriq\Event\GatewayDeliveryRequestedEvent;
use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Integriq\Event\MappingExecutionRequestedEvent;
use OCA\Integriq\Event\OptOutChangeRequestedEvent;
use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\Integriq\Event\RosterImportRequestedEvent;
use OCA\Integriq\Event\SourceRequestedEvent;
use OCA\Integriq\Event\StufProviderRequestReceivedEvent;
use OCA\Integriq\Event\StufZdsCaseRequestedEvent;
use OCA\Integriq\Event\StufZdsConfirmationReceivedEvent;
use OCA\Integriq\Service\PeppolOutboundConsumer;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\Tables\Event\RowAddedEvent;
use OCA\Tables\Event\RowDeletedEvent;
use OCA\Tables\Event\RowUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\SystemTag\MapperEvent;

/**
 * Registers the listeners of the EventListener component, so Application does
 * not name the listener classes.
 *
 * Naming every listener and its event is what a registrar is for, so the
 * coupling between objects is high by design.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class EventListenerRegistrar {
	/**
	 * Register the listeners that Nextcloud attaches after register() returns.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's listeners and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerConnectionAndDocumentListeners(context: $context);
		$this->registerExchangeListeners(context: $context);
		$this->registerRosterAndLaunchListeners(context: $context);
	}//end register()

	/**
	 * Register the listeners on the event dispatcher, in the order they must run.
	 *
	 * Listeners on the same event run in registration order, so this order is
	 * part of the behaviour.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's listeners and has no behaviour of its own
	 */
	public function registerListeners(IEventDispatcher $dispatcher): void {
		$this->registerObjectWriteListeners(dispatcher: $dispatcher);
		$this->registerObjectSaveGuards(dispatcher: $dispatcher);
		$this->registerOutboundEventListeners(dispatcher: $dispatcher);
		$this->registerSiblingRequestListeners(dispatcher: $dispatcher);
		$this->registerEndpointCacheListeners(dispatcher: $dispatcher);
		$this->registerNextcloudCoreListeners(dispatcher: $dispatcher);
	}//end registerListeners()

	/**
	 * Register the Tables row listeners; the caller checks that Tables is enabled.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 */
	public function registerTablesListeners(IEventDispatcher $dispatcher): void {
		$dispatcher->addServiceListener(eventName: RowAddedEvent::class, className: NextcloudTablesEventListener::class);
		$dispatcher->addServiceListener(eventName: RowUpdatedEvent::class, className: NextcloudTablesEventListener::class);
		$dispatcher->addServiceListener(eventName: RowDeletedEvent::class, className: NextcloudTablesEventListener::class);
	}//end registerTablesListeners()

	/**
	 * Register the Forms submission listener; the caller checks that Forms is enabled.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md
	 */
	public function registerFormsListeners(IEventDispatcher $dispatcher): void {
		$dispatcher->addServiceListener(eventName: FormSubmittedEvent::class, className: NextcloudFormsEventListener::class);
	}//end registerFormsListeners()

	/**
	 * Registers the connection, document, mapping and gateway listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerConnectionAndDocumentListeners(IRegistrationContext $context): void {
		// Connection registry (connection-registry D5/D6): apps report a
		// connection status or ask for a fresh resolve with two typed events,
		// and enabling or disabling an app syncs or resolves its declared
		// connections. Every listener resolves its service lazily and never
		// throws into the sender.
		$context->registerEventListener(ConnectionStatusReportedEvent::class, ConnectionStatusReportedListener::class);
		$context->registerEventListener(ConnectionRefreshRequestedEvent::class, ConnectionRefreshRequestedListener::class);
		$context->registerEventListener(AppEnableEvent::class, ConnectionAppLifecycleListener::class);
		$context->registerEventListener(AppDisableEvent::class, ConnectionAppLifecycleListener::class);
		// Document generation (document-generation-vendor-adapter REQ-DGV-002):
		// filinq asks for a vendor render with a typed command and reads the
		// job id, or the structured refusal, off the same instance.
		$context->registerEventListener(
			DocumentRenderRequestedEvent::class,
			DocumentRenderRequestedListener::class
		);
		// A sibling runs a mapping by slug (mapping-woo-index-field-mapping
		// REQ-WOOM-001): only an app the mapping lists in callableBy.
		$context->registerEventListener(
			MappingExecutionRequestedEvent::class,
			MappingExecutionRequestedListener::class
		);
		// A sibling sends through a statutory gateway (statutory-gateways-and-
		// frameworks REQ-SG-010): the caller the CORV, GGK, WKPB and publication
		// adapters lacked.
		$context->registerEventListener(
			GatewayDeliveryRequestedEvent::class,
			GatewayDeliveryRequestedListener::class
		);
	}//end registerConnectionAndDocumentListeners()

	/**
	 * Registers the exchange, StUF and acknowledgement listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerExchangeListeners(IRegistrationContext $context): void {
		// Exchange jobs another app owns (learniq-exchange-jobs-native): the
		// owning app asks integriq to carry a job, or to store its own
		// mapping, with two typed commands (ADR-041). The SWV hand-off client
		// is bound to its dormant mock, the only binding that exists, so the
		// dispatcher that routes `swv` jobs can be built at all.
		// A decision on a mirrored approval task in the shared OpenRegister
		// inbox, or the shared sweep's expiry of it, resolves the approval
		// request (hitl-on-shared-tasks 2.1, 2.2). Only committed dispatches
		// act: the listener may resume a run.
		$context->registerEventListener(TaskTerminalEvent::class, SharedApprovalTaskListener::class);
		$context->registerEventListener(ExchangeJobRequestedEvent::class, ExchangeJobRequestedListener::class);
		// StUF-ZDS case messages a sibling app asks for (decision 193: dossiq's
		// creeerZaak/actualiseerZaak/geefZaakdetails/genereerZaakIdentificatie/vrijBericht).
		$context->registerEventListener(StufZdsCaseRequestedEvent::class, StufZdsCaseRequestedListener::class);
		// A confirmation a sibling app received on its own wire-contract URL (dossiq /api/stuf/inbound, one release).
		$context->registerEventListener(StufZdsConfirmationReceivedEvent::class, StufZdsConfirmationReceivedListener::class);
		// A provider request a sibling app received on its own wire-contract URL (dossiq /api/stuf/cases and friends, one release, decision 199).
		$context->registerEventListener(StufProviderRequestReceivedEvent::class, StufProviderRequestReceivedListener::class);
		$context->registerEventListener(ExchangeMappingRequestedEvent::class, ExchangeMappingRequestedListener::class);
		// An authority's later retour on an exchange job's record
		// (connectors-data-exchange-dispatch REQ-013): one listener on the
		// four adapters' acknowledgement events.
		foreach (array_keys(ExchangeAcknowledgementListener::ADAPTER_OF) as $acknowledgement) {
			$context->registerEventListener($acknowledgement, ExchangeAcknowledgementListener::class);
		}
	}//end registerExchangeListeners()

	/**
	 * Registers the roster import and LTI launch listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerRosterAndLaunchListeners(IRegistrationContext $context): void {
		// Rostering into planninq (rostering-adapter-targets-planninq,
		// decision D10): learniq's timetable-import job asks integriq to
		// deliver a rostering source; the listener always answers on the
		// event, delivered or failed with an error code.
		$context->registerEventListener(
			RosterImportRequestedEvent::class,
			RosterImportRequestedListener::class
		);
		// LTI platform launch (connectors-lti-platform-launch REQ-LTIL-001):
		// learniq raises a typed launch request and reads the login
		// initiation form, or the named refusal, off the same instance.
		$context->registerEventListener(
			LtiLaunchRequestedEvent::class,
			LtiLaunchRequestedListener::class
		);
	}//end registerRosterAndLaunchListeners()

	/**
	 * Registers the listeners on OpenRegister object writes and deletes.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerObjectWriteListeners(IEventDispatcher $dispatcher): void {
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: ObjectCreatedEventListener::class);

		// Spec registry-subscription-connector Task 3: the binding that was blocked
		// on OpenRegister shipping the event. It has, and dispatches it from
		// RegistrySubscriptionNotifier, so the wire shape is read rather than
		// guessed.
		$dispatcher->addServiceListener(
			eventName: RegistrySubscriptionRequestedEvent::class,
			className: RegistrySubscriptionRequestedListener::class
		);
		$dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: ObjectUpdatedEventListener::class);
		$dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ViewDeletedEventListener::class);
		$dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: ObjectDeletedEventListener::class);
		// REQ-SOR-005 (records-owned-by-an-external-source): every delete passes
		// OpenRegister's stoppable ObjectDeletingEvent, so the refusal of a
		// source-owned record holds whichever page or app deletes it.
		$dispatcher->addServiceListener(eventName: ObjectDeletingEvent::class, className: SourceOwnedDeleteGuardListener::class);
	}//end registerObjectWriteListeners()

	/**
	 * Registers the guards that run on OpenRegister's creating and updating events.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerObjectSaveGuards(IEventDispatcher $dispatcher): void {
		// REQ-DSO-010 (dso-activity-mapping-table): a DSO activity mapping row
		// needs an imowId or an activityId, and two active rows may not share an
		// imowId; refused on OpenRegister's own save path.
		$dispatcher->addServiceListener(eventName: ObjectCreatingEvent::class, className: DsoActivityMappingGuardListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatingEvent::class, className: DsoActivityMappingGuardListener::class);
		// REQ-SOW-001 (signed-outbound-webhooks): the Webhooks page saves a
		// subscription through OpenRegister's object API, so the signing
		// default and the unsigned-needs-a-reason refusal run on its stoppable
		// creating/updating events, whichever page or app saves it.
		$dispatcher->addServiceListener(eventName: ObjectCreatingEvent::class, className: SubscriptionSigningDefaultListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatingEvent::class, className: SubscriptionSigningDefaultListener::class);
		// REQ-MSV-001 (mapping-message-schema-validation): a message schema whose
		// document does not parse for its kind is refused on OpenRegister's own
		// save path, so the refusal holds whichever page or app saves it.
		$dispatcher->addServiceListener(eventName: ObjectCreatingEvent::class, className: MessageSchemaDocumentListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatingEvent::class, className: MessageSchemaDocumentListener::class);
		// Live defect I1: an endpoint's endpointRegex and endpointArray follow its
		// path on every save, as the EndpointMapper did before the OR cutover.
		$dispatcher->addServiceListener(eventName: ObjectCreatingEvent::class, className: EndpointRoutingListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatingEvent::class, className: EndpointRoutingListener::class);
		// REQ-CON-DSO-001 (dso-intake-through-an-integriq-connection): at most one
		// dso-stam consumer, so the STAM intake never has to guess its account.
		$dispatcher->addServiceListener(eventName: ObjectCreatingEvent::class, className: DsoStamConsumerListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatingEvent::class, className: DsoStamConsumerListener::class);
	}//end registerObjectSaveGuards()

	/**
	 * Registers the listeners that turn object events into outbound messages.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerOutboundEventListeners(IEventDispatcher $dispatcher): void {
		// Peppol-access-point-connector: reacts to nl.conduction.peppol.outbound.requested
		// CloudEvents (register `openconnector` — the OpenRegister register slug,
		// frozen across the app-id rename; schema event) created by any app.
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: PeppolOutboundConsumer::class);
		// Outbound webhooks / CloudEvent subscriptions: turns any OR object
		// create/update/delete (any app) into a CloudEvent fanned out to
		// matching `event_subscription`s (events-cloudevents spec REQ-004).
		// Internally gated (EventService::hasActiveSubscriptions) so an
		// install with no configured subscriptions pays no persistence cost,
		// and guards against re-forwarding its own `event`/`event_message`
		// writes (would otherwise recurse — see CloudEventListener docblock).
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: CloudEventListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: CloudEventListener::class);
		$dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: CloudEventListener::class);
	}//end registerOutboundEventListeners()

	/**
	 * Registers the listeners for typed requests that sibling apps dispatch.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerSiblingRequestListeners(IEventDispatcher $dispatcher): void {
		// ADR-041 cross-app delivery seam: a sibling app (dossiq, ...) raises
		// a typed DeliveryRequestedEvent; this listener ingests it into the
		// same CloudEvents pipeline (subscription routing, retry, dead-letter,
		// replay) and writes the synchronous result slot back on the event.
		$dispatcher->addServiceListener(eventName: DeliveryRequestedEvent::class, className: DeliveryRequestedListener::class);
		$dispatcher->addServiceListener(eventName: DigitalPostSendRequestedEvent::class, className: DigitalPostSendRequestedListener::class);
		// Opt-out-before-send (REQ-OOA-002, REQ-OOA-003): a sibling app asks
		// whether it may message these people, and records a person's wish.
		// Both answer synchronously from integriq's own opt-out table.
		$dispatcher->addServiceListener(eventName: OutboundSendDecisionRequestedEvent::class, className: OutboundSendDecisionRequestedListener::class);
		$dispatcher->addServiceListener(eventName: OptOutChangeRequestedEvent::class, className: OptOutChangeRequestedListener::class);
		// A sibling app that still holds a call to a plain URL (dossiq's retired
		// webhook steps) asks for the Source for that base URL here, so the call
		// can run through `openconnector.source-call` like every other one.
		$dispatcher->addServiceListener(eventName: SourceRequestedEvent::class, className: SourceRequestedListener::class);
		// Connectors-graph-document-search D3: a sibling app (dossiq's Gather documents) searches and fetches Microsoft 365.
		$dispatcher->addServiceListener(eventName: DocumentSearchRequestedEvent::class, className: DocumentSearchRequestedListener::class);
		$dispatcher->addServiceListener(eventName: DocumentFetchRequestedEvent::class, className: DocumentFetchRequestedListener::class);
	}//end registerSiblingRequestListeners()

	/**
	 * Registers the endpoint routing cache invalidation.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerEndpointCacheListeners(IEventDispatcher $dispatcher): void {
		// Endpoint routing cache: clear it whenever an `openconnector`/endpoint
		// (register slug frozen across the app-id rename)
		// object is created, updated, or deleted so the runtime path matcher
		// (EndpointCacheService) never serves stale routing (self-gated on
		// register+schema slug, so unrelated object writes are a cheap no-op).
		$dispatcher->addServiceListener(eventName: ObjectCreatedEvent::class, className: EndpointCacheInvalidationListener::class);
		$dispatcher->addServiceListener(eventName: ObjectUpdatedEvent::class, className: EndpointCacheInvalidationListener::class);
		$dispatcher->addServiceListener(eventName: ObjectDeletedEvent::class, className: EndpointCacheInvalidationListener::class);
	}//end registerEndpointCacheListeners()

	/**
	 * Registers the Nextcloud file, tag and calendar event listeners.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 */
	private function registerNextcloudCoreListeners(IEventDispatcher $dispatcher): void {
		// Files (REQ-001) — unconditional, stable OCP.
		$dispatcher->addServiceListener(eventName: NodeCreatedEvent::class, className: NextcloudFileEventListener::class);
		$dispatcher->addServiceListener(eventName: NodeWrittenEvent::class, className: NextcloudFileEventListener::class);
		$dispatcher->addServiceListener(eventName: NodeDeletedEvent::class, className: NextcloudFileEventListener::class);
		$dispatcher->addServiceListener(eventName: MapperEvent::class, className: NextcloudFileTagEventListener::class);

		// Calendar (REQ-002) — unconditional; `dav` ships with every
		// instance. Both the NC32+ OCP "own calendar" family and the
		// always-available OCA "cached subscription" family are wired so
		// one listener normalizes whichever fires (see
		// NextcloudCalendarEventListener's class docblock).
		$dispatcher->addServiceListener(eventName: CalendarObjectCreatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CalendarObjectUpdatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CalendarObjectDeletedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CachedCalendarObjectCreatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CachedCalendarObjectUpdatedEvent::class, className: NextcloudCalendarEventListener::class);
		$dispatcher->addServiceListener(eventName: CachedCalendarObjectDeletedEvent::class, className: NextcloudCalendarEventListener::class);
	}//end registerNextcloudCoreListeners()
}
