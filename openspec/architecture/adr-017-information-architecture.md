# ADR-017: Information Architecture — five top-level menus organised around connections, messages, and an adapter catalogue

## Status
Accepted

## Date
2026-05-23

## Context

OpenConnector is the integration plane underneath every Conduction app: it
owns sources, sinks, transports, schedules, mappings, message logs,
authentication brokers, and a catalogue of pre-built adapters for the Dutch
and international ecosystems (StUF, ZGW, Digikoppeling, HaalCentraal,
Berichtenbox, DigiD/eHerkenning, Peppol, Mollie/Stripe, MS Graph, Google
Workspace, SaaS productivity, document/CMS, infra & data, endpoint/workspace,
plus an iPaaS-reliability layer for retries, DLQ and circuit-breaker).

It is explicitly a **developer/integrator tool** — the operator who wires up
a connection, troubleshoots a failing endpoint at 23:00, and tunes a mapping.
It is *not* the end-user surface of any downstream Conduction app.

The risk that drives this ADR: the catalogue of adapter specs is unbounded.
We have ~15 adapter specs today (`stuf-bg-zkn-bg-koppelvlak`,
`digikoppeling-adapter`, `haalcentraal-personen-bag-hra-adapter`,
`berichtenbox-mijnoverheid-adapter`, `digid-eherkenning-auth-adapter`,
`peppol-e-invoicing-adapter`, `mollie-stripe-payment-adapter`,
`microsoft-graph-workspace-adapter`, `saas-productivity-connectors`,
`document-cms-connectors`, `endpoint-workspace-connectors`,
`data-infra-connectors`, plus cross-cutting `auth-protocol-suite`,
`ipaas-reliability` and `prometheus-metrics`) and the roadmap easily doubles
that. If each adapter family earned its own menu the IA would collapse before
phase 4 lands. The IA must therefore *not* grow with the catalogue.

A parallel risk is duplication. Operators routinely confuse "what is wired"
with "what happened" — auth providers get re-defined per connection, retry
policy drifts per integration, mappings hide inside logs. Without a clear
home for each of those concerns the operator's mental model fragments and
the troubleshooting flow (the 23:00 use case) breaks.

This ADR is the cross-cutting IA contract that every spec, page, modal and
left-nav addition is measured against. Per-resource conventions (index +
modals + sidebar triad, manifest-driven UI) are owned by ADR-010 and the
hydra Tier-0..Tier-4 ADRs; this ADR governs only the top-level shape.

## Decision

OpenConnector ships **five top-level menu items**, in this order:

1. **Verbindingen** — configured connections (each connection = adapter +
   endpoint + auth + mapping + schedule).
2. **Bronnen** — sources & sinks (raw endpoints/systems before they are
   wired into a flow).
3. **Berichten** — messages, runs, DLQ, retries, errors (the operational
   view).
4. **Adapters** — adapter catalogue (installed + available; StUF, ZGW,
   HaalCentraal, DigiD, Peppol, MS Graph, Mollie, Berichtenbox,
   Digikoppeling, …).
5. **Beheer** — authentication, reliability tuning, prometheus, admin
   settings.

The IA is governed by the following numbered rules. They are normative for
every new spec, page, menu or feature shipped under openconnector.

### Rule 1 — Adapters are catalogue entries, not menu items

**Rule.** A new adapter family ships as a card in *Adapters* and as new
options in the *Verbindingen* new-connection wizard and the *Bronnen* type
filter. It MUST NOT become a new top-level nav item, a new settings page,
or a sibling of *Adapters*.

**Rationale.** The adapter catalogue is open-ended. Treating it as data
(catalogue entries + wizard options) instead of structure (menus + pages)
is the only mechanism that keeps the top-level count stable as the
catalogue grows from 15 to 50+ specs.

**How to apply.**
- New adapter spec → register it in the catalogue index used by *Adapters*
  with a domain tag (`Overheid-NL`, `E-facturatie`, `Productiviteit`,
  `Document/CMS`, `Infra/Data`, `Endpoint/Workspace`).
- Add its capabilities and configuration schema to *Adapter detail* (tabs:
  Beschrijving, Capaciteiten, Configuratie-schema, Voorbeelden, Versies,
  Verbindingen die deze adapter gebruiken).
- Expose it as a step-1 picker in the *Nieuwe verbinding* wizard.
- Do **not** add a per-adapter settings page, per-adapter top-level menu,
  or per-adapter route under `/beheer`.
