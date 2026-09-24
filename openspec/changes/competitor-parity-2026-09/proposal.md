---
kind: umbrella
depends_on: []
---

# Proposal: competitor-parity-2026-09

## Summary

This is the integriq half of the dossiq competitor parity programme. The
source of record is the gap register at `procest/_gaps/` in
ConductionNL/market-intelligence: `README.md`, `gap-register.md`,
`gap-register.json` and `ownership-rules.md`, written 2026-09-13.

Ruben's rule from the ownership rules governs the split. Dossiq reaches
100% comparability with the competition. Logic that belongs to another app
is specified in that app, and dossiq consumes it. Integriq is one of those
owner apps, so this umbrella indexes the logic integriq owes.

Nothing here is implemented. Each indexed change carries its own
`proposal.md`, `design.md`, `specs/` and `tasks.md`.

## The changes

Three changes on integriq's `development` cite the register. A sweep of
every other proposal in `openspec/changes/` found no fourth. Sizes are the
register's own, quoted in each proposal.

| change | rows | size | dossiq consumer |
|---|---|---|---|
| `document-generation-vendor-adapter` | 12.11 | M | Dossiq binds `TemplateEngineAdapterInterface` to filinq and deletes `MockTemplateEngineAdapter`. The vendor sits one hop behind filinq. |
| `signed-outbound-webhooks` | Q6.20 | S | None of its own. Dossiq retires its `WebhookHandler` under `dossiq-delivers-nothing`, and its outbound traffic is then signed by default. |
| `objecten-api-facade` | 12.3 | M | Dossiq declares its `caseObject` types as objecttypes in its endpoint declaration and ships no controller for the standard. |

Row 12.3 started on openregister. The openregister lane handed it here and
wrote the argument in its own umbrella: ADR-091 §6 puts ZGW, StUF, DSO and
Notificaties in integriq, and openregister's `specs/zgw-api-mapping` is a
redirect stub.

## Build order

1. `signed-outbound-webhooks`. Start now. It extends `webhook-signing`,
   which already ships signing, rotation and a one-time reveal. The work is
   to make the secret part of what a subscription is, and to show an
   unsigned subscription as unsigned.
2. `objecten-api-facade`. Start now. Openregister's half exists and is not
   rebuilt: the endpoint dispatch, the objects API, the schema export and
   the RBAC behind them.
3. `document-generation-vendor-adapter`. The seam can be built now. Its
   value reaches dossiq only after filinq offers a vendor engine per
   template, so build it last of the three.

## Halves another app carries

Two of the three rows close only when another app does its part.

- **Filinq**, for row 12.11. Filinq owns document generation under ADR-075.
  It needs a template `engine` per template on
  `document-creatie-sjablonen`, so a template can point at a vendor source
  instead of its own Twig engine. Without that, nothing calls the seam.
- **Dossiq**, for rows 12.11 and 12.3. Both halves are one binding or one
  declaration plus a deletion. They are named in dossiq's own umbrella.
- **Openregister**, for row 12.3. Its half is already shipped. This change
  configures it, it does not extend it.

## Register corrections

- Row Q6.20's `covered` column reads "none (events-cloudevents specifies
  retry and dead letter, not signing)". Integriq's outbound signing lives in
  `openspec/specs/webhook-signing/`, with `WebhookSignatureService`
  implementing it. Re-point the row at `webhook-signing`.
- Row 12.3 lists openregister as owner. Re-point it at integriq, citing
  ADR-091 §6.

## Discovery wave 1

A second source of record sits beside the gap register: the round 4
discovery sweep, `procest/_round4/discovery/` in
ConductionNL/market-intelligence, written 2026-09-14. Thirty-six systems
read, 631 consolidated candidates, 70 capability clusters in
`build-plan.md`, 22 decisions in `decisions.md`. Ruben answered all 22 on
2026-09-14 and lifted the build hold.

The ownership rule moves 57 of the 631 candidates to integriq, in eight
clusters. This section indexes what integriq opens in wave 1.

| change | cluster | candidates | size | decision | dossiq consumer |
|---|---|---|---|---|---|
| `registry-backed-field-source` | 26 "Fields read live from a registry, not copied", and depth-study CT-5 | C-integrations-9 (matrix hole), C-intake-10, C-parties-and-contacts-15, C-parties-and-contacts-4, C-integrations-11, C-integrations-43 | L | D2 | dossiq declares a source on a `propertyDefinition`, in its CT-1 change `casetype-field-vocabulary`, and stops being limited to one fixed address slot and one fixed person slot |

