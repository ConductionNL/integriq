---
kind: code
depends_on: []
---

# Proposal: statutory-gateways-and-frameworks

## Summary

A statutory route is a gateway with a name, a standard and a conformance
claim anyone can read. Integriq already holds Digikoppeling, StUF, DSO,
Notificaties, DigiD and eHerkenning. This change turns the scattered set
into one answer to the question a gemeente asks in a tender: which statutory
routes does the product reach, under which law, and where does the data go.

## Motivation

Round 4 discovery, cluster 56, "The statutory gateways and the frameworks we
claim" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Twelve candidates, six
passers, one driven and five documented, proving system xxllnc-zaken. Owner
integriq, size L, wave 3. The rows the candidate notes name are 12.9 and
12.17.

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-integrations-27 | must | no | formal electronic messages received and sent under the Wmebv obligations |
| C-integrations-28 | must | partial | named third-party and national systems connected out of the box, not built per customer |
| C-integrations-29 | must | no | publication under the Wet elektronisch publiceren from the case, without copying the document |
| C-integrations-37 | must | yes | the buyer chooses the region the data is stored in, and can move it |
| C-integrations-47 | must | partial | the product runs against its own case registry or another vendor's, as a deployment choice |
| C-integrations-3 | must | partial | a case type flagged as carrying a statutory consequence when its term is missed |
| C-integrations-4 | should | no | a case type flagged as recording a public-law restriction on a property |
| C-integrations-30 | should | yes for the framework, no for the bridge | reach a system behind the customer's firewall through an outbound bridge |
| C-integrations-36 | should | partial | the Archiefwet, the Archiefregeling and the AVG named separately as frameworks the product meets |
| C-integrations-39 | should | no | the buyer chooses which Digikoppeling broker the connection runs over |
| C-integrations-46 | should | no | the CORV justice route and the GGK social domain gateway |
| C-integrations-23 | should | yes | case storage, the type catalogue and the service bus as one shared platform layer |

Five of the six passers are documented, so the lane quotes rather than
measures. Verbatim, from `_round4/discovery/candidates.json`:

- C-integrations-27, `integrations.tsv:50`, clause: "the Wmebv took effect on
  1 January 2026, it names twelve obligations, and it returns nothing in our
  tree". Passer: "atabix: documented, /expertises/wmebv".
- C-integrations-46, `integrations.tsv:47`, clause: "jeugdbescherming en Wmo
  berichtenverkeer are statutory routes we do not touch (grep -ri CORV
  returns nothing in dossiq)". Passer: "mozard: documented, /integraties".
- C-integrations-39, `integrations.tsv:35`: "the broker is a procurement
  decision the case system usually forces, and offering two is a deployment
  capability". dossiq: "no, zero hits for Digikoppeling or OpenTunnel".
- C-integrations-28, `integrations.tsv:27`: "'no double entry' is the claim
  that decides whether central case registration works at all, and our 12.x
  rows count protocols rather than asking it". dossiq reads partial three
  times, the sharpest being "partial, dossiq reaches BRP, KvK, BAG, WOZ, BRK,
  DSO, Berichtenbox".
- C-integrations-47, `integrations.tsv:54`: "'our app works on your existing
  registers' is the Common Ground promise made testable, and no row asks
  whether an app can run on a foreign store".

## The decisions this rests on

The build plan records no decision on cluster 56. Two answered decisions
govern how it is read, and D21 names this cluster by name.

**D21, the 143 candidates with no driven passer.** Ruben took option 2:
"Admit them as documented, labelled, and never blended into a driven count",
with one hard rule: "A documented candidate may become a row and may never be
counted in a driven tally." Its "waits on it" line opens with "The statutory
gateways (five of six passers documented)". Every documented candidate in the
table above is labelled as such and none is counted as driven anywhere in
this change.

**D6, the promotion bar.** Ruben took "two driven passers, and admit a
candidate on one driven passer when it is statutory. Awb 4:3a, Wmebv, Wet
elektronisch publiceren and the archiving duties are obligations, not
comparisons, and a row that says 'nobody does this and we must' is worth more
than a row that says 'six systems do this'." Three of the five `must`
candidates here are exactly those named laws.

