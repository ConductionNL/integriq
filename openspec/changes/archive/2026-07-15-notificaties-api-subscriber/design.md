# Design: notificaties-api-subscriber

## Context
OpenConnector already runs a full internal CloudEvents bus (`events-cloudevents` spec, `EventService`):
OR object lifecycle changes and NC-native events (`nextcloud-event-triggers`) are normalized into an
`event` OR-object, fanned out to matching `event_subscription`s, and delivered via
`action.kind ∈ {webhook, synchronization, job}` (REQ-008), with retry/backoff/dead-letter/replay for free.
Separately, `consumer-management` (REQ-CON-001) authenticates inbound calls against a `consumer` OR-object
via `AuthorizationService::authorizeApiKey()`. Neither surface currently understands the ZGW **Notificaties
API** wire protocol (abonnement/kanaal/notificatie), which several sibling gov connectors in this app
(StUF, VNG Klantinteracties, DSO, Digikoppeling) sit adjacent to.

**VERIFY-against-HEAD note (deviations from `context-brief.md`):** the brief names the CloudEvents spec as
`events-cloudevents` and the trigger spec as `nextcloud-event-hub` — at HEAD the former is the correct spec
directory name (`openspec/specs/events-cloudevents/`, confirmed to exist alongside a separate,
UI-focused `cloud-event-management` spec that is NOT the same capability), and the latter is the *archived
change name*; its live spec directory is `openspec/specs/nextcloud-event-triggers/`. Both are used
correctly by directory name throughout this design. `EventService::emitCloudEvent()` already exists at
HEAD (added for `peppol-access-point-connector`), generalized precisely for "a connector that needs a
domain-specific CloudEvent type" — this change is its second real consumer, confirming the abstraction
holds.

## Goals / Non-Goals

**Goals**
- Register/update/delete an `abonnement` on a remote Notificaties API for one or more `kanalen`.
- Authenticate and normalize inbound notifications into the existing CloudEvents pipe, so any
  `event_subscription` (webhook/synchronization/job) can react to a ZGW notification with zero new
  trigger code.
- Publish an internal CloudEvent onward to a Notificaties API `kanaal` in the correct wire shape, reusing
  the existing action-dispatch retry/backoff/dead-letter machinery.
- Zero new HTTP client, zero new auth stack, zero new event bus.

**Non-Goals**
- Hosting a Notificaties API server (`/kanaal`, `/abonnement`, `/notificaties` inbound CRUD for other
  systems) — out of scope per proposal.md.
- Autorisaties API scope enforcement — deferred (Open Question).
- Modifying `endpoint-runtime`'s `targetType` dispatch enum (see Decision 1).

## Decisions

### Decision 1 — Callback is a dedicated controller route, not a generic `endpoint-runtime` target (deviation from brief)
The brief says "reuse endpoint-runtime + consumer auth" for the callback. At HEAD, `endpoint-runtime`
dispatches on exactly two `targetType` values — `register/schema` (object CRUD) and `api` (Source proxy)
— per `openspec/specs/endpoint-runtime/spec.md` REQ-EP-003. Neither shape fits "parse a ZGW notification
body, verify it, normalize to a CloudEvent, and feed the CloudEvents fan-out" — doing this the
brief-literal way would mean adding a third `targetType` to the shared dispatch pipeline, a materially
larger and riskier change to a capability sixteen other capabilities depend on. The two closest precedents
in this codebase — `peppol-access-point-connector` REQ-005 (`POST /api/peppol/inbound`) and
`psd2-ais-bank-feed-connector` (`GET /api/psd2/callback`) — both solved the identical "receive a signed/
authenticated inbound callback and republish as a CloudEvent" problem with a **dedicated controller route**,
not a generic endpoint. This design follows that precedent: `NotificatiesSubscriberController::callback()`
at `POST /api/notificaties/callback/{abonnementId}`.

What IS genuinely reused, honouring the brief's actual intent, is **consumer auth**:
`AuthorizationService::authorizeApiKey($header, [])` and its private `resolveConsumerByApiKey()` are
already a public, DI-injectable contract, not endpoint-runtime-internal plumbing (verified at HEAD —
`AuthorizationService.php:670-765`). The callback controller calls `authorizeApiKey()` directly with the
`Authorization` header value; zero new auth code is written. See Decision 2 for how a `consumer` record is
provisioned per abonnement so this "just works."

