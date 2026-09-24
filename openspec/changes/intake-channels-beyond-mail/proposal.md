---
kind: code
depends_on: []
---

# Proposal: intake-channels-beyond-mail

## Summary

A resident writes on WhatsApp, reports a broken streetlight from a phone, or
submits a form that arrives as an object over a webhook. Each of those is a
channel, and today only mail has a way in. This change makes a channel a
declared adapter with a routing rule, so a new one is configuration rather
than a release.

## Motivation

Round 4 discovery, cluster 45, "Intake channels beyond mail"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Six candidates, seven passers,
four driven and three documented, proving system xxllnc-zaken. Owner
integriq, size M, wave 3, no decision.

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-intake-21 | must | partial | a submitted form arrives as an object over a notification webhook and becomes a case by a type mapping |
| C-intake-3 | should | no | a case is created from a message on a channel that is not e-mail |
| C-intake-35 | should | no | public space reports arrive through their own mobile channel, on the same case types |
| C-intake-4 | should | no | a case is created from inside another application's text box, without leaving it |
| C-intake-12 | should | no | a native mobile application creates cases, comments and books time away from a desk |
| C-tasks-and-phases-31 | should | no | tasks are handled and case information consulted from a mobile application |

The clauses, verbatim from `_round4/discovery/candidates.json`:

- C-intake-21, `intake.tsv:36`: "it is the Open Formulieren pattern and the
  mapping is the configuration that makes it work without code". Passer:
  "dimpact-zac: Inbox productaanvragen (productaanvraag-intake/spec.md)".
  dossiq: "partial, `lib/Controller/DSOIntakeController.php`".
- C-intake-3, `intake.tsv:12`: "WhatsApp is how a resident actually writes".
  Passer: "freescout: Manage, Modules, sms-tickets, whatsapp $4, telegram $4,
  facebook $4, twitter $14". The lane notes three passers.
- C-intake-35, `intake.tsv:10`: "meldingen openbare ruimte is the
  highest-volume case type a gemeente has". Passers: "xxllnc-zaken: MOR app
  (MOR.md)" driven, and Decos JOIN documented. The lane's note: "MOR under
  two names, one as a shipped product set, one as a mobile-first app".

## What this change does not build, and who does

Three of the six are not an integration change, and saying so is cheaper than
sizing them wrong.

- **C-intake-4**, creating a case from another application's text box, is the
  Nextcloud smart picker. The lane says it outright: "One of the ten
  Nextcloud platform integration points dossiq does not register". Its passer
  is Deck's `lib/Reference/CreateCardReferenceProvider.php`. Decision D9 asks
  whether those ten run as one programme, and this candidate belongs there.
- **C-intake-12 and C-tasks-and-phases-31**, a native mobile application, are
  a product, not a connector. Both have documented passers only, Easy
  Redmine, Jira Data Center and Decos JOIN, which under D21 makes them an
  upper bound and never a driven count. The lane is blunt about the state of
  the market: "a toezichthouder in the field is the whole VTH use case and
  none of the four driven systems ships an app". dossiq reads "partial,
  `register.d/40-mobiel-inspectie-offline.json` models offline inspection and
  there is no app".

C-intake-35 stays in scope, because a public space report arrives over a
channel whether or not we ship the app that sends it. The channel is
integriq's; an app on a phone is not.

## What integriq builds and what dossiq consumes

Integriq builds the way in. dossiq declares where it lands.

- A channel adapter receives, normalises and hands over. dossiq never writes
  channel-specific code, and its `case.intakeChannel` becomes the value the
  channel declared rather than a value typed afterwards.
- The routing rule maps a channel and a payload onto a case type. dossiq owns
  the case types and portaliq owns the form, and integriq owns neither.
- A reply goes back over the channel it arrived on, so a WhatsApp message is
  answered on WhatsApp and not by e-mail.

## The existing specs this extends

- `open-formulieren-intake`, REQ-001: the signed inbound submission webhook,
  a `#[PublicPage]` endpoint gated by a signature, mirroring
  `PeppolController::inbound()` and `NotifyNlController::inbound()`. The
  submission half of C-intake-21 is that endpoint generalised to a routing
  rule.
- `nextcloud-forms-connector` and `kcc-cti-adapter`: two channels that
  already exist and become adapters under the contract.
- `notifynl-sms-channel`: an outbound channel that gains an inbound half, so
  a reply can arrive where the message went.
- `mapping-and-search` and `rule-pipeline`: the mapping and the pre-write
  rules a routed submission passes through.
- `synchronization-engine`, REQ-008 per-item isolation: one unparseable
  message does not stop a channel.

## Size and dependencies

Size M. The build plan makes it depend on cluster 51, "The intake form as its
own object", which is portaliq's and sits in wave 2. The dependency is real
for the form half and not for the channel half: a channel can deliver a
payload before anyone has modelled the form that produced it. Where a routing
rule needs a form definition, it names one and fails if it is missing.

## Out of scope

- Mail intake. It exists, and its filters and sender authentication are
  dossiq's under D12 in `inbound-mail-filters`, cluster 25.
- A native mobile application, C-intake-12 and C-tasks-and-phases-31.
- The Nextcloud smart picker, C-intake-4, which belongs to the platform
  programme under D9.
- The intake form as an object, cluster 51, portaliq.
