## Context

openconnector is the fleet's **adapter layer**. Almost every Dutch word in it is not our
vocabulary — it is the field name of an external API that openconnector exists to speak
to. The fleet policy's exemption ("statutory wire field names, inside the adapter layer
only") is therefore not a footnote here; it governs most of the change.

Token-aware scan: **7 schemas / 15 Dutch properties**, plus **12 files / 12 classes /
45 methods** carrying Dutch identifiers.

### The exemption is per-layer, not per-app

Reading each schema's own description settles the classification:

| schema | its own description says | verdict |
|---|---|---|
| `kiss_klantcontact` | "**mirrors** one klantcontact from a KISS deployment's **VNG Klantinteracties API**" | WIRE |
| `stuf_message` | "one inbound **zakLk01/edcLk01** kennisgeving" (StUF-ZKN) | WIRE |
| `iwmo_ijw_message` | "one outbound Wmo/Jw **berichttype** dispatch" (iWMO/iJW standard) | WIRE |
| `dso_verzoek` | "one DSO Verzoek received on the **STAM koppelvlak**" | WIRE |
| `notificaties_abonnement` | "registration against a remote **ZGW Notificaties API** (Logius/VNG)" | WIRE |
| `event_subscription` | "pairs a Consumer with an event-type filter (**CloudEvents** Subscription API)" | mixed — see decision 3 |
| `ris_sync_record` | "tracks each outbound push or inbound pull **between OpenConnector and** an iBabs or NotuBiz RIS" | **OURS** |

`berichttype` and `verwerkingssoort` are literal StUF elements. `kanalen[].naam` is the
literal ZGW Notificaties field. `bronorganisatie` is the literal DSO/STAM field. Renaming
any of them does not internationalise openconnector — it breaks it, silently, because the
payload it must match no longer matches.

## Goals / Non-Goals

**Goals:**

- Rename the vocabulary that is genuinely **ours**: `ris_sync_record`, our own
  bookkeeping record.
- Mark every wire schema explicitly, so the next reader does not re-litigate the
  classification, and so a future sweep does not "fix" it.
- Rename Dutch in class and method names where the name describes **our** behaviour
  rather than naming an external product.

**Non-Goals:**

- Renaming wire field names. `berichttype`, `verwerkingssoort`, `bronorganisatie`,
  `kanalen[].naam`, `kenmerk` and the KISS `klantcontact` fields stay.
- Renaming external products. `Berichtenbox` is MijnOverheid's product name;
  `Klantinteracties`, `Digikoppeling`, `StUF`, `iBabs`, `Haal Centraal` are proper
  names. The ratified exemption covers these.
- Any change to ZGW resource names at the protocol boundary (`Zaak`, `Besluit`,
  `ZaakType` as they appear in ZGW URLs and payloads).

## Decisions

### 1. `ris_sync_record` is ours and is renamed

Its own description places it "between OpenConnector and" the RIS — it is our sync
bookkeeping, not a mirror of an iBabs payload. Its `title` fields are already English
(`Case ID`, `RIS Meeting ID`, `Decision Status`), which is the same self-disagreement
tell openbuild showed.

| property | title already says | new name |
|---|---|---|
| `zaakId` | `Case ID` | `caseId` |
| `risVergaderingId` | `RIS Meeting ID` | `risMeetingId` |
| `besluitStatus` | `Decision Status` | `decisionStatus` |

⚠️ `caseId` is a **cross-app key**. openconnector holds a foreign key into procest, and
so does docudesk. Per the ratified application order, procest renames first and these
two follow in the same window. openconnector must not land `caseId` alone.

### 2. `besluitStatus` carries the *instrument* sense — confirmed, not assumed

The fleet policy left open which of the two `besluit` senses this property holds.
`IBabsConnectorService.php:533` reads `$body['besluitStatus']` from the iBabs response
and maps it onto decidesk's `Decision.outcome`. That is the decision **instrument**
sense, the same one as procest's `Besluit` — not opencatalogi's decision *letter*.