**Alternative considered**: add `targetType = 'notificaties-callback'` to `endpoint-runtime`. Rejected —
touches a shared capability for one consumer's benefit, and the admin-configured `endpoint`/rule-pipeline
UI has no natural home for "which abonnement does this belong to," forcing a rule-config workaround that a
dedicated controller avoids entirely.

### Decision 2 — One `consumer` OR-object per abonnement, bound automatically
On abonnement creation, `NotificatiesSubscriberService::createAbonnement()` generates a random secret
(reusing the same `whsec_`-style generation approach as `webhook-signing` REQ-WHS-002, but without the
`whsec_` prefix since this is a bearer token, not an HMAC key), creates a `consumer` OR-object
(`authorizationType = 'apiKey'`, `authorizationConfiguration.apiKey = <secret>`, `name = "Notificaties
abonnement: <kanaal list>"`), stores `consumerId` on the `notificaties_abonnement` record, and sends the
same secret as the abonnement's `auth` field to the remote Notificaties API. Every subsequent inbound
delivery to the callback carries `Authorization: Bearer <secret>` (or a bare token, depending on target
implementation — see Decision 4), which `authorizeApiKey()` matches against this consumer via constant-time
comparison — REQ-CON-001's existing fail-closed behaviour applies unchanged. Deleting the abonnement MUST
cascade-delete its companion consumer (avoids orphan accumulation, Risk 2 in proposal.md).

**Alternative considered**: store the shared secret directly on the abonnement and write a bespoke
comparison in the controller. Rejected — this is exactly the "share the contract, not just the component"
trap: a second hand-rolled apiKey check would duplicate REQ-CON-001's constant-time-compare + fail-closed
guarantees and silently drift from them over time.

### Decision 3 — Publish is a new `event_subscription.action.kind = 'notificaties'`, not a bespoke publisher
`EventService::attemptDelivery()` already switches on `action.kind` (REQ-008: `webhook` | `synchronization`
| `job`). This change adds a fourth case, `notificaties`, with action shape
`{kind: 'notificaties', sourceId, kanaal, hoofdObjectField?, resourceField?, actieMap?}`:
`dispatchNotificatiesAction()` builds the ZGW notification body from the matched `event`'s CloudEvents
envelope —

```json
{
  "kanaal": "<action.kanaal>",
  "hoofdObject": "<derived from event.data, or event.subject when hoofdObjectField absent>",
  "resource": "<derived from event.type's trailing segment, or action.resourceField>",
  "resourceUrl": "<event.data.attributes.url, when present>",
  "actie": "<create|update|delete, derived from event.type suffix via actieMap, default 1:1 with
             com.nextcloud.openregister.object.{created→create,updated→update,deleted→destroy}>",
  "aanmaakdatum": "<event.time>",
  "kenmerken": "<action.kenmerken static map, merged with any event.data.kenmerken>"
}
```

and POSTs it via `CallService::call($source, '/notificaties', 'POST', ['json' => $body])` — inheriting
REQ-002's exact success/failure/retry/backoff/dead-letter bookkeeping verbatim, the same way `synchronization`
and `job` do today. `deliverMessage` (HTTP webhook) and `webhook-signing` are NOT invoked for this kind,
matching REQ-008's existing "kind='synchronization'/'job' MUST NOT invoke deliverMessage" rule extended to
the new kind.

**Alternative considered**: a standalone `NotificatiesPublisherService::publish()` called ad hoc by
producer code. Rejected — this is precisely the "fork the machinery" anti-pattern the brief's Constraints
section explicitly forbids; every producer would need its own retry/backoff/dead-letter re-implementation
instead of getting it for free from the existing `event_message` state machine.