- The only allowed exception is when the adapter requires tenant-wide
  broker config that is reused across connections — see Rule 3.

### Rule 2 — Configuration lives on the connection; operations live on the message

**Rule.** Anything about *what is wired* (adapter choice, source, target,
mapping, schema, auth selection, schedule, reliability overrides) belongs
in *Verbindingen* on the *Verbinding detail* tabs. Anything about *what
happened* (runs, payloads in/out, mapping trace, auth trace, timings,
errors, retry history, DLQ) belongs in *Berichten* on *Bericht detail*.

**Rationale.** Operators learn the split once and apply it forever. It
collapses an open-ended troubleshooting workflow into a binary choice
("is this a wiring problem or a runtime problem?") and prevents the
common anti-pattern where logs sprout configuration UI and configuration
panels sprout per-run telemetry.

**How to apply.**
- New configuration field → *Verbinding detail* tab (Overzicht, Bron,
  Doel, Mapping, Schema, Auth, Schedule, Reliability, Test, Audit).
- New runtime/observability field → *Bericht detail* tab (Payload-in,
  Payload-out, Mapping-trace, Auth-trace, Timing, Foutdetails,
  Retry-historie).
- Bulk operations on history (retry-all, mark-read, export) → *Berichten*
  list bulk-actions, never *Verbindingen*.
- The *Test* tab on a connection is the only allowed place where a
  configuration surface may trigger a single run inline; the result of
  that run is still recorded in *Berichten*.

### Rule 3 — Auth has two homes by design: providers in Beheer, selection on the connection

**Rule.** Tenant-wide authentication infrastructure (OAuth/OIDC providers,
SAML IdPs, mTLS certificates, JWT/JWE keys, DigiD/eHerkenning brokers,
key rotation policy) lives in *Beheer > Authenticatie*. Per-connection
auth selection (which of those providers this connection uses) lives on
the *Verbinding detail > Auth* tab. Provider config MUST NOT be duplicated
inside a connection.

**Rationale.** Auth providers are shared infrastructure; key rotation
and credential hygiene are tenant concerns. Letting each connection
re-define a provider produces drift, hides key reuse, and makes rotation
a per-connection migration instead of a one-time tenant op. The split
also gives `auth-protocol-suite` (cross-cutting) and adapter-specific
broker specs (e.g. `digid-eherkenning-auth-adapter`) one canonical home
without forcing the adapter out of the catalogue.

**How to apply.**
- New auth protocol → add to *Beheer > Authenticatie* (OAuth/OIDC, SAML,
  mTLS, JWT/JWE, sleutels & rotatie sections).
- New auth-broker adapter (e.g. DigiD) → catalogue entry in *Adapters*
  (so it is discoverable when wiring a new connection) **and** broker
  config in *Beheer > Authenticatie* (tenant-wide).
- *Verbinding detail > Auth tab* → picker referencing existing providers;
  never an inline provider-create form.

### Rule 4 — Reliability is global policy with per-connection override

**Rule.** Default retry, backoff, and circuit-breaker thresholds live in
*Beheer > Reliability* (`ipaas-reliability` spec). Each connection may
deviate from the defaults via a *Reliability* tab on *Verbinding detail*.
Per-connection settings MUST be explicit overrides of named global
policies, not standalone re-implementations.

**Rationale.** Identical principle to Rule 3 applied to the iPaaS layer:
shared policy, scoped override. Avoids the failure mode where every
connection silently runs with hand-tuned retry maths that nobody
understands six months later.

**How to apply.**
- New reliability concern (e.g. rate-limiting, backpressure, jitter
  strategy) → add to *Beheer > Reliability* as a global default first.
- Per-connection deviation → expose the same field on the *Reliability*
  tab with the global value as placeholder and an explicit
  "override default" affordance.
- DLQ and retry UX live in *Berichten* (Rule 2): policy is global,
  operations are per-message.

### Rule 5 — Beheer is operator-only and absorbs all settings, dashboards, and ops endpoints

**Rule.** Anything that is tenant configuration, cross-cutting policy,
admin dashboard, AI-tool provider config, or an ops endpoint card
(Prometheus, alerting) belongs under *Beheer*. The user-flow pages
(*Verbindingen*, *Bronnen*, *Berichten*, *Adapters*) MUST NOT leak admin
controls or tenant-wide settings.

**Rationale.** The operator and the integrator are the same persona for
openconnector, but the *task* still differs ("I'm wiring a connection"
vs. "I'm tuning the tenant"). One door for tenant work keeps the wiring
flow uncluttered and lets us add new admin surfaces (Prometheus, alerting,
future AI-tool providers) without touching the user-flow IA.