**Decision:** `besluitStatus` → `decisionStatus`. Question closed.

### 3. The enum values stay Dutch even though the property is renamed

`besluitStatus` enumerates `aangenomen` / `verworpen` / `aangehouden` /
`doorgeschoven`. These are the values **iBabs sends**. The property name is ours; the
values are the wire.

**Decision:** rename the property, keep the enum. A stored `decisionStatus:
"aangenomen"` is correct — it records what the RIS said. Translating the values would
mean the record no longer reproduces the source, and the mapping in
`Mapping-iBabs Besluit to Decision-v0.0.1.json` (which lowercases and compares against
`aangenomen`) would silently match nothing.

This split — English key, wire value — is the general rule for the adapter layer.

### 4. `event_subscription.action.kenmerken` needs one more read before it moves

The schema describes itself as CloudEvents, and CloudEvents has no `kenmerken` field;
`kenmerken` is ZGW Notificaties vocabulary. So either the schema is a CloudEvents
record that borrowed a ZGW word (ours — rename to `characteristics`, matching its own
`title`), or it carries a ZGW payload through (wire — keep).

**Decision:** deferred to the read, not guessed. Recorded as an open question rather
than resolved by preference, because guessing either way is silent: renaming a wire
field breaks the payload match, and keeping ours leaves Dutch in the codebase.

### 5. Class and method names split the same way

`BerichtenboxClient`, `KlantinteractiesClient`, `BesluitTranslator`, `ZaakTranslator`,
`InboundBerichtTranslator` all name **the external thing they translate**. A
`ZaakTranslator` translates ZGW `Zaak` resources between API versions; renaming it
`CaseTranslator` would misname what it actually handles.

**Decision:** keep adapter classes named after the protocol resource they adapt. Rename
only identifiers describing our own behaviour — e.g. `addsBeheerRoute`
(`addsAdminRoute`), `downloadBijlagen` (`downloadAttachments`) where the method is our
logic rather than a protocol operation. Each of the 45 method hits is classified
individually; there is no bulk rename here.

## Risks / Trade-offs

- **A wire field is renamed and the payload silently stops matching** → the adapter
  keeps running and produces empty results, which looks like "no data" rather than a
  bug. Mitigated by classifying every property against its schema's own description
  before touching it, and by keeping enum values on the wire side.
- **`caseId` is renamed unilaterally** → procest and docudesk desynchronise, and no
  app's own tests notice because every consumer reads with `??`. Mitigated by the
  coordinated window; openconnector does not land this alone.
- **A bulk rename is applied to the 45 method hits** → adapter methods get misnamed
  after concepts they do not handle. Mitigated by individual classification; this is
  explicitly not a scripted change.
- **The classification is re-litigated later and reversed** → mitigated by recording it
  in the schema itself (decision 6 below), not only in this document.

## Migration Plan

1. Mark all five wire schemas with an explicit wire/statutory marker so the
   classification travels with the schema.
2. Classify each of the 45 Dutch method names and 12 class names as protocol-facing or
   ours; rename only the latter.
3. Resolve the `event_subscription.action.kenmerken` question by reading its producer
   and consumer.
4. Rename `risVergaderingId` → `risMeetingId` and `besluitStatus` → `decisionStatus`
   (both app-local, no coordination needed).
5. **Hold `zaakId` → `caseId`** until procest's rename lands, then apply in the same window.

**Rollback:** steps 1–4 are app-local and revert cleanly. Step 5 cannot be rolled back
independently of procest and docudesk — the three move together or not at all.

## Open Questions

- Does `event_subscription.action.kenmerken` carry a ZGW Notificaties payload (wire, keep)
  or is it a CloudEvents record that borrowed the word (ours, rename)? Its `title` reads
  `Characteristics`, which hints at ours, but a title is intent and the payload is fact.
- Of the 45 Dutch method names, how many are protocol operations versus our own logic?
  The scan counts them; it does not classify them. That classification is the bulk of
  the work in this change and has not yet been done.