## Scope

Nine requirements, and the boundary each one keeps.

1. A gateway is a catalogue entry that declares its standard and its
   conformance claim, so the catalogue answers "which routes, under which
   law" without a slide deck.
2. The Digikoppeling broker is an instance configuration, not a build-time
   constant.
3. CORV and GGK ship as sector gateway adapters, beside the iWmo and iJw
   adapter that already exists.
4. An inbound electronic route declares which Wmebv obligations it meets and
   which it hands to its consumer, recorded per message.
5. Official publication under the Wet elektronisch publiceren is a gateway,
   and the document is published by reference rather than copied.
6. The ZGW registry the product writes to is a deployment binding, so an
   instance can run on OpenRegister or on another vendor's registry.
7. An on-premise bridge reaches a system behind the customer's firewall over
   a connection the customer's network opens.
8. A gateway declares the jurisdiction its endpoint sits in, so an instance
   can report where data leaves to.
9. A WKPB restriction is registered through a gateway like any other
   statutory route.

## What integriq builds and what dossiq consumes

Integriq builds the routes and the claims. dossiq consumes them the way it
consumes every other adapter: it declares, it never dials.

- dossiq declares a case type as carrying a WKPB registration, and the
  gateway does the registering. The flag is dossiq's, on the case type.
- dossiq declares which registry binding an instance runs against, and its
  ZGW controllers keep the shape they have.
- dossiq's Wmebv duties that are case duties stay dossiq's. The
  ontvangstbevestiging under Awb 4:3a is named in the discovery summary as
  the first thing to fix and it is dossiq's work, not a gateway's.

## What this change does not build, and who does

- **C-integrations-3**, the statutory consequence of a missed term, is a
  deadline outcome, not a route. dossiq already holds
  `lib/Controller/NoticeOfDefaultController.php` and
  `lib/Controller/DwangsomPaymentCallbackController.php`. The candidate is
  named here because the cluster carries it, and its work belongs to dossiq's
  term model, cluster 18.
- **C-integrations-23** reads `yes` for dossiq: "OpenRegister plus the ZGW
  controllers". Nothing is built for it. It is recorded so nobody rediscovers
  it.
- **Publication itself.** Cluster 50, "Publication, inspection and the
  national indexes", is opencatalogi's. This change holds the gateway to the
  official publication platform and nothing about what is published or
  indexed.

## The existing specs this extends

- `connector-catalog`, REQ-001 and REQ-003: the catalogue and its single
  PHP-side metadata registry. A gateway entry gains the standard and the
  conformance claim.
- `digikoppeling-adapter`, REQ-DK-001 through REQ-DK-005: the adapter is a
  catalogue entry, WUS, ebMS2, Grote Berichten and PKIoverheid through the
  broker. The broker becomes a choice.
- `digid-eherkenning-auth-adapter`: integriq hosts the government IdP
  conversation and is the only component in the fleet that does. The
  boundary stands; this change adds no second IdP path.
- `objecten-api-facade`, the open change from `competitor-parity-2026-09`:
  the Objecten API served over openregister's dispatch. The registry binding
  in requirement 6 is the same shape pointed outward.
- `stuf-adapter`, `dso-omgevingsloket`, `iwmo-ijw-adapter`,
  `notificaties-api-connector`, `berichtenbox-digital-post-adapter`: the
  routes that already exist and become catalogue entries with claims.

## Size and dependencies

Size L, as the build plan rates it. It waits on nothing. The build plan puts
it in wave 3 and says why: "Every one of them is L, and none of them blocks
a tender answer."

## Out of scope

- The mail account and the mail transport. D12 gives the account to
  Nextcloud Mail, and integriq opens no mail-account change.
- Publication, inspection and the national indexes, cluster 50, opencatalogi.
- The term model and the consequence of a missed term, cluster 18, dossiq.
- A certification. A claim is a claim: C-integrations-36's lane note keeps it
  "apart from the certification row: a claim, not a certificate".