### Decision 4 — Callback-verification handshake: shared-secret header, not a challenge/response ping (ASSUMPTION — flagged)
The ZGW Notificaties API standard (VNG-Realisatie `notificaties-api` OAS, the specification this app's
sibling ZGW connectors already target) does **not** define a WebSub/PubSubHubbub-style challenge/response
verification step (no `hub.challenge` equivalent). Verification is achieved by the `abonnement.auth` value
the subscriber supplies at registration time, which the Notificaties Routeer Component (NRC) is expected to
echo back as the `Authorization` header on every notification POST to `callbackUrl` — the subscriber's own
responsibility is to check that header, which Decision 2's consumer-apiKey binding does. **This is stated
as an assumption, not verified against a live Notificaties API instance or a specific implementation's OAS
(e.g. Open Notificaties/Common Ground reference implementation), per the task's instruction to flag
uncertain wire details rather than invent them.** If the target deployment's NRC implementation differs
(e.g. expects a distinct verification ping, or a header name other than `Authorization`), the callback
controller's header-name and scheme MUST be made configurable per-abonnement before General Availability —
tracked as a task, not designed away here.

### Decision 5 — Abonnement lifecycle is an OR object, not app-config
`notificaties_abonnement` is a new schema in `lib/Settings/openconnector_register.json` (same register as
`event_subscription`/`consumer`/`source`), following the existing lifecycle-status convention used by
`bankfeed_connection` (`pending → active → expired/revoked`): `pending` (not yet confirmed registered) →
`active` (registered, remote API accepted it) → `error` (registration/verify/delete call failed — retains
the last error for operator visibility) → `deleted` (soft-deleted after successful remote DELETE, kept for
audit rather than hard-removed, matching the `discarded`/`abandoned` terminal-but-queryable convention
`events-cloudevents` REQ-002/`dead-letter-replay` already use).

## Risks / Trade-offs
- [Risk] Decision 4's shared-secret-header assumption may not match every target NRC implementation →
  [Mitigation] header name/scheme made configurable per abonnement (tracked in tasks.md); documented as an
  explicit assumption here rather than silently hard-coded.
- [Risk] A `notificaties` action.kind failure is retried/backed-off identically to a webhook failure, but a
  malformed `kanaal`/`hoofdObject` mapping is a **configuration** error, not transient → [Mitigation] reuse
  REQ-008's existing "unrecognised kind is a configuration error, no retryCount increment" pattern for the
  analogous "action.kanaal missing" case, so a bad config surfaces once in the dead-letter view instead of
  burning the retry budget.
- [Risk] Per-abonnement consumer records (Decision 2) are a new place secrets live → [Mitigation] identical
  storage/redaction posture to every other `consumer.authorizationConfiguration.apiKey` (ADR-007 plaintext-
  in-OR-with-existing-redaction-on-export convention) — no new secret-handling code path.

## Migration Plan
See migration.md — additive OR schema only (new `notificaties_abonnement` schema entry, new `action.kind`
enum value on the existing `event_subscription.action` object), no SQL migration required (OR schemas are
schema-less JSON-column storage, per the `nextcloud-event-hub` precedent).

