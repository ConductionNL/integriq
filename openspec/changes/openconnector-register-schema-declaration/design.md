# Design: openconnector-register-schema-declaration

## Architecture Overview

This change adds two static JSON files; it ships no PHP. The architectural surface
is the **descriptor contract** between openconnector and OpenRegister at app
install/upgrade time:

```
openconnector/lib/Settings/openconnector_register.json
        │
        │ read by
        ▼
OCA\OpenRegister\Service\ConfigurationService::importFromApp(
  appId:       'openconnector',
  registerJson: <path>,
  version:     '<openconnector app version>',
  force:       false,
)
        │
        ▼
oc_openregister_registers   (+1 row: slug 'openconnector')
oc_openregister_schemas     (+15 rows)
oc_openregister_objects     (+ seed objects, populated from openconnector_seed_data.json)
```

No request, response, controller, route, or service is added on the openconnector
side. The companion change `openconnector-register-storage` is what invokes the
importer (from a Nextcloud migration class).

## Declarative-vs-imperative decision (ADR-031)

This change is **100% declarative**. Every behaviour it introduces is encoded as
JSON Schema + `x-openregister-*` annotations:

| Concern                          | Declarative form                                  |
|----------------------------------|---------------------------------------------------|
| Append-only enforcement          | `appendOnly: true` on log schemas                 |
| Immutability after insert        | `immutable: true` on log schemas                  |
| Retention windows                | `x-openregister-archival.retentionPeriod` (ISO-8601) |
| Foreign-key relations            | `format: "uuid"` + `$ref: "<target-schema>"`      |
| Cascade-delete behaviour         | `onDelete: "CASCADE"` / `"SET NULL"` per relation |
| Required fields                  | JSON Schema `required` array                       |
| Enums (`status`, `auth`, etc.)   | JSON Schema `enum`                                |
| Defaults                         | JSON Schema `default`                             |
| Maximum-depth nesting            | `maxDepth` (per schema)                            |

No code is needed because every supported feature already exists in
OpenRegister's `Schema` entity (`lib/Db/Schema.php`) and `ConfigurationService`.
ADR-031's "scheduled bulk work" exception does **not** apply here — there is no
one-shot or scheduled imperative work in this chain. (The companion code chain
takes the imperative path for the one-shot data migration; that's where ADR-031's
exception is invoked.)

## API Design

This change adds no HTTP endpoints. The descriptor consumed by OR is itself an
OpenAPI 3.0 document, but it is read off disk by OR's importer rather than served.

## Database Changes

No openconnector table is created, altered, or dropped by this change. After OR
provisions the descriptor, OR's own tables receive new rows but its schema is
unchanged:

- `oc_openregister_registers`: +1 row (`slug='openconnector'`)
- `oc_openregister_schemas`: +15 rows
- `oc_openregister_objects`: +N seed rows (33 — see Seed Data below)

No migration class in `lib/Migration/` is added by this change. The companion
`openconnector-register-storage` change adds the migration class that triggers
the importer.

## Nextcloud Integration

No new Nextcloud integration. The descriptor is read by openregister at install /
upgrade time through its existing `ConfigurationService::importFromApp(...)`
mechanism — identical pattern to pipelinq and procest.

- Controllers: none
- Services: none
- Mappers/Entities: none
- Events/Hooks: none

## Security Considerations

- **Secrets in seed data.** `Source` carries six encrypted columns (`secret`,
  `password`, `apikey`, `jwt`, `username`, plus `authenticationConfig` may contain
  secrets). Seed objects MUST use placeholder values
  (`"YOUR_API_KEY_HERE"`, `00000000-0000-0000-0000-000000000000`,
  `"<placeholder>"`) — never realistic-looking credentials.
- **Append-only protection.** `appendOnly: true` + `immutable: true` together
  defend the log schemas against tampering. `appendOnly` allows INSERTs but blocks
  UPDATE/DELETE; `immutable` is the stronger guarantee that ALSO blocks INSERT
  modification of already-set fields. The combination matches the
  `audit-trail-immutable` spec expectation.
