# Contract: openconnector-comprehensive-tests

## Consumers

Chain E introduces no new API endpoints. All tests exercise the existing OpenConnector REST
API that was defined in chains B and C. There are no new producer/consumer contracts to
establish.

This document records the **test-side** contract: which CI jobs are merge-blocking, what
threshold values are enforced, and what artifact each gate produces, so that consuming PRs
(future chains, product features) know what they must not break.

---

## CI Gate Contract

### PHPUnit Coverage Gate

**Trigger**: every PR to `development`

**Endpoint (internal)**: `composer test:coverage` followed by `composer coverage:check`

**Enforcement**:

| Gate | Threshold | Action on failure |
|------|-----------|-------------------|
| Line coverage | ≥ 80% | PR blocked |
| Branch coverage | ≥ 70% | PR blocked |

**Artifact produced**: `coverage/clover.xml` (uploaded as workflow artifact)

**Auth**: N/A (runs in container, no HTTP auth)

---

### Newman API Gate

**Trigger**: every PR to `development`

**Endpoint**: `POST/GET/PUT/DELETE` calls against `http://localhost:8080/index.php/apps/openconnector/api/...`

**Environment variables (in `tests/postman/openconnector.postman_environment.json`)**:

| Variable | Default | Description |
|----------|---------|-------------|
| `baseUrl` | `http://localhost:8080` | Nextcloud base URL |
| `adminUser` | `admin` | Admin username |
| `adminPassword` | `admin` | Admin password |
| `testSourceUuid` | (set by pre-request script) | UUID of source created during the run |

**Enforcement**: `newman run` exits non-zero on any assertion failure → PR blocked

**Performance baseline**: p95 response time captured per request; CI fails if any endpoint
regresses > 50% vs the baseline stored in `tests/postman/baselines.json`

**Auth**: HTTP Basic (`adminUser`:`adminPassword`) on every request

---

### Playwright E2E Gate

**Trigger**: every PR to `development` that touches `src/`, `lib/`, `appinfo/`, or
`tests/e2e/` (path filter excludes `docs/`, `openspec/`)

**Enforcement**: `npx playwright test --project regression` exits non-zero on any test
failure → PR blocked

**On failure**: screenshots and traces uploaded as workflow artifacts; report at
`tests/e2e/playwright-report/`

**Auth**: session state from `global-setup.ts` (admin login persisted to
`tests/e2e/.auth/admin.json`)

---

## Error Codes

These are the HTTP error codes the Newman tests assert against. They are not new — they
reflect the existing API surface from chains B/C.

| Code | Condition |
|------|-----------|
| 200 | Successful read or update |
| 201 | Successful create (some endpoints return 200) |
| 400 | Invalid request body (missing required field, invalid DTO) |
| 401 | No valid session / missing auth |
| 403 | Authenticated but not admin |
| 404 | Resource UUID not found |
| 409 | Conflict (e.g., migration already completed) |
| 500 | Unexpected server error |

## Versioning

The test suite targets the API surface defined in chains B/C (no version suffix on routes).
If a future chain introduces breaking API changes, the Newman collection MUST be updated in
the same PR that introduces the break.

## Breaking Change Policy

A breaking change to any existing endpoint covered by the Newman collection MUST include a
corresponding update to `tests/postman/openconnector.postman_collection.json` in the same
PR. The CI gate will reject PRs where the Newman suite fails without a collection update.

## SLA

These are CI-level expectations, not production SLAs:

- PHPUnit suite: < 2 min wall time (unit tests only, no container)
- Newman suite: < 3 min wall time against a running dev container
- Playwright regression suite: < 10 min wall time with `workers: 4`
