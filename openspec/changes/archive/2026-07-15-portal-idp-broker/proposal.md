---
kind: config
depends_on: []
---

# Proposal: portal-idp-broker

<!--
Change classification note: this is a SPEC-FIRST change — it ships only
OpenSpec artifacts (proposal, design, delta spec, tasks) and NO PHP/Vue/config.
ADR-032 (see hydra opsx-ff rules) demands `kind:` ∈ {config, code}, so `config`
is used, following the fleet precedent for non-code / investigation / spec-only
changes (`decidesk-ris-import-go-investigation`, hydra's
`declarative-widget-vocabulary`). This change is the HEAD of an implementation
chain — the chain is narrated under "Chaining narrative" below, per the
opsx-ff head-of-chain rule.
-->

**Tracking issue:** ConductionNL/openconnector#99

## Summary

Specify the **government IdP broker** inside openconnector: the component that
owns the SAML/OIDC conversation with the DigiD / eHerkenning / eIDAS chain
(typically via a commercial broker — Signicat, OneWelcome, or direct Logius
routes) and hands consuming apps nothing but a **verified, one-time, signed
subject envelope** `{subjectRef source, audience, organisation, trust}`. This
is the capability ADR-017 already reserves as the `digid-eherkenning-auth-adapter`
catalogue entry, and it is the hard prerequisite for portaliq's **Wave 2 —
government/citizen** audiences (zaakafhandelapp Mijn Zaken, docudesk consent +
signing, decidesk citizen participation, softwarecatalog vendor orgs; see
`apps-extra/PORTALIQ-FLEET-REVIEW-2026-07-06.md` §Wave 2). This change is
**spec-only**: no PHP, no Vue, no register JSON is touched. Implementation
arrives in follow-up changes chained on this one.

## Motivation

- **portaliq is blocked.** Portaliq's auth edge (`lib/Service/PortalSessionService.php`,
  `lib/Service/PortalJwtService.php`) is production-shaped — fail-closed bearer
  resolution, eIDAS-aligned trust vocabulary `low|substantial|high`, A6
  assertion pattern — but its only login path today is the debug-gated
  `session#devLogin`. Every Wave-2 audience (citizen via DigiD, organisation
  via eHerkenning, EU subjects via eIDAS) needs a real broker before it can
  ship. The fleet review lists "real eHerkenning/DigiD broker (openconnector
  adapters are planned in its ADR-017, not implemented)" as open item 7.
- **procest already assumed openconnector would do this.** Procest's dormant
  supplier-portal seam (`lib/Service/SupplierAuthService.php`,
  `lib/Service/Auth/EHerkenningSamlAdapterInterface.php` at
  `origin/development`) explicitly documents "Production wires this to
  OpenConnector" and ships a Log-adapter that throws until the openconnector
  broker config + certificates exist. This change is the openconnector side of
  that seam — one broker serving both portaliq and procest's leverancier
  portal instead of per-app SAML stacks (long-term unification rule).
- **BSN must never spread.** DigiD authentication yields a BSN. Under the Wet
  BSN / AVG, only parties with a legal basis may process it — a generic portal
  layer and generic app stores (OpenRegister objects) do not qualify. The
  broker boundary is the single place where BSN → pseudonym conversion can be
  enforced, audited, and kept out of every downstream store.
- **ADR-017 demands one canonical home.** Rule 3 places tenant-wide auth
  broker config in *Beheer > Authenticatie*; Rule 7 sanctions exactly this
  split (`digid-eherkenning-auth-adapter` — *Adapters* for discovery +
  *Beheer > Authenticatie* for broker config). Writing the spec now pins the
  implementation to the IA contract before code exists.

## Affected Projects

- [x] Project: `openconnector` — this change: spec artifacts for the
  `digid-eherkenning-auth-adapter` capability (broker boundary, trust mapping,
  envelope handoff, dormant seam). Future chained changes: broker config
  surface + SP/RP runtime.
- [ ] Project: `portaliq` — **referenced, not modified.** Consumer contract
  read from `lib/Service/PortalSessionService.php` + `PortalJwtService.php` +
  `lib/Settings/portaliq_register.json` (portalAccount/portalSession). A
  future portaliq-repo change adds `/portal/auth/{provider}` + envelope
  redemption and flips the dev-login edge.
- [ ] Project: `procest` — **referenced, not modified.** Its dormant
  `EHerkenningSamlAdapterInterface` becomes a consumer of the same broker in
  a later chained change.

## Scope

### In Scope

- The **broker boundary decision**: openconnector owns the IdP conversation;
  consumers receive only the verified subject envelope; raw BSN never crosses
  the boundary (polymorphic pseudonymisation, with a salted-HMAC fallback).