### The mail account is Nextcloud Mail's, so integriq opens nothing for it

`build-plan.md` puts cluster 28, "Mail accounts, OAuth2 and alias
domains", on integriq in wave 1, and `decisions.md` D12 recommended that
integriq hold the account, the token and the alias domains.

Ruben answered D12 differently: **Nextcloud Mail owns the mail account**,
because the OAuth 2.0 flow is already Nextcloud Mail's. Integriq opens no
mail-account change. The work moves to dossiq, whose intake pipeline reads
the account Nextcloud Mail already holds, and which keeps the filter
pipeline and the sender-authentication half. dossiq's wave 1 change is
`inbound-mail-filters`, cluster 25.

Cluster 60, "Outbound sender identity and deliverability", stays integriq's
and stays in a later wave. It now builds on a Nextcloud Mail account rather
than on an integriq one, which is a re-read that cluster needs before it is
written.

### What openregister owes this wave, and by what name

`registry-backed-field-source` needs one key on a schema property, and the
key is openregister's under D2. This umbrella asks for it by name so two
lanes do not invent two:

- **`x-openregister-property-source`**, `{ provider, config, mode }`, on a
  schema property, to be specified in openregister, wave 1.
- It is not `x-openregister-object-source`, which openregister's open
  change `object-source-providers` already uses to serve a whole schema's
  objects from a provider.

### Two decisions that change how the clusters are read

- **D6 was answered relevance-led.** Every `must` candidate enters the
  corpus as a row, whatever its passer count. A `must` cluster is not
  skipped for having one passer. In integriq's wave 1 that admits
  C-intake-10, a `must` with one documented passer and no driven one.
- **D17 was answered for a broad market.** The 20 candidates the lanes
  rated `not` are not disqualified: the product serves MKB as well as
  municipalities, so a `not` for a gemeente can be a `could` for an MKB
  buyer. None of the 20 falls in integriq's wave 1 cluster, so nothing in
  this section changes on that count.

### Later waves

Seven further integriq clusters waited after wave 1. Five of them open in
wave 3 below: 23, 27, 33, 45 and 56. Two stay closed. Cluster 28, mail
accounts, is closed under D12. Cluster 60, outbound sender identity, needs a
re-read first, because D12 moved the account it rests on to Nextcloud Mail.

## Discovery wave 3

Wave 3 is the rest and the long ones. The build plan says what that means:
"humaniq takes agenda, rostering and time. pipelinq takes the project above
the cases. hermiq takes the assistant. **integriq takes the statutory
gateways.** buildiq takes the layout per case type. openregister takes
tenancy. All L, and none of them blocks a tender answer."

Seven changes open here. Five carry one of integriq's eight clusters. The
other two carry work the ownership rule gives integriq inside a cluster
another app owns, and each names that cluster and its owner in its own
proposal.

| change | cluster | candidates | size | decision | dossiq consumer |
|---|---|---|---|---|---|
| `statutory-gateways-and-frameworks` | 56 "The statutory gateways and the frameworks we claim" | C-integrations-27, 28, 29, 37, 47, 3, 4, 30, 36, 39, 46, 23; rows 12.9 and 12.17 | L | D21, D6 | dossiq declares the WKPB flag on a case type and the registry binding, and keeps its ZGW controllers unchanged |
| `outbound-communication-log` | 23 "The outbound communication log, per recipient and per step" | C-communication-33, 57, 5 (three matrix holes), 58, 56, 39, 10, 40; rows 6.11, 6.20, 6.23 | M | none | dossiq renders the send history on the case and projects the last-contact answer onto its own searchable field, which the lane marks `dossiq-only 6.20` |
| `outbound-call-delivery-and-replay` | 27 "Delivery, retry and replay of an outbound call" | C-integrations-7, 31, 45 (three matrix holes), 12, 6, 16, 20, 32; row 6.11 | M | none | dossiq retires the hardcoded schedule in `lib/BackgroundJob/StufRetryJob.php` and shows the call log filtered to a case |
| `directory-and-group-sync` | 33 "Directory synchronisation and one place for access" | C-access-and-privacy-82 (matrix hole), C-integrations-34, C-integrations-21 | M | none | dossiq's `roleType.ncGroupId` groups are filled from the directory instead of from local configuration |
| `intake-channels-beyond-mail` | 45 "Intake channels beyond mail" | C-intake-21, 3, 35, 4, 12, C-tasks-and-phases-31 | M | none | dossiq writes `case.intakeChannel` from the channel that delivered the message, and holds no channel-specific code |
| `migration-source-adapters` | 8 "Migration in and migration out", owner openregister; integriq holds the source adapters by the cluster's own mechanism line | C-configuration-88, C-configuration-16 (both matrix holes), and the read half of C-configuration-95 | M | none | none directly. Migrated cases reach dossiq through OpenRegister in the shape it already reads |
| `allowlisted-expression-sources` | depth study D-casetype-20; consolidated candidate C-access-and-privacy-40 sits in cluster 4, owner openregister | C-access-and-privacy-40 | S | D3 for the boundary | none directly. An expression in a case type is evaluated by openregister, which asks integriq for a prefixed value |

