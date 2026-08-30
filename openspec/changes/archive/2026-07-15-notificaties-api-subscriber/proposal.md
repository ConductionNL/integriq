# Proposal: notificaties-api-subscriber

## Summary
OpenConnector already emits and consumes NL GOV CloudEvents internally (`events-cloudevents` /
`nextcloud-event-triggers`), but has no way to act as a ZGW **Notificaties API** participant — the
publish/subscribe protocol (Logius/VNG "API Notificatiestandaard voor ZGW APIs") that Dutch-gov ZGW
components (Zaken API, Documenten API, etc.) use to announce object lifecycle changes over `kanalen`
(channels). This change adds SUBSCRIBER capability (register an `abonnement`, receive `kanaal`
notifications at a callback endpoint, route them into a synchronization/job/webhook) and PUBLISHER
capability (send an internal CloudEvent onward to a configured Notificaties API `kanaal` in the ZGW
notification body shape), both built entirely on existing machinery — `EventService`'s CloudEvents fan-out
and action-dispatch, `CallService`, and `consumer-management`'s apiKey authentication — with no new
event bus, no new HTTP client, and no new auth stack.

## Motivation
Every ZGW-adjacent connector this app already ships (StUF, VNG Klantinteracties, DSO, Digikoppeling)
sits next to gov components that publish or expect Notificaties API traffic. Without subscriber/publisher
support, OpenConnector can only be reached by point-to-point webhooks configured out-of-band, which is not
how ZGW components discover each other — they publish to `kanalen` and expect consumers to self-register
an `abonnement`. Shipping this closes a recurring integration gap and lets a `synchronization` be triggered
directly off a `zaken`/`documenten` kanaal notification instead of a bespoke poller.

## Affected Projects
- [x] Project: `openconnector` — new `notificaties-api-connector` capability (abonnement lifecycle,
      callback endpoint, publish action), plus deltas to `events-cloudevents` (new subscription
      `action.kind`, inbound-notification-to-CloudEvent normalization) and `consumer-management`
      (formalizes that apiKey consumer authentication is a public contract usable outside the
      endpoint-runtime dispatch path).

## Scope

### In Scope
1. **Subscriber**: register/update/delete an `abonnement` (subscription) against a remote Notificaties
   API for one or more `kanalen` with `filters`, via `CallService` against a configured `Source`.
2. **Callback endpoint**: a dedicated controller route that authenticates inbound notification POSTs
   via the existing `consumer-management` apiKey path (a `consumer` record generated per abonnement,
   its `authorizationConfiguration.apiKey` sent to the remote API as `abonnement.auth`), and normalizes
   each verified notification into an internal CloudEvent via `EventService::emitCloudEvent()`.
3. **Routing reuse**: the normalized CloudEvent flows through the pre-existing `event_subscription` /
   `processEvent` fan-out and REQ-008 action-dispatch (webhook / synchronization / job) unchanged — no
   new trigger engine.
4. **Publisher**: a new `action.kind = 'notificaties'` on `event_subscription.action`, so a matching
   internal event is published to a configured Notificaties API `kanaal` (via `CallService` against a
   `Source`) in the ZGW notification body shape: `{kanaal, hoofdObject, resource, resourceUrl, actie,
   aanmaakdatum, kenmerken}`.
5. **Abonnement lifecycle**: create/update/delete on the remote API, OR-persisted lifecycle record
   (`notificaties_abonnement` schema) with status tracking.
6. **Config UI**: an Abonnementen section (typed manifest page) to manage abonnementen; NcSelect
   `inputLabel` per ADR-004.
7. **Tests**: unit coverage for the abonnement request body, the notification→CloudEvent mapping, the
   publish body shape, and the callback auth gate; integration coverage for "incoming notification
   triggers a synchronization" against a mocked Notificaties API.

### Out of Scope
- Acting as a Notificaties API **server** (hosting `/kanaal`, `/abonnement`, `/notificaties` endpoints
  for OTHER systems to call) — this change is client-only (subscriber + publisher).
- **Autorisaties API** integration (scope-based authorization of which kanalen/resources a client may
  access) — flagged as an explicit follow-up in Open Questions.