- The **trust mapping table**: DigiD Basis/Midden/Substantieel/Hoog and
  eHerkenning EH2+/EH3/EH4 (and inbound eIDAS LoA) → portaliq's
  `low|substantial|high`.
- The **end-to-end flow**: portal SPA → portaliq `/portal/auth/{provider}` →
  openconnector broker endpoints (SAML SP / OIDC RP) → callback → verify +
  map → one-time signed subject envelope → portaliq mints its session JWT and
  upserts `portalAccount`. Session lifetime + logout (SLO) considerations.
- The **dormant-seam pattern** (procest precedent): interfaces +
  config-flag-gated adapters that throw until certificates and a broker
  contract exist.
- Delta spec `specs/digid-eherkenning-auth-adapter/spec.md` with requirements +
  scenarios (all `@e2e exclude` — nothing is implementable yet).
- The explicit **Open Decisions** list for the human (below).

### Out of Scope

- **Any implementation** — no PHP, no Vue, no routes, no register JSON, no
  certificates, no broker contract. Deliberately deferred to the chained
  changes below.
- **Berichtenbox/MijnOverheid identity linkage** — `lib/Adapters/Berichtenbox`
  exists as a transport seam, but wiring it to the DigiD identity chain
  (`berichtenbox-mijnoverheid-adapter` in ADR-017) is explicitly deferred
  (Open Decision D5).
- A formal `contract.md` — the concrete endpoint paths/params depend on the
  broker vendor selection (Open Decision D1), so the cross-project contract
  document lands with the first implementation change instead of guessing
  vendor-specific shapes now. The envelope claim shape is already pinned in
  `design.md` §D4.
- IdP-initiated login support (explicitly **rejected** — see design threat
  notes), attribute-rich claims (machtigingen/ketenmachtiging beyond the
  intermediary basics), and Wave-3+ audiences.

## Approach

Spec-first. Capture four decisions as a delta spec + design doc:

1. **Broker boundary** — openconnector hosts the SAML SP / OIDC RP and talks
   to the government IdP chain via a commercial broker; portaliq (and later
   procest) receive only a verified subject envelope
   `{sub (KvK/RSIN for eHerkenning, BSN-pseudonym for DigiD, eIDAS UID),
   audience, organisation, trust}`. BSN never reaches portaliq or any app
   store: polymorphic pseudonymisation (eToegang polymorphe pseudoniemen) when
   contracted, salted-HMAC pseudonym computed at the broker edge as fallback.
2. **Dormant seam** — mirror procest's `LogEHerkenningSamlAdapter` pattern:
   provider interfaces + default Log-adapters that throw
   "broker not configured", activation via app-config feature flag +
   certificates + DI swap. Portaliq keeps its dev-login edge until the flip.
3. **Trust mapping** — normative table to portaliq's eIDAS-aligned
   `low|substantial|high`; unknown levels are rejected fail-closed at the
   broker (portaliq additionally normalises unknowns to `low` on its side).
4. **Envelope handoff** — one-time opaque code via browser redirect, redeemed
   server-to-server for a short-TTL signed envelope JWT that mirrors
   portaliq's A6 assertion shape (including the `use`-claim token-confusion
   guard).

Details, alternatives, flow diagram, and threat notes live in `design.md`.

## Capabilities

### New Capabilities

- `digid-eherkenning-auth-adapter`: the government IdP broker — SAML SP / OIDC
  RP conversation ownership, BSN pseudonymisation at the broker edge, trust
  mapping to the eIDAS-aligned vocabulary, one-time signed subject-envelope
  handoff to consuming apps, dormant-seam activation, and ADR-017-compliant
  configuration placement. (Name matches the catalogue entry ADR-017 already
  reserves — one canonical home, no second name.)

## Chaining narrative (implementation follow-ups)

This spec change is the head of a chain. Planned follow-up changes, each with
`depends_on` on its predecessor(s), each filed as its own OpenSpec change +
GitHub issue at plan time:

1. `portal-idp-broker-config` (openconnector, kind: config) — *Beheer >
   Authenticatie* broker-provider config schema + *Adapters* catalogue entry
   (ADR-017 Rules 1/3/7), feature flags, certificate/secret storage seams
   (ADR-007/ADR-016). `depends_on: [portal-idp-broker]`.
2. `portal-idp-broker-runtime` (openconnector, kind: code) — SP/RP endpoints
   (authorize/callback/exchange/metadata), assertion verification, trust
   mapping, pseudonymisation, envelope minting, Log-adapters (dormant
   default). `depends_on: [portal-idp-broker-config]`.