Numbers from `_round4/discovery/found-and-lacking.md`, "The twenty-five
loudest": number 2 is the migration path out of a named competing product,
eight driven; number 7 is directory user and group synchronisation, four
driven; number 8 is replaying a failed delivery or firing one by hand, five
driven; number 13 is bulk import from a file with a column mapping, two
driven. All four are answered above.

### What is deliberately not opened

- **Cluster 28, mail accounts, OAuth2 and alias domains.** D12: Nextcloud
  Mail owns the account. Recorded in wave 1 above and unchanged.
- **Cluster 60, outbound sender identity and deliverability.** It now builds
  on a Nextcloud Mail account rather than an integriq one, which is the
  re-read wave 1 asked for. `outbound-communication-log` adds the sender
  identity as one more field on its record when cluster 60 lands. The
  re-read is done and the cluster opens in wave 4 below. It is cluster
  **61** in both sources; "60" above is a typo in this umbrella and the
  wave 4 section records it.
- **Cluster 26 and CT-5** are wave 1's `registry-backed-field-source`, merged
  as integriq#1997. Wave 3 adds nothing to them.

### Candidates recorded and not built

Each is named in the proposal that carries its cluster, with the reason, so
none of them is rediscovered.

| candidate | why not built | who, if anyone |
|---|---|---|
| C-integrations-21, acting as an identity provider for another product | the lane admitted it to record a distinction: "the direction a gemeente wants is the other one". Nextcloud is already an OAuth provider | nobody |
| C-integrations-6, a scheduled mirror in either direction | `synchronization-engine` already routes both directions, paginates, tracks completeness and guards deletion | a configuration recipe |
| C-integrations-32, federation between instances | a Nextcloud platform capability; decision D9 asks whether the ten platform integration points run as one programme | the platform programme |
| C-intake-4, a case created from another application's text box | named by the lane as one of those same ten platform integration points | the platform programme |
| C-intake-12 and C-tasks-and-phases-31, a native mobile application | documented passers only, capped as an upper bound under D21; none of the four driven Dutch systems ships one either | a product decision |
| C-communication-40, draft replies kept on the case | a draft is composed on the case surface and is not a send | dossiq |
| C-integrations-3, a case type flagged for lex silencio positivo or dwangsom | a deadline outcome, not a route; dossiq already ships `NoticeOfDefaultController` and `DwangsomPaymentCallbackController` | dossiq, cluster 18 |
| C-integrations-23, the shared zakenmagazijn and zaaktypecatalogus | reads `yes` for dossiq: "OpenRegister plus the ZGW controllers" | nobody |

### What other apps owe wave 3

- **openregister**: the import engine, its preview and its conflict policy
  for cluster 8; whole-instance export and import, C-integrations-50; the
  restorable dump before destruction, C-integrations-22; the expression
  language and the rest of cluster 4.
- **opencatalogi**: cluster 50, publication and the national indexes, over
  the Wet elektronisch publiceren gateway this wave builds.
- **portaliq**: cluster 51, the intake form as its own object, which the
  submission mapping in `intake-channels-beyond-mail` names.
- **dossiq**: the case-surface halves listed in the consumer column above,
  plus the `lastTold` field and the case-type flags.

### Two decisions, applied again

- **D6, relevance-led.** Every `must` enters whatever its passer count. In
  wave 3 that admits the five documented `must` candidates of cluster 56 and
  C-tasks-and-phases-33's sibling reasoning elsewhere in the fleet.
- **D21, documented candidates admitted and labelled.** Cluster 56 has five
  documented passers of six, and D21's own "waits on it" line names it first:
  "The statutory gateways (five of six passers documented)". Every documented
  passer in wave 3 is labelled and none is counted in a driven tally.