- **No new auth surface** — the descriptor is consumed by OR's importer which
  already runs under Nextcloud's admin privilege at install/upgrade time.

## File Structure

```
openconnector/
├── lib/
│   └── Settings/
│       ├── openconnector_register.json        # NEW — register + 15 schemas
│       └── openconnector_seed_data.json       # NEW — ~33 seed objects
└── openspec/
    └── changes/
        └── openconnector-register-schema-declaration/
            ├── proposal.md
            ├── design.md            (this file)
            ├── discovery.md
            ├── contract.md
            ├── specs/openconnector-register-schema/spec.md
            ├── migration.md
            ├── tasks.md
            └── test-plan.md
```

## Seed Data

Per ADR-001 lines 40–50, every mutable config schema receives 3–5 realistic
objects modelling general-organisation data (works for municipality, consultancy,
travel agency). Log schemas receive **no seed data** (append-only, immutable —
seeding would either fail or pollute the audit trail).

Each seed object carries the `@self` envelope: `{ register: "openconnector",
schema: "<schema-slug>", slug: "<object-slug>" }`.

### Schema: `source` (3 objects)
| Field        | conduction-postnl-api      | gemeente-amsterdam-zaakregister | salesforce-crm           |
|--------------|----------------------------|---------------------------------|--------------------------|
| name         | PostNL Tracking API        | Amsterdam Zaakregister          | Salesforce CRM           |
| description  | NL postal tracking source  | Municipal case-register source  | CRM lead source          |
| type         | api                        | api                             | api                      |
| location     | https://api.postnl.nl/v1   | https://zaken.amsterdam.nl/api  | https://example.my.salesforce.com |
| auth         | apikey                     | oauth2                          | oauth2                   |
| isEnabled    | true                       | true                            | false                    |
| apikey       | YOUR_API_KEY_HERE          | YOUR_API_KEY_HERE               | YOUR_API_KEY_HERE        |
| slug         | postnl-tracking            | amsterdam-zaakregister          | salesforce-crm           |

### Schema: `consumer` (3 objects)
| Field              | dashboard-app              | reporting-bi          | partner-integration       |
|--------------------|----------------------------|------------------------|---------------------------|
| name               | Dashboard App              | Reporting BI           | Partner Integration       |
| description        | Frontend dashboard         | BI reporting consumer  | External partner system   |
| authorizationType  | bearer                     | apiKey                 | oauth2                    |
| domains            | ["dashboard.example.org"]  | ["bi.example.org"]     | ["partner.example.com"]   |

### Schema: `endpoint` (3 objects)
| Field         | get-cases                          | post-case            | get-document             |
|---------------|------------------------------------|----------------------|--------------------------|
| name          | Get Cases                          | Create Case          | Get Document             |
| endpoint      | /api/v1/cases                      | /api/v1/cases        | /api/v1/documents/{{id}} |
| method        | GET                                | POST                 | GET                      |
| targetType    | register/schema                    | register/schema      | register/schema          |

### Schema: `event` (3 objects)
| Field         | case-created            | document-uploaded         | sync-completed         |
|---------------|--------------------------|---------------------------|------------------------|
| source        | /openconnector/cases     | /openconnector/documents  | /openconnector/sync    |
| type          | nl.gov.case.created      | nl.gov.document.uploaded  | nl.openconnector.sync.completed |
| subject       | case/00000000-0000-0000-0000-000000000001 | doc/00000000-0000-0000-0000-000000000002 | sync/00000000-0000-0000-0000-000000000003 |
| status        | pending                  | pending                   | processed              |

