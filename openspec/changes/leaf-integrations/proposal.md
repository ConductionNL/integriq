# Proposal: leaf-integrations

## Summary

Give OpenConnector its first OpenRegister integration leaves — deliberately few. Verified current state: `src/manifest.json` contains zero `{"type": "integration"}` widgets and no schema in `lib/Settings/openconnector_register.json` declares `linkedTypes`; OpenConnector consumes none of OpenRegister's ~17 app-agnostic leaves. Its domain objects are technical (source, endpoint, job, mapping, synchronization, dead-letter), so most consumer-style leaves (photos, polls, contacts, mail intake…) would be decoration. Four leaves genuinely serve an integrator's workflow and only those are adopted: **files** on `source` (supplier API documentation, OAS exports, onboarding documents attached to the connection they describe), **deck** on `source` (integration-incident follow-up cards), **talk** on `source` (an incident war-room conversation linked to the failing connection), and **calendar** on `synchronization` (planned maintenance windows and cutover dates linked to the sync they affect). Everything is declarative — `configuration.linkedTypes` in the register plus manifest widgets on the two manifest-driven detail pages — with one honest limitation recorded: `SynchronizationDetail` is a `type: "custom"` page (verified), so its calendar leaf surfaces through the shared object sidebar rather than a manifest widget until that page is manifest-driven.

## Motivation

- **Integration operations is coordination work with no anchor today.** When a supplier API breaks, the artefacts scatter: the incident card in a personal Deck board, the war-room in an unlinked Talk conversation, the supplier's PDF in someone's mail, the maintenance window in a private agenda. The `source`/`synchronization` object is the natural anchor and OpenRegister's leaf machinery already links all four entity types to objects — for free.
- **The infrastructure is proven next door.** Scholiq and OpenCatalogi render integration widgets from the same manifest mechanism; OpenConnector's SourceDetail and EndpointDetail are already manifest `detail` pages with widget arrays.
- **Restraint is the deliverable.** An earlier fleet lesson (opencatalogi/scholiq leaf adoptions) is that every widget costs page space and every leaf on a data-bearing schema is an exposure question. OpenConnector's schemas are the integration control plane; this change declares leaves on exactly two schemas and refuses the rest with reasons.

## Affected Projects

- [x] Project: `openconnector` — `lib/Settings/openconnector_register.json` (`configuration.linkedTypes` on `source` and `synchronization`), `src/manifest.json` (3 widgets on SourceDetail), one e2e spec-coverage file.

## Scope

### In Scope

- `source.configuration.linkedTypes = ["files", "deck", "talk"]` + three widgets on SourceDetail (`src-files` "Supplier documents", `src-deck` "Incident follow-ups", `src-talk` "Incident war-room")
- `synchronization.configuration.linkedTypes = ["calendar"]`, surfaced via the shared object sidebar (no manifest widget — SynchronizationDetail is `type: "custom"`)
- A Playwright spec-coverage test for the SourceDetail widgets
- Documentation of the OFF list (every other schema, every other leaf) with reasons

### Out of Scope

- Leaves on `endpoint`, `job`, `mapping`, `rule`, `consumer`, any log schema, or any dead-letter schema — see design; notably a files leaf on `sync_item_dead_letter` (exported payload samples) is **deferred, not refused**: the DeadLetters page is `type: "custom"` and bulk-oriented, so there is no per-object surface to render it on yet
- Converting `SynchronizationDetail` (or any custom page) to a manifest-driven detail page — its own change
- Mail intake (`mailObjectTemplate`) — no OpenConnector archetype maps to "an email becomes an object"
- Any automation attached to leaves (auto-creating a Deck card when a circuit breaker opens is an event/notification feature, not a leaf declaration — noted as a follow-up)
- Membership sync for the Talk leaf (war-room membership is manual)

## Approach

Add the two `configuration.linkedTypes` declarations (validated at import by OpenRegister's `Schema::validateLinkedTypesValue()` against the provider registry — an unknown id fails the import loudly); add the three SourceDetail widgets shaped like the fleet's existing integration widgets; add the e2e file. No PHP, no Vue, no migration.

## New Dependencies

None. Deck/Talk/Calendar are runtime-optional: OpenRegister providers self-disable (`isEnabled()`) when the NC app is absent and the widget simply does not render.

## Impact

- `lib/Settings/openconnector_register.json` — additive `configuration` keys on 2 of 39 schemas; no property, authorization, or lockdown overlay (`register.d/99-*`) change.
- `src/manifest.json` — 3 new widgets on SourceDetail; no existing page or widget changes.
- No REST surface, no ADR-023 action, no secret-handling change: leaves link entities held by other NC apps and never read `source`'s credential properties.

## Cross-Project Dependencies

None requiring changes elsewhere. Requires openregister ≥ the pluggable-integration-registry commit (present at `origin/development`).

## Risks

### Risk 1: A leaf on `source` sits next to plaintext credentials

**Severity:** Medium — `source` carries `password`, `apikey`, `secret`, `jwt` (plaintext per local ADR-007), and SourceDetail renders for admins. The leaves add link surfaces to that page but no new read path: leaf widgets render only after the object read that page already performs, and no leaf reads object properties at all (files/deck/talk link external entities by object id). **Mitigation:** REQ-OCL-002 makes "leaves never read or write source properties" normative; the e2e test asserts no credential value appears inside any leaf widget's DOM.

### Risk 2: War-room conversations outlive incidents and accumulate

**Severity:** Low — linked Talk conversations/cards go stale after the incident. **Mitigation:** accepted; unlink is one click, and the leaf shows its linked entities' own state. Auto-archival is the follow-up automation explicitly out of scope.

### Risk 3: The sidebar-only calendar leaf on synchronizations goes unnoticed

**Severity:** Low — without a manifest widget, discoverability depends on the shared sidebar. **Mitigation:** documented in `docs/`; the honest alternative (claiming a widget on a custom page the manifest cannot reach) would be a phantom spec. Revisit when SynchronizationDetail becomes manifest-driven.

## Rollback Strategy

Revert the commit; re-import drops the `linkedTypes` keys and the widgets disappear with the manifest. Linked cards/conversations/events/files live in their owning NC apps and lose only their OpenConnector-side rendering.

## Open Questions

- Should a circuit-breaker-open event (`circuitBreakerState`, verified property) auto-create the incident card/war-room, making the leaf the landing spot? (Follow-up automation; needs the event-hub work, not this change.)
- When `SynchronizationDetail` is manifest-driven, should `synchronization` also gain `deck` for sync-specific follow-ups, or does the source-level board suffice?