## Nextcloud Integration
- **Controllers:** new `NotificatiesSubscriberController` (`#[NoCSRFRequired]` on `callback()` only —
  inbound calls carry no NC session; abonnement CRUD methods are `#[NoAdminRequired]` + CSRF-protected,
  session-based, matching `EventsController`'s existing posture).
- **Services:** new `NotificatiesSubscriberService` (abonnement CRUD + notification normalization);
  extends `EventService` (new `dispatchNotificatiesAction()` private method, called from
  `attemptDelivery()`'s existing switch); reuses `CallService::call()` and
  `AuthorizationService::authorizeApiKey()`/`resolveConsumerByApiKey()` unmodified.
- **Mappers/Entities:** none — OR `ObjectEntity` generic storage, unchanged pattern (Controller → Service →
  OR `ObjectService`, ADR-008).
- **Events/Hooks:** none new — the existing `event_subscription`/`event_message` machinery is the hook.

## Security Considerations
- **Callback auth is fail-closed**: an unmatched or missing `Authorization` header is rejected before any
  side effect (no `event` OR-object is persisted, no fan-out runs), mirroring
  `peppol-access-point-connector` REQ-005's "unsigned callback rejected before any side effect" scenario
  and `webhook-signing` REQ-WHS-003's ordering guarantee (auth check runs before any body-transforming or
  side-effecting step).
- **Per-object IDOR carry-over, not introduced, not fixed here**: `events-cloudevents` REQ-005's Notes
  already flag that `EventsController`'s subscription endpoints have no per-object ownership check (any
  authed NC user can modify any subscription by UUID) — `notificaties_abonnement` CRUD endpoints inherit
  the SAME `#[NoAdminRequired]`-with-no-ownership-check posture as the existing `EventsController` pattern
  they're modeled on. This is an existing, already-flagged gap in the capability being extended, not a
  regression introduced by this change; closing it is out of scope here (same reasoning
  `nextcloud-event-hub`'s design.md used).
- **Secrets never logged**: the per-abonnement consumer secret (Decision 2) is generated server-side,
  stored only in `consumer.authorizationConfiguration.apiKey`, and MUST be redacted on every read surface
  the way every other consumer's apiKey already is — no new redaction code path, reuses the existing
  convention.
- **SSRF**: the abonnement's registration target and the publish target are both `Source.location` values,
  already subject to whatever SSRF posture `CallService`/`Source` configuration enforces today — unchanged.

## NL Design System
The Abonnementen manifest page and its create/edit modal reuse existing `NcSelect` (kanalen multi-select,
**MUST** set `inputLabel` per ADR-004 / `hydra-gate-nc-input-labels`), `NcTextField`, and
`NcCheckboxRadioSwitch` components already used by the Consumers/Webhooks editor and
`EventSubscription` modal — no new component patterns. WCAG AA unchanged (existing app baseline).

## File Structure
```
lib/
  Controller/
    NotificatiesSubscriberController.php   # NEW — abonnement CRUD + callback()
  Service/
    NotificatiesSubscriberService.php      # NEW — abonnement lifecycle + notification normalization
    EventService.php                        # + dispatchNotificatiesAction() switch case (REQ-008 extension)
  Settings/
    openconnector_register.json             # + notificaties_abonnement schema;
                                             #   event_subscription.action.kind + 'notificaties' enum value
src/
  views/NotificatiesAbonnement/
    NotificatiesAbonnementenPage.vue        # NEW — list/manage abonnementen
  modals/NotificatiesAbonnement/
    NotificatiesAbonnementForm.vue          # NEW — create/edit modal (NcSelect kanalen w/ inputLabel)
tests/
  Unit/Service/NotificatiesSubscriberServiceTest.php   # NEW — abonnement body, notification→CloudEvent mapping
  Unit/Service/EventServiceNotificatiesActionTest.php  # NEW — publish body shape, dispatch bookkeeping
  Integration/NotificatiesCallbackTest.php             # NEW — inbound notification triggers a synchronization
```

## Seed Data

### Schema: `notificaties_abonnement`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `zaken-kanaal-abonnement` | `documenten-kanaal-abonnement` | `besluiten-kanaal-abonnement-error` |
| sourceId | `<zgw-notificaties-source>` | `<zgw-notificaties-source>` | `<zgw-notificaties-source>` |
| kanalen | `[{"naam":"zaken","filters":{"bronorganisatie":"123443210"}}]` | `[{"naam":"documenten","filters":{}}]` | `[{"naam":"besluiten","filters":{}}]` |
| status | `active` | `active` | `error` |
| consumerId | `<generated>` | `<generated>` | `<generated>` |
| lastError | `null` | `null` | `"Remote API returned 503 during registration"` |

**Related items per object:** none (no files/notes/tasks/contacts apply to a machine-to-machine
subscription record).

## Trade-offs
Dedicated controller (Decision 1) trades "one more route to maintain" for "does not touch a
sixteen-consumer shared dispatch pipeline" — judged the right side of that trade given the blast radius of
`endpoint-runtime`. Per-abonnement consumer (Decision 2) trades "N consumer records instead of 1" for
"zero new auth code and zero drift from REQ-CON-001" — judged clearly worth it at gov-integration scale
(low cardinality, Risk 2 accepted with cascade-delete mitigation).
