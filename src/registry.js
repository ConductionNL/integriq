// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component + custom-handler registry for openconnector's
// manifest-driven app shell.
//
// Post chain-C cutover + post chain-E custom-Import drop: the page-
// component registry is empty (all 23 manifest pages use a standard
// nc-vue type — CnIndexPage / CnDetailPage / CnLogsPage /
// CnDashboardPage / CnSettingsPage — and resolve their CRUD against
// OR's `/api/objects/openconnector/{schema}/*` routes). Bulk import is
// now per-schema via CnIndexPage's built-in CnMassImportDialog action
// (Actions → Import on each index page) — the chain-A custom Import
// page was deleted because its backend `POST /api/import` endpoint had
// already been removed in chain-C, leaving the Vue page broken anyway.
//
// What lives here today:
//   1. Row-action handlers — small function entries called by
//      CnIndexPage when the user clicks a row Actions item whose
//      manifest `handler:` field matches one of the names below.
//      See src/handlers/actionHandlers.js for the implementations.
//   2. (future) Custom **widgets** for the genuinely-custom interactive
//      surfaces: MappingEditorWidget (drag-drop transformation),
//      RuleConditionsWidget (visual condition builder), CronBuilderWidget
//      (job-interval editor), EventSubscriptionsManagerWidget,
//      SourceTesterWidget (connection-test result panel), JobRunnerWidget
//      (run-job modal poll). These will register as v2 widget slots on
//      standard-type pages, NOT as entire custom pages.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …) — WIN for 23 pages
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) — escape hatch for handlers + future widgets

import {
	testSourceHandler,
	runJobHandler,
	testJobHandler,
	runSynchronizationHandler,
	testSynchronizationHandler,
	runSynchronizationStreamHandler,
	testSynchronizationStreamHandler,
	runFlowHandler,
	testMappingModalHandler,
	addEndpointRuleHandler,
	manageSigningHandler,
	viewLogsHandler,
	openConfigurationImportHandler,
	openConfigurationExportHandler,
	openPromotionHandler,
} from './handlers/actionHandlers.js'
import CatalogItemCard from './components/CatalogItemCard.vue'
import JobFormFields from './modals/v2/JobFormFields.vue'
import SourceFormFields from './modals/v2/SourceFormFields.vue'
import SubscriptionActionFields from './modals/EventSubscription/SubscriptionActionFields.vue'
import EventDeliveriesPage from './views/EventDelivery/EventDeliveriesPage.vue'
import SyncDeadLetterPage from './views/Synchronization/SyncDeadLetterPage.vue'
import MappingDetailPage from './views/wrappers/MappingDetailPage.vue'
import RuleDetailPage from './views/Rule/RuleDetailPage.vue'
import SynchronizationDetailPage from './views/Synchronization/SynchronizationDetailPage.vue'
import FlowDetailPage from './views/Flow/FlowDetailPage.vue'
import ApprovalsIndex from './views/Approvals/ApprovalsIndex.vue'
import ApprovalDetail from './views/Approvals/ApprovalDetail.vue'
import ApiProductDetail from './views/ApiProducts/ApiProductDetail.vue'
import CircuitBreakerBadge from './components/CircuitBreakerBadge.vue'
import NotificatiesAbonnementenPage from './views/NotificatiesAbonnement/NotificatiesAbonnementenPage.vue'
import TraceDetailPage from './views/ExecutionTrace/TraceDetailPage.vue'