**How to apply.**
- New tenant-config surface → *Beheer* page or section, never a tab on
  a user-flow page.
- New ops endpoint card (Prometheus, future alerting/SLO) → *Beheer*
  dashboard or its own *Beheer* page.
- Global dashboards (operational KPIs across all connections) → *Beheer >
  Dashboard*; per-connection health/spark widgets stay on
  *Verbinding detail > Overzicht*.

### Rule 6 — What is deliberately NOT a top-level menu

The following are explicitly forbidden as top-level items, regardless of
spec growth:

- **Mappings** — a mapping is meaningless without its source/target;
  it lives on a connection (*Verbinding detail > Mapping* tab).
- **Schedules** — same reason; lives on *Verbinding detail > Schedule*.
- **Transformations** — part of mapping; not a separate concept in the IA.
- **Per-adapter menus** (e.g. "StUF", "DigiD", "Peppol") — forbidden by
  Rule 1.
- **Per-protocol menus** (e.g. "OAuth", "SAML") — forbidden by Rule 3;
  these live inside *Beheer > Authenticatie*.

If a future spec argues for promoting any of these, it MUST first
amend this ADR.

### Rule 7 — Deliberate cross-menu splits are allowed; duplication is not

**Rule.** Where a feature genuinely answers two different operator
questions, it MAY surface in two menus — but the underlying data, schema
and store remain single-sourced. Three current splits are sanctioned:

- `digid-eherkenning-auth-adapter` — *Adapters* (discovery) +
  *Beheer > Authenticatie* (broker config).
- `data-infra-connectors` — *Adapters* (catalogue entry) +
  *Bronnen* (type filter for DB/S3/MQ).
- `ipaas-reliability` — *Beheer > Reliability* (global policy) +
  *Verbinding detail > Reliability* (per-connection override).

New splits MUST be justified in the spec's mapping table with the same
"two questions, one source" rationale. Splits that introduce a second
write path or a second store are not splits — they are duplication and
MUST be rejected at spec review.

## Consequences

- The top-level count stays at five regardless of how large the adapter
  catalogue grows. Every new adapter spec ships as a catalogue entry
  plus wizard step; the left nav does not move.
- Operators always know where to look: wiring questions resolve in
  *Verbindingen*, runtime questions in *Berichten*, tenant policy in
  *Beheer*. The 23:00 troubleshooting flow stops at *Berichten >
  Bericht detail* without a hunt through settings pages.
- The five-menu shape constrains spec authors: a new feature must fit
  one of the five sections (or, for sanctioned splits, two of them with
  shared storage). Proposals that don't fit must change the IA via this
  ADR, not by adding a sixth menu silently.
- The forbidden top-level list (Rule 6) will resurface in spec reviews;
  authors who propose a "Mappings" or "Schedules" menu must be redirected
  to *Verbinding detail* tabs.
- Adapter-family proliferation continues to be safe: today's 15 specs
  and tomorrow's 50 share the same five-menu shell.
- Cross-references:
  - ADR-010 (per-resource UI triad) — the within-page convention that
    every resource page in this IA follows.
  - ADR-005 (source/synchronization/contract triad) — the data triad
    that anchors *Verbindingen* and *Bronnen*.
  - ADR-003 (CallLog as primary observability surface) — the data
    backing *Berichten*.
  - ADR-007 / ADR-016 (credentials & encryption) — the data backing
    *Beheer > Authenticatie*.
  - ADR-014 (cron job execution) — the data backing *Verbinding
    detail > Schedule*.
  - Hydra ADR-022 (Tier-4 manifest-driven UI) and the openconnector
    frontend rewrite chain — the rendering layer that implements this
    IA; this ADR governs structure, those govern rendering.

## Evidence

- IA source: `/tmp/ia-doc-dec-cat-conn.md` section 4 (openconnector),
  including the four design rules at section 4.F and the splits note
  at section 4.G that this ADR consolidates and elevates to a numbered
  rule set.
- Spec inventory (15 adapter specs + auth-protocol-suite +
  ipaas-reliability + prometheus-metrics) confirmed against
  `openspec/specs/` and the mapping table in the IA source.
- `src/views/` currently mirrors a per-resource layout (Source, Endpoint,
  Job, Synchronization, Mapping, Rule, Consumer, Contract, Log) — the
  IA in this ADR groups those resources under the five top-level
  surfaces without renaming the underlying stores or routes.
