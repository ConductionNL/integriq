# Contract: slo-kerndoelen-import

This change adds no HTTP endpoint. The interface other projects depend on is the record shape the adapter emits for learniq, and the PHP facade that produces it.

## Consumers
- `learniq`: the emitted `CompetencyFramework` and `Competency` objects, written into register `learniq` by the follow-up Synchronization. Field names agreed in `CONTRACT-competency-fields.md` (lane r2-curriculum, 2026-09-27).
- `integriq` itself: the follow-up write step stores one SynchronizationContract per record from `originId`, `uuid` and `originHash`.

## Endpoints

### `SloCurriculumSourceAdapter::importFramework(string $setKey, string $tenantId, ?string $rootUuid = null, array $subjectCourseIds = []): array`
**Auth**: in-process PHP call, no HTTP surface. The live client authenticates to SLO with the seeded source's Basic credentials.

**Request:**
```json
{
  "setKey": "fo-kerndoelen",
  "tenantId": "00000000-0000-0000-0000-000000000000",
  "rootUuid": "612afa33-c49c-4b12-a7d1-7e44f2d69d25",
  "subjectCourseIds": { "burgerschap": "00000000-0000-0000-0000-000000000000" }
}
```

**Response (success):**
```json
{
  "setKey": "fo-kerndoelen",
  "rootUuid": "612afa33-c49c-4b12-a7d1-7e44f2d69d25",
  "flavour": "mock",
  "framework": {
    "register": "learniq",
    "schema": "competency-framework",
    "uuid": "<uuid v5>",
    "originId": "612afa33-c49c-4b12-a7d1-7e44f2d69d25",
    "originHash": "<sha256>",
    "object": {
      "name": "Kerndoelen burgerschap",
      "sourceAuthority": "slo-kerndoelen",
      "sourceRef": "https://opendata.slo.nl/curriculum/uuid/612afa33-c49c-4b12-a7d1-7e44f2d69d25",
      "edition": "definitief concept",
      "level": null,
      "description": "Kerndoelen burgerschap. Bron: SLO ...",
      "proficiencyLevels": [ { "levelId": "introduce", "label": "Kennismaken", "order": 1 } ],
      "tenant_id": "00000000-0000-0000-0000-000000000000"
    }
  },
  "competencies": [
    {
      "register": "learniq",
      "schema": "competency",
      "uuid": "<uuid v5>",
      "originId": "<SLO uuid>",
      "originHash": "<sha256>",
      "object": {
        "frameworkId": "<framework uuid>",
        "parentId": null,
        "code": "Democratische oefenplaats",
        "title": "Democratische oefenplaats",
        "description": null,
        "order": 0,
        "applicableYears": [],
        "subjectId": "00000000-0000-0000-0000-000000000000",
        "tenant_id": "00000000-0000-0000-0000-000000000000"
      }
    }
  ],
  "attribution": { "text": "Bron: SLO ...", "licence": "CC BY 4.0", "licenceUrl": "https://creativecommons.org/licenses/by/4.0/deed.nl" },
  "stats": { "nodes": 19, "leaves": 10, "withYears": 0, "skippedDeprecated": 0, "skippedUnreleased": 0, "skippedDuplicates": 0, "filteredByNiveau": 0, "expansions": 0 }
}
```

Guarantees:
- `competencies` is ordered parents before children, so a writer can create them in order.
- `object` keys are exactly the learniq field names of the seeded mapping presets; no other keys.
- `uuid` is stable for the same tenant, set, root and SLO node.
- `lifecycle` is never set (learniq's lifecycle engine owns it; imported rows start as `draft`).
- `description` is `null` when SLO has no text, never an empty string.

**Errors:**
| Code | Condition |
|------|-----------|
| `UnknownSloCurriculumSetException` | `setKey` is not a seeded profile |
| `InvalidArgumentException` | `tenantId` is not a UUID; a per-root set without `rootUuid`; a `subjectCourseIds` value that is not a UUID |
| `SloCurriculumException` | SLO answered an error status, the body did not parse, a guard limit was hit, or the mock has no recording for the request |

### `SloCurriculumSourceAdapter::discoverRoots(string $setKey): array`
Returns `[{uuid, title, status}]` for the set's discovery route, skipping deprecated and unreleased entries.

### `SloCurriculumSourceAdapter::describeSets(): array`
Returns every profile as `{key, label, sourceAuthority, level, framework}`.

## Error Codes
| Code | Meaning | Condition |
|------|---------|-----------|
| `SloCurriculumException` (status 401) | No or wrong key | Source has no valid Basic credentials |
| `SloCurriculumException` (status 404) | Unknown SLO id | Root uuid not in SLO |
| `SloCurriculumException` (no status) | Parse or guard failure | Malformed JSON or JSONTag; more than 25,000 nodes, depth over 16, over 500 expansions, over 20 pages |
| `UnknownSloCurriculumSetException` | Unknown profile | `setKey` not in `configuration.sets` |

## Versioning
Record shape version 1. Additive fields may appear in `stats` and `attribution`. `object` keys change only together with learniq's schema, through the mapping presets.

## Breaking Change Policy
A renamed learniq field is changed in `CONTRACT-competency-fields.md` first, then in the two mapping presets, in the same PR as the learniq schema change. The follow-up write step reads `object` as-is.

## SLA
Not applicable: an import runs on demand. The live flavour inherits `CallService`'s timeouts and the source's rate limit.