3. `portal-idp-consumption` (portaliq repo, kind: code) —
   `/portal/auth/{provider}` initiation + envelope redemption + portalAccount
   upsert + dev-login flag-off path. `depends_on: [portal-idp-broker-runtime]`.
4. `procest-eherkenning-activation` (procest repo, kind: code) — swap
   `LogEHerkenningSamlAdapter` for the openconnector-backed adapter.
   `depends_on: [portal-idp-broker-runtime]`.
5. `berichtenbox-identity-linkage` (openconnector) — deferred until D5 is
   decided.

## New Dependencies

None in this change (spec-only). The runtime change will introduce: a
commercial broker contract (D1), PKIoverheid certificates (D2), and a SAML/OIDC
library decision (recorded as an open question in design.md).

## Impact

- New files only: `openspec/changes/portal-idp-broker/**` (this change) and,
  at sync time, `openspec/specs/digid-eherkenning-auth-adapter/spec.md`
  (Status: planned).
- No runtime, API, or schema impact. ADR-017 is **not** amended — this spec
  materialises an entry the ADR already names.

## Cross-Project Dependencies

- **portaliq** consumes the subject envelope; its session edge defines the
  target vocabulary (`subjectRef`/`audience`/`organisation`/`trust` with
  trust ∈ `low|substantial|high`) and the A6 assertion shape the envelope
  mirrors. Contract source of truth read at HEAD of
  `portaliq` (`lib/Service/PortalSessionService.php`,
  `lib/Service/PortalJwtService.php`, `lib/Settings/portaliq_register.json`).
- **procest**'s dormant eHerkenning seam is the prior art and second consumer.
- No app may depend on this change until the runtime chain link ships.

## Risks

### Risk 1: BSN leakage through logs, envelopes, or stores

**Severity:** High — **Mitigation:** the delta spec makes "raw BSN never
crosses the broker boundary" a MUST with scenarios; pseudonymisation (or the
salted-HMAC fallback) is a hard requirement, not an option; implementation
changes inherit these as gate-checked requirements.

### Risk 2: Spec drifts from the eventual broker contract

**Severity:** Medium — **Mitigation:** everything vendor-specific is kept out
of the spec (only the envelope, trust table, and boundary are normative);
vendor selection is Open Decision D1 and the runtime change must revalidate
against the signed contract.

### Risk 3: Two consumers (portaliq, procest) diverge on the envelope shape

**Severity:** Medium — **Mitigation:** the envelope mirrors portaliq's A6
assertion claims exactly; procest's activation change must adopt the same
envelope rather than its bespoke `BrokerAssertionResult` shape (unification
rule; noted in design.md §D4).

### Risk 4: Multi-tenant SP metadata turns out per-organisation

**Severity:** Low (for this change) — **Mitigation:** explicitly parked as
Open Decision D3; the spec's requirements are written tenant-parametric so
either outcome fits without a spec rewrite.

## Rollback Strategy

Spec-only: revert the change directory (and, if already synced, the
`openspec/specs/digid-eherkenning-auth-adapter/` folder). No runtime rollback
exists because nothing runs.

## Open Questions — decisions for the human

These are **explicitly parked for Ruben** and MUST be answered before the
`portal-idp-broker-config` chain link starts:

- **D1 — Broker vendor/contract.** Signicat vs OneWelcome vs direct Logius
  routes (DigiD aansluiting + eHerkenning via an erkende makelaar). Includes:
  is **polymorphic pseudonymisation** contracted (eToegang polymorphe
  pseudoniemen), or do we start on the salted-HMAC fallback? Budget, DPA, and
  eIDAS-inbound support are part of this decision.
- **D2 — Certificate custody.** Where do the PKIoverheid private keys and SP
  signing certs live — doriath? openconnector *Beheer > Authenticatie* vault
  (ADR-016 encryption service)? An HSM at the broker? Who rotates?
- **D3 — Per-organisation vs shared SP metadata.** White-label multi-tenant
  portals: one shared SP EntityID (one dienstenaanbieder, organisation carried
  in the envelope) vs per-gemeente SP metadata/EntityID (per-tenant
  dienstverlening registration, cleaner legal separation, N× certificates).
- **D4 — DigiD Midden acceptance.** The trust table maps Basis and Midden to
  `low` (neither is eIDAS-notified). Do Wave-2 apps accept `low` DigiD logins
  at all, or is Substantieel the floor (zaakafhandelapp's Mijn Zaken likely
  demands ≥ substantial)?
- **D5 — Berichtenbox adapter deferral.** Confirm that
  `berichtenbox-mijnoverheid-adapter` identity linkage stays deferred until
  the broker runtime is live (it needs an OIN/aansluiting decision of its
  own), or pull it into the chain now.