### Schema: `event_subscription` (3 objects)
| Field         | webhook-pipelinq         | webhook-decidesk     | pull-archive            |
|---------------|--------------------------|----------------------|--------------------------|
| source        | /openconnector/cases     | /openconnector/cases | /openconnector/archive   |
| types         | ["nl.gov.case.created"]  | ["nl.gov.case.closed"] | ["nl.gov.archive.transferred"] |
| sink          | https://pipelinq.example.org/hooks/oc | https://decidesk.example.org/hooks/oc | (pull) |
| protocol      | HTTP                     | HTTP                  | HTTP                     |
| style         | push                     | push                  | pull                     |

### Schema: `job` (3 objects)
| Field         | hourly-sync             | nightly-archive       | weekly-cleanup          |
|---------------|--------------------------|------------------------|--------------------------|
| name          | Hourly Synchronisation   | Nightly Archive Sweep  | Weekly Cleanup           |
| jobClass      | OCA\\OpenConnector\\Action\\SynchronizationAction | OCA\\OpenConnector\\Action\\ArchiveAction | OCA\\OpenConnector\\Action\\CleanupAction |
| interval      | 3600                     | 86400                  | 604800                   |
| isEnabled     | true                     | true                   | true                     |

### Schema: `mapping` (3 objects)
| Field         | postnl-to-shipment       | crm-to-contact         | api-error-to-jsonlogic |
|---------------|--------------------------|------------------------|-------------------------|
| name          | PostNL to Shipment       | CRM to Contact         | Error JSONLogic        |
| reference     | mapping.postnl.shipment  | mapping.crm.contact    | mapping.error.logic    |
| passThrough   | false                    | false                  | false                  |

### Schema: `rule` (3 objects)
| Field         | dedupe-before-write      | log-error-after-call   | retry-on-429           |
|---------------|--------------------------|------------------------|-------------------------|
| name          | De-duplicate before write| Log error after call   | Retry on HTTP 429      |
| action        | create                   | read                   | read                   |
| timing        | before                   | after                  | after                  |
| type          | mapping                  | error                  | error                  |

### Schema: `synchronization` (3 objects)
| Field         | postnl-to-cases          | salesforce-to-clients  | amsterdam-to-zaken     |
|---------------|--------------------------|------------------------|-------------------------|
| name          | PostNL → Cases           | Salesforce → Clients   | Amsterdam → Zaken      |
| sourceType    | api                      | api                    | api                    |
| targetType    | register/schema          | register/schema        | register/schema        |

### Schema: `synchronization_contract` (3 objects)
| Field              | postnl-shipment-123      | sf-client-456          | amsterdam-zaak-789     |
|--------------------|--------------------------|------------------------|-------------------------|
| synchronizationId  | (uuid of postnl-to-cases) | (uuid of sf-to-clients) | (uuid of amsterdam-to-zaken) |
| originId           | postnl/shipment/123      | sf/lead/456            | amsterdam/zaak/789     |
| targetLastAction   | create                   | update                 | create                 |

**Related items per object:** None for this change — seed objects are pure config
entries with no file/note/task/contact attachments.

## Trade-offs

| Alternative considered                                | Chosen?  | Reasoning |
|-------------------------------------------------------|----------|-----------|
| Encode FK relations as flat string fields (no `$ref`) | No       | Loses OR's `_relations`/`extend`/`inversedBy`/cascade machinery — defeats the whole point. |
| Drop `*Id` columns immediately, rename to schema name | No       | Frontend Vue stores still read `sourceId` etc. Breaking change must be staged. |
| Express retention in PHP constants only               | No       | Replicates the triplication problem. Whole register goal is one canonical declaration. |
| Combine all 15 schemas into a single OpenAPI file (vs 15 separate files) | Yes — single file | Matches pipelinq + procest precedent; importer expects one file per register. |
| `appendOnly` only (no `immutable`) for logs           | No       | `appendOnly` alone permits delete via admin tools. Combined flag prevents drift in both directions. |
| Set `archival.retentionPeriod` to OR default (no override) | No   | Default differs across OR versions; openconnector's retention is explicitly business-defined. |