export default {
	// Row-action handlers — referenced by manifest `config.actions[].handler` strings.
	testSourceHandler,
	runJobHandler,
	testJobHandler,
	runSynchronizationHandler,
	testSynchronizationHandler,
	// #1082: live-output console variants — open a streamed run/test in a console
	// modal instead of firing and forgetting.
	runSynchronizationStreamHandler,
	testSynchronizationStreamHandler,
	// Flows index row action (visual-flow-orchestration): manual run trigger.
	runFlowHandler,
	// Modal-opening row-action handlers — emit on the shared modal bus,
	// the App.vue-mounted ModalHost picks up and renders the modal.
	testMappingModalHandler,
	addEndpointRuleHandler,
	// Webhook signing-secret manager (opens SubscriptionSigningModal via
	// the modal bus). See openconnector-webhook-signing.
	manageSigningHandler,
	// Query-aware navigation handler for "View logs" row actions on
	// parent index pages (#837). Pushes `?<queryParam>=<rowId>` onto the
	// destination *Logs route. Will be retired once nc-vue#330 lands a
	// declarative `queryParam` field on the built-in `navigate` handler.
	viewLogsHandler,
	// Catalog page header actions (connector-catalog-ui): open the
	// configuration import-preview / export dialogs via the modal bus.
	openConfigurationImportHandler,
	openConfigurationExportHandler,
	// Environments page header action (environments-and-promotion): open the
	// promote-configuration flow via the modal bus.
	openPromotionHandler,

	// Card component for the Catalog index page (connector-catalog-ui):
	// referenced by `pages[].config.cardComponent: "CatalogItemCard"`.
	// Clicking a card opens CatalogItemDetailDialog through the modal bus.
	CatalogItemCard,

	// Slot-override components — referenced by manifest `pages[].slots`
	// keys. The Jobs page wires `form-fields` to JobFormFields so the
	// CnFormDialog inner content renders a Synchronization picker when
	// `jobClass === OCA\OpenConnector\Action\SynchronizationAction`
	// (per #847). Open follow-up upstream: CnFormDialog has no native
	// per-field `condition`/`visibleWhen` prop — tracked as a ncv issue.
	JobFormFields,

	// The Sources page wires `form-fields` to SourceFormFields so the
	// CnFormDialog authentication section can offer a brokered-credential
	// (credentialRef) picker backed by OpenRegister's credential broker, and
	// hide the embedded-secret fields while brokered (openconnector#102).
	SourceFormFields,

	// The Webhooks (event_subscription) page wires `form-fields` to
	// SubscriptionActionFields so the CnFormDialog offers a delivery-action
	// kind picker (Webhook/Synchronization/Job) and an optional custom
	// retry-policy block — neither is a declarative schema widget. See
	// nextcloud-event-hub REQ-008/REQ-009.
	SubscriptionActionFields,

	// Custom-page components — referenced by manifest `pages[].component`
	// when `pages[].type === 'custom'`. The 3 bespoke editors below
	// (Mapping #832, Rule #833, Synchronization #834) wrap CnDetailPage
	// with surfaces too rich for schema-driven detail pages — see each
	// component's JSDoc for the per-section rationale.
	MappingDetailPage,
	RuleDetailPage,
	SynchronizationDetailPage,

	// Flow detail (custom page): the ordered step-list editor + manual Run +
	// run-log tab that a generic detail page cannot express. See
	// visual-flow-orchestration REQ-009.
	FlowDetailPage,

	// Dead-letter operations view (custom page): a filtered event_message
	// surface backed by the admin-only /api/events/dead-letter endpoints with
	// per-row + bulk Replay/Discard. See openconnector-dead-letter-replay.
	EventDeliveriesPage,

	// HITL Pending Approvals (custom pages): a filtered approval_request
	// surface backed by the two-layer-authorized /api/approvals endpoints
	// with per-row navigation and approve/reject verbs. Not expressible as a
	// generic CnIndexPage. See hitl-approval-rule-action.
	ApprovalsIndex,
	ApprovalDetail,

	// API Products gateway detail (custom page): endpoint picker, tier
	// editor, gateway analytics panel, and pending-subscription approve/
	// reject actions for one api_product. Not expressible as a generic
	// CnIndexPage/detail page. See api-product-gateway.
	ApiProductDetail,

	// Sync-item dead-letter operations view (custom page): a filtered
	// sync_item_dead_letter surface backed by the admin-only
	// /api/sync-dead-letter endpoints with per-row + bulk Replay/Discard.
	// See retry-and-circuit-breaker-policies (REQ-DLR-007..012).
	SyncDeadLetterPage,

	// Source detail circuit-breaker badge (declarative body section on
	// SourceDetail via config.bodyWidgets): shows breaker state + failure
	// count + cooldown countdown with a Reset action. See
	// retry-and-circuit-breaker-policies (REQ-009).
	CircuitBreakerBadge,

	// ZGW Notificaties API Abonnementen (custom page): abonnement CRUD
	// backed by the dedicated NotificatiesSubscriberController endpoints
	// (create/update/delete also register/update/delete against the remote
	// Notificaties API and provision/cascade-delete a companion consumer) —
	// not the generic OR object CRUD a CnIndexPage drives. See
	// notificaties-api-subscriber REQ-008.
	NotificatiesAbonnementenPage,

	// Execution trace detail (custom page): step-timeline + dry-run/forced
	// Replay over one execution_trace, backed by the
	// ExecutionTracesController REST surface. The list itself uses the
	// generic `type: logs` CnLogsPage (Traces manifest page) — mirrors the
	// SourceLogs/EndpointLogs/CloudEventLogs precedent — so only the detail
	// view needs a bespoke component. See execution-trace-observability.
	TraceDetailPage,
}

// V2 component registry (ADR-036). Under @conduction/nextcloud-vue@2, CnAppRoot
// resolves a `type:"custom"` page's `component` string against the `registry`
// prop, matching on `kind: 'page'` (CnPageRenderer.resolveCustomComponent with
// requireKind='page'); the legacy `customComponents` string map above is
// deprecated for v2 manifests and no longer drives page rendering — passing it
// alone left CnAppRoot with zero resolvable pages and the shell rendered blank.
//
// Every entry below corresponds 1:1 to a manifest `type:"custom"` page's
// `component` key (11 pages: ApiProductDetail, NotificatiesAbonnementen,
// MappingDetail, RuleDetail, SynchronizationDetail, EventDeliveries, Approvals,
// SyncDeadLetters, FlowDetail, ApprovalDetail, TraceDetail). The `page` kind
// requires no metadata beyond `component`.
//
// The remaining registry-resolved surfaces — row-action HANDLERS (functions),
// the Catalog card (`config.cardComponent`), the CnFormDialog `form-fields`
// slot components, and the CircuitBreakerBadge body section — continue to
// resolve through the legacy `customComponents` default export above:
// CnPageRenderer resolves slot/card/section components and create-overrides by
// name with no `kind` constraint (requireKind=null), so its legacy fallback
// still applies to them. Only `type:"custom"` PAGES need the kind-tagged
// registry, so only they are listed here.
export const registry = {
	ApiProductDetail: { kind: 'page', component: ApiProductDetail },
	NotificatiesAbonnementenPage: { kind: 'page', component: NotificatiesAbonnementenPage },
	MappingDetailPage: { kind: 'page', component: MappingDetailPage },
	RuleDetailPage: { kind: 'page', component: RuleDetailPage },
	SynchronizationDetailPage: { kind: 'page', component: SynchronizationDetailPage },
	EventDeliveriesPage: { kind: 'page', component: EventDeliveriesPage },
	ApprovalsIndex: { kind: 'page', component: ApprovalsIndex },
	SyncDeadLetterPage: { kind: 'page', component: SyncDeadLetterPage },
	FlowDetailPage: { kind: 'page', component: FlowDetailPage },
	ApprovalDetail: { kind: 'page', component: ApprovalDetail },
	TraceDetailPage: { kind: 'page', component: TraceDetailPage },
}
