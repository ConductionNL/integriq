# Design: vng-klantinteracties-adapter

## Architecture Overview

The VNG Klantinteracties surface is a **packaged OpenConnector configuration**
sitting on top of a small set of **generic gateway features**. Nothing about the
Dutch dialect is added to storage — pipelinq's canonical English schema.org CRM
schemas (`ticket`, `client`, `contact`, `task`) remain the system of record.

```
KISS / municipal client
        │  VNG Klantinteracties REST (OpenKlant 2.x, OAS v0.8.0)
        ▼
/api/endpoint/{_path}            (existing public dispatcher, ADR-008)
        │  resolve Endpoint (targetType: register/schema → pipelinq register)
        ▼
EndpointService pipeline
   ├─ before Rules:  vng-avg-bsn-policy (validate+hash inbound BSN)
   │                 vng-maak-klantcontact-composite (transactional fan-out)
   ├─ input Mapping:  VNG dialect  →  canonical schema.org
   ├─ search filter:  VNG list-filters + expand=  →  OpenRegister search
   ├─ OpenRegister CRUD (pipelinq register/schema)
   ├─ output Mapping: canonical schema.org  →  VNG dialect
   └─ after Rules:   vng-selfurl-hal (absolute url + _links)
                     vng-referentienummer (stamp message ref)
        ▼
VNG-shaped JSON (absolute self-URLs, HAL _links, referentienummer)
```

The five generic features (composite fan-out Rule, VNG filter/`expand=`
translation, self-URL/HAL helper, PUT/PATCH semantics, `referentienummer`) are
built into `rule-pipeline`, `mapping-and-search` and `endpoint-runtime` so that
future VNG dialects (zaken, contactmomenten v1, klachten) reuse them. The dialect
itself is expressed as slug-referenced Endpoints/Mappings/Rules/Consumers,
exported through `ConfigurationService` (ADR-015) as a shippable OAS document.

## Goals / Non-Goals

**Goals**
- Serve the VNG Klantinteracties API from pipelinq's canonical storage with zero
  Dutch-specific storage schemas.
- Make filters, `expand=`, self-URLs and PUT/PATCH generic so the next dialect is
  config-only.
- Guarantee AVG BSN safety (hash-only, no raw reconstruction) at the gateway.

**Non-Goals**
- Klantinteracties v1 / zaken / klachten config sets.
- The actor-bridge schema (lives in the pipelinq leaf).
- Any new SPA panel.

## Decisions

### D1: Dialect as configuration, gateway features as code (mirror stuf/psd2)
Follow the `stuf-adapter` / `psd2-ais-bank-feed-connector` precedent: the dialect
is a packaged configuration; only the genuinely reusable mechanics are code.
_Alternative rejected:_ a bespoke `KlantinteractiesService` — it would duplicate
mapping/rule/dispatch machinery and not benefit the next dialect.

### D2: Endpoints target `register/schema`, not an `api` Source
Klantinteracties reads and writes pipelinq's own OpenRegister objects, so
Endpoints use `targetType: register/schema` (ADR-008) and CRUD OpenRegister
directly. _Alternative rejected:_ proxying to an external OpenKlant instance
(`targetType: api`) — pipelinq IS the store of record; there is no upstream.

### D3: Bidirectional mapping via endpoint input + output mappings
Endpoints carry an input mapping (VNG → canonical) and an output mapping
(canonical → VNG). The engine's `passThrough`/`unset`/`#-root`/`handleCast`
(incl. `unsetIfValue`, date, money) covers the field-shape work; only the AVG BSN
transform and composite fan-out need Rules. _Alternative rejected:_ a single
mega-mapping — bidirectionality and per-resource rules are clearer split.

### D4: Composite `maak-klantcontact` is a transactional Rule, not an endpoint hack
`POST /maak-klantcontact` fans out into `klantcontact` + `betrokkenen` +
`digitaleadressen` + `onderwerpobjecten`. This is modelled as a dedicated
composite Rule type that wraps the child writes and rolls them back on any
failure. _Alternative rejected:_ chained endpoints — no atomicity guarantee.

### D5: AVG BSN policy is a mandatory Rule, a documented VNG deviation
Inbound `partijIdentificator` BSNs are validated (11-proef) and SHA-256-hashed
through pipelinq's BRP flow before storage; outbound rendering never reconstructs
a raw BSN — `objectId` is omitted or replaced by a hash-backed identity. This
deviates from VNG's raw-BSN expectation and is documented as a conscious policy.
_Alternative rejected:_ storing raw BSN to satisfy VNG literally — violates
pipelinq's AVG posture (never persist raw BSN) and Dutch privacy law.