## Discovery wave 4 (2026-09-14)

Wave 4 closes the last cluster integriq recorded and did not open. The
re-read wave 1 asked for is done: decision D12, as Ruben answered it, puts
the mail account in Nextcloud Mail, so a sender identity is a face on an
account somebody else owns rather than an account of integriq's own.

| change | cluster | candidates | size | decision | dossiq consumer |
|---|---|---|---|---|---|
| `outbound-sender-identity-and-deliverability` | 61 "Outbound sender identity and deliverability" | C-communication-24, 26, 43, 44, 45, 50, 51, 65, 66 | M | D12, D21 | needs a dossiq change: the sender identity declared per team on the case type, replacing the single `EmailSettings.php` instance address |

The cluster's own mechanism line reads "extend integriq's outbound mail
path; dossiq declares the sender per team on the case type". The change
extends `outbound-communication-log`: a message's identity is the field the
wave 3 index promised that log would gain.

### A numbering correction

Wave 1 and wave 3 above call this cluster 60. Both sources call it 61:
`build-plan.md` heading "### 61. Outbound sender identity and
deliverability", and the gap register's cluster table row 61, naming the
same nine candidates. Cluster 60 is agenda, rostering and resource booking,
owned by humaniq under D19, and nothing here touches it. The change uses
61. The earlier text is left as written so the correction is visible rather
than quietly applied.

### What still stays closed

- **Cluster 28, mail accounts, OAuth2 and alias domains.** D12. Unchanged
  by wave 4: an identity references a Nextcloud Mail account and never
  holds a credential.

### What other apps owe wave 4

- **dossiq**: the sender declaration per team or per case type. Its
  `inbound-mail-filters` is the inbound sibling and is unaffected.
- **Nextcloud Mail**: the account and its OAuth 2.0 flow, per D12.

## The pending proposals of the gap register (2026-09-14)

The last half of the parity programme is the register's pending proposals: the
rows dossiq published from `dossiq#2314` and the 98 rows promoted under decision
D1. Four of them are integriq's. Two are already answered by a change on
`development`, by substance rather than by name, and two open here.

| change | rows | size | basis | consumes from |
|---|---|---|---|---|
| `records-owned-by-an-external-source` | 5.19 | M | new | dossiq declares the ownership mode and the disappearance policy on the `brpPerson` and `kvkCompany` synchronisations in `register.d/25-brp-kvk.json`, renders the ownership state on the contact and the case party, and drops the delete action on a party it does not own. To be specified in dossiq, on the surface `contacts-domain` opens |
| `one-off-and-suppressed-recipients` | 6.23 | M | new | dossiq offers the send screen where a handler adds a one-off recipient or suppresses a standing one with a reason, and passes both on the delivery request its `dossiq-delivers-nothing` change already dispatches. To be specified in dossiq |
| `outbound-communication-log` | 6.24, 6.27 | M | existing, by substance | unchanged from wave 3: dossiq renders the send history on the case and projects the last-contact answer onto its own searchable field |

### The two rows an existing change already carries

Both were read in full before the claim was made, `proposal.md` and the spec.

- **6.27**, "Delivery outcome per recipient on every outgoing message, with a
  reason", is REQ-OCL-001: "each recipient with its own status, and an ordered
  list of steps with their outcomes. A failure MUST name the step it happened
  in and the reason the transport gave", with REQ-OCL-005 keeping delivery and
  read state honest where a channel reports neither.
- **6.24**, "When the applicant was last actually reached, sortable in the work
  list", is REQ-OCL-006: "The log answers when a recipient was last told
  anything". Wave 3 already splits the row the way the lane marked it,
  `dossiq-only 6.20`: integriq answers the query and dossiq projects it onto
  the searchable field.

One reservation is recorded rather than quietly resolved. REQ-OCL-006 answers
from "the most recent message that reached at least the transport step", and
the row says *actually reached*, which is the delivery confirmation REQ-OCL-005
holds per recipient and not the handover. The material for both readings is in
the same change, so the row is claimed there; whoever implements it should
decide which of the two the field means, and say so.

### What the competitor evidence is for these rows

Nothing, and that is the finding. All four are D1 rows, and the corpus batch
file states it: "Every competitor column is `unread`, and none of them is `no`.
... `no` is a reading of a product somebody opened, and filling these cells with
it would fabricate thirty readings per row." No proposal in this half claims a
competitor behaviour.