- Any change to the generic `endpoint-runtime` dispatch pipeline (`targetType` enum) — the callback is a
  dedicated controller route, not a generic configurable endpoint; see design.md Decision 1 for why this
  deviates from the initial brief's "reuse endpoint-runtime" framing.

## Approach
Reuse, don't fork, per the existing `events-cloudevents` extension pattern (webhook → +synchronization →
+job → **+notificaties**, same `action.kind` switch in `EventService::attemptDelivery`). Reuse
`AuthorizationService::authorizeApiKey()`/`resolveConsumerByApiKey()` (already public, already used by
`consumer-management` REQ-CON-001) for callback auth, binding one `consumer` record per abonnement. Reuse
`EventService::emitCloudEvent()` (already generalized for exactly this "domain-specific CloudEvent type"
case by `peppol-access-point-connector`) for the inbound normalization. Reuse `CallService::call()` against
a `Source` for both the abonnement CRUD calls and the outbound notification POST — no new HTTP client.
Full technical detail in design.md.

## New Dependencies
None. No new composer packages — the ZGW notification body is a plain JSON structure; no ZGW/Notificaties
API client library is introduced.

## Impact
- `lib/Service/EventService.php` — new `action.kind = 'notificaties'` dispatch branch.
- New `lib/Service/NotificatiesSubscriberService.php` — abonnement CRUD against the remote API,
  notification→CloudEvent normalization.
- New `lib/Controller/NotificatiesSubscriberController.php` — callback route + abonnement management API.
- `lib/Settings/openconnector_register.json` — new `notificaties_abonnement` schema; `event_subscription`
  gains the `notificaties` action.kind (additive, backward compatible).
- `src/` — new Abonnementen manifest page + modal.
- No change to `EndpointService`/`EndpointsController` (endpoint-runtime is not modified).

## Cross-Project Dependencies
None. This is entirely internal to `openconnector`; it does not require or block changes in any other
`apps-extra` project. Apps that already consume OpenConnector CloudEvents (e.g. via `event_subscription`)
gain the ability to subscribe to `nl.conduction.zgw.notificatie.*` events with zero changes on their side.

## Risks

### Risk 1: ZGW Notificaties API callback verification is a shared-secret convention, not a formal handshake
**Severity:** Medium — **Mitigation:** The standard (VNG-Realisatie `notificaties-api`) does not define a
WebSub-style challenge/response handshake; verification is achieved by the remote API echoing the
`abonnement.auth` value as the `Authorization` header on every delivered notification, which this design
validates via the existing constant-time apiKey consumer match. This is documented as an explicit
assumption in design.md and flagged for verification against the specific target implementation's OAS
(e.g. Open Notificaties) before General Availability, since some implementations are known to vary.

### Risk 2: Per-abonnement `consumer` records are an unbounded-growth pattern
**Severity:** Low — **Mitigation:** One `consumer` per abonnement is small in practice (gov integrations
are configured, not self-service at scale); deleting an abonnement MUST cascade-delete its companion
consumer (REQ-004) to avoid orphaned auth records accumulating.

## Rollback Strategy
Purely additive: the new `notificaties_abonnement` schema, the new `action.kind = 'notificaties'` value,
and the new controller/service are all opt-in — no existing `event_subscription` sets `action.kind =
'notificaties'` before this ships, and the default (`action` absent → `webhook`) is unchanged. Rollback is
a straight revert of the merged PR(s); no data migration is required to unwind (any abonnement records
created in the interim remain harmless orphaned OR objects, since `NotificatiesSubscriberService` is the
only reader/writer of that schema).

## Open Questions
- Autorisaties API integration (scope-gating which kanalen/resources this app may subscribe to /
  publish on) is deferred — should it land before or after this ships to a gov-facing pilot?
- Should the publish-side `Source` require mTLS (PKIoverheid) by default for Digikoppeling-adjacent
  deployments, or is plain HTTPS + apiKey acceptable for a first cut? Deferred to the target
  environment's `Source` configuration (existing `CallService`/`AuthenticationService` support both;
  this change does not mandate either).