### D6: Absolute self-URLs via a generic output helper
VNG clients treat the absolute `url` as the canonical identifier and expect HAL
`_links`. A generic self-URL/HAL rendering helper (driven by
`IURLGenerator` + the resolved endpoint path) stamps these on emitted resources,
usable by any dialect. _Alternative rejected:_ hard-coding URLs in the output
mapping — not portable across hosts/environments.

## API Design

The endpoints below are **configuration** (registered Endpoint objects), not new
controller routes; they are all served by the existing `/api/endpoint/{_path}`
dispatcher.

### `POST /api/endpoint/klantinteracties/maak-klantcontact`
**Request (VNG composite):**
```json
{
  "klantcontact": { "onderwerp": "Vraag over afvalpas", "kanaal": "telefoon" },
  "betrokkene": { "rol": "klant" },
  "digitaalAdres": { "adres": "0612345678", "soortDigitaalAdres": "telefoon" }
}
```
**Response:** `201` with the created `klantcontact` carrying an absolute `url`,
HAL `_links`, and a `referentienummer`.

### `GET /api/endpoint/klantinteracties/partijen?partijIdentificator__codeSoortObjectId=bsn&expand=digitaleAdressen`
**Response:** VNG `partij` list where the BSN filter is translated to an
OpenRegister search on the hashed identity and `expand=` embeds related
`digitaleAdressen`.

## Database Changes

None. All configuration objects are OpenRegister objects in the existing
`openconnector` register; no new Nextcloud tables or migrations.

## Nextcloud Integration

- Controllers: none new — reuses `EndpointsController` public dispatcher.
- Services: extends `EndpointService` (self-URL/HAL, PUT/PATCH),
  `MappingService`/search compiler (filters, `expand=`),
  `RuleProcessingService` (composite fan-out, `referentienummer`, AVG BSN policy);
  reuses `ConfigurationService` (ADR-015 export/import).
- Mappers/Entities: reuses the OpenRegister object shim for the `openconnector`
  register (Endpoint/Mapping/Rule/Consumer objects).
- Events/Hooks: none new.

## Security Considerations

- **AVG / BSN:** see D5 — hash-only inbound, no raw reconstruction outbound;
  mandatory Rule on every `partijIdentificator` path.
- **Consumer auth:** the KISS consumer authenticates with JWT (per-consumer
  `publicKey` → NC user session, `consumer.userId`) or apiKey; every call is
  audited in the OpenRegister `call_log`.
- **Input validation:** PUT enforces all mandatory fields; PATCH is partial;
  malformed BSNs are rejected at the AVG Rule before any write.
- **No secrets in config export:** ADR-015 credential redaction applies; consumer
  keys are placeholders in the shipped config (`YOUR_API_KEY_HERE`,
  nil-UUID references).

## File Structure

```
lib/
  Service/
    EndpointService.php          # self-URL/HAL rendering; PUT/PATCH semantics
    MappingService.php           # (search compiler) VNG filter operators + expand=
    RuleProcessingService.php    # composite fan-out; referentienummer; AVG BSN policy
  Rule/                          # new composite + policy rule handlers
configuration/
  vng-klantinteracties.oas.json  # ADR-015 slug-referenced packaged config (seed)
```

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|-----------|------|-----------|
| VNG → canonical field mapping | **Config** (Endpoint input/output Mappings) | Pure data-shape transform; the mapping engine already does dot-path/Twig/cast. No code. |
| List-filter + `expand=` translation | **Code** (search compiler) | Query-language translation is generic gateway mechanics, not a per-object derived field; belongs in `mapping-and-search`. External-integration exception. |
| Absolute self-URL / HAL `_links` | **Code** (output helper) | Host/environment-aware URL rendering; not expressible as a declarative field. External-integration exception (ADR-031). |
| PUT-all-mandatory / PATCH-partial | **Code** (dispatch semantics) | HTTP-method contract enforcement in the request pipeline; not a storage concern. |
| Composite `maak-klantcontact` fan-out | **Code** (composite Rule) | Transactional multi-object write with rollback — a domain rule selector / external-integration exception, explicitly permitted by ADR-031; a declarative aggregation cannot express atomic fan-out. |
| `referentienummer` generation | **Code** (Rule/helper) | Message-identity stamping on the wire; gateway concern, not stored derived data. |
| AVG BSN validate + hash | **Code** (policy Rule, delegates to pipelinq BRP flow) | Security/compliance transform invoking BRP hashing; ADR-031 external-integration + domain-rule exception. |

**What stays config:** every Endpoint, every field Mapping, every Consumer, and
the wiring of the Rules onto Endpoints — all slug-referenced and ADR-015
exportable. Only the seven mechanics above are code, and all seven are
dialect-agnostic (reused by future VNG dialects).

## Seed Data

The packaged configuration set ships as OpenRegister objects in the
`openconnector` register (exported as `configuration/vng-klantinteracties.oas.json`
per ADR-015). Slugs below are frozen and referenced verbatim by the pipelinq
`vng-klantinteracties-leaf` fragment.

### Schema: `endpoint`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `vng-klantcontacten` | `vng-partijen` | `vng-maak-klantcontact` |
| name | Klantcontacten | Partijen | Maak Klantcontact (composite) |
| path | `klantinteracties/klantcontacten` | `klantinteracties/partijen` | `klantinteracties/maak-klantcontact` |
| method | GET,POST,PUT,PATCH | GET,POST,PUT,PATCH | POST |
| targetType | register/schema | register/schema | register/schema |
| target (register/schema) | pipelinq / ticket | pipelinq / client | pipelinq / ticket |
| inputMapping | `vng-klantcontact-in` | `vng-partij-in` | `vng-maakklantcontact-in` |
| outputMapping | `vng-klantcontact-out` | `vng-partij-out` | `vng-klantcontact-out` |
| rules | vng-avg-bsn-policy, vng-selfurl-hal, vng-referentienummer | vng-avg-bsn-policy, vng-selfurl-hal | vng-maak-klantcontact-composite, vng-avg-bsn-policy, vng-selfurl-hal, vng-referentienummer |

Additional endpoints (same shape): `vng-betrokkenen`, `vng-digitaleadressen`,
`vng-actoren`, `vng-onderwerpobjecten`, `vng-internetaken`, `vng-bijlagen`.

### Schema: `mapping`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `vng-klantcontact-in` | `vng-klantcontact-out` | `vng-partij-in` |
| direction | VNG → canonical | canonical → VNG | VNG → canonical |
| example map | `onderwerp→title`, `tekst→description`, `kanaal→channel`, `plaatsgevondenOp→occurredAt` (+ `ticketType=contactmoment`) | inverse of Object 1 + `url` self-link | `soortPartij→type` (person/organization), `partijIdentificator.bsn→(hashed)` |

### Schema: `rule`
| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | `vng-maak-klantcontact-composite` | `vng-avg-bsn-policy` | `vng-selfurl-hal` | `vng-referentienummer` |
| type | composite-fanout | data-mutation (BSN hash) | output (self-url/HAL) | output (message-ref) |
| timing | before | before | after | after |

### Schema: `consumer`
| Field | Object 1 |
|-------|----------|
| slug | `vng-kiss-consumer` |
| authorizationType | jwt |
| publicKey | `YOUR_PUBLIC_KEY_HERE` (placeholder in shipped config) |
| userId | `00000000-0000-0000-0000-000000000000` (nil UUID placeholder) |

**Related items per object:** each Endpoint links to its two Mapping objects and
its Rule objects by slug; every call is recorded in the OpenRegister `call_log`.

## Risks / Trade-offs

- [Generic features over-fitted to Klantinteracties] → specified against
  endpoint-runtime/mapping-and-search/rule-pipeline as dialect-agnostic and
  reviewed against zaken / contactmomenten-v1 as future consumers.
- [Composite fan-out partial writes] → transactional Rule with rollback.
- [Slug drift with the pipelinq leaf] → slugs frozen here; leaf gated on stability.
- [AVG raw-BSN deviation surprises a VNG conformance test] → documented explicitly
  in the spec so it is a reviewable, intentional deviation.

## Migration Plan

Additive. Deploy the code changes, then import
`configuration/vng-klantinteracties.oas.json` (ADR-015) to seed the config set.
Rollback: delete the seeded Endpoints/Mappings/Rules/Consumers (VNG surface off,
no impact to other endpoints) and git-revert the code additions.

## Open Questions

- `referentienummer` format — UUIDv4 by default; a municipality numbering scheme
  may override (leaf change may refine).
- Whether `expand=` should be capped in depth to bound OpenRegister search cost.
