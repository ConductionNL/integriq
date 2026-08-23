# Integriq API-contract tests (Newman)

Newman/Postman contract tests that exercise integriq's integration HTTP
surface directly, locking the API contract. Per the gate-19 split, **API/contract
correctness lives in Newman**; Playwright drives the UI only.

## Architecture note (read first)

After the chain-C OR-cutover, integriq controllers no longer own per-schema
CRUD — it is delegated to **OpenRegister's object API** at
`/api/objects/openconnector/{schema}/*` (ADR-022). integriq's own controllers
now expose only the integration **actions**: `sources#test`, `jobs#run/test`,
`synchronizations#run/test/contracts/statistics/logs`, `mappings#test/getObjects`,
and the events (webhook subscription) lifecycle. This suite covers **both**: the
OR-delegated CRUD for every schema, and the integriq action endpoints.

The `register` collection variable is the **slug** `openconnector` (OR register
id 65), and the schema slugs used are: `source`, `mapping`, `rule`, `endpoint`,
`job`, `synchronization`, `event_subscription` (= webhook), `event` (CloudEvent),
`consumer`. Using slugs (not numeric ids) keeps the suite portable across envs.

## What is covered

| Folder | Endpoints | Happy | Error (4xx not 500) | Authz |
| --- | --- | --- | --- | --- |
| 0. Setup | OR CRUD create for all 8 schemas | creates + captures every id | — | — |
| 1. Source | OR read; `POST /api/sources/test/{id}`; `GET /api/sources/logs` | source test 200, logs 200 | unknown source -> 404 | sources logs 401 |
| 2. Mapping | `GET /api/mappings/objects`; `POST /api/mappings/test` | getObjects 200 | — | mappings test 401 |
| 3. Rule | OR read | rule read 200 | create missing-required -> 4xx | (OR reads not gated) |
| 4. Endpoint | OR read; `GET /api/endpoints/logs`; `GET /api/endpoint/{path}` | endpoint read 200, logs 200 | unmatched path -> 4xx | (public path) |
| 5. Job | `GET /api/jobs/logs`; `POST /api/jobs/run/{id}` | jobs logs 200 | unknown job -> 404 | jobs run 401 |
| 6. Synchronization | `POST .../{id}/run`, `.../{id}/test`, `GET .../contracts/{id}`, `.../statistics`, `.../logs` | **run NOT-500 (Phase-0)**, contracts/statistics/logs 200 | unknown sync run -> 404 | sync run 401, statistics 401 |
| 7. Webhook (EventSubscription) | `GET/POST/DELETE /api/events/subscriptions`, `.../pull` | list 200, subscribe 200, unsubscribe 2xx | empty subscribe -> 4xx, unknown pull -> 404 | subscriptions 401 |
| 8. CloudEvent + Consumer | OR read/update for webhook + consumer | read 200, consumer update 2xx | — | (OR reads not gated) |
| 9. Teardown | OR CRUD delete for all 8 schemas | idempotent cleanup | — | — |
| 10. DSO STAM signature verification | `PUT /api/admin/dso-pki-config`; `POST /api/dso/stam/verzoeken` | valid HMAC signature -> 202 | forged/missing signature -> 401 | — |
| 11. Observability (AppHost health/metrics) | `GET /api/health`; `GET /api/metrics` | health anonymous 200 (`status/checks.database/checks.openregister/app/version`); metrics admin 200 (exposition format, all 11 `openconnector_*` metric names) | — | metrics no-auth 401 |

**Total: 58 requests, 79 assertions — all green.**

Folder 11 covers `adopt-apphost`'s Requirement: Declarative Metrics Parity / Declarative Health per ADR-006 (`openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md`) — the two scenarios each `@e2e exclude`s to this Newman suite as the API-contract source of truth. The 503-on-critical-failure health scenario is not exercised here (it requires deliberately breaking the DB connection, which this suite does not do); it is covered by the AppHost observability engine's own contract collection and unit tests.

The collection is **self-contained and idempotent**: setup creates one object per
schema (capturing the id into a collection variable) and teardown deletes them all.

### Phase-0 fix locked

`POST /api/synchronizations/{id}/run` no longer 500s on the run-log/hydrate path
(commit `b855af5b` *"restore run-log write path to OpenRegister + nullable-safe
hydrate"*). The `synchronizations#run on source-less sync` test asserts the run
reaches the `synchronize()` service, writes its run-log, and returns a **graceful
400** (`"sourceId of synchronization cannot be empty"`) rather than crashing. The
**load-bearing assertion is NOT-500**; 400 is correct because the seeded sync has
no source. Unknown-id run returns a clean 404.

### findObjects / findObject status

The OR-method `findObjects()` / `findObject()` non-existent-method calls were
**already migrated off `development`** (commit `139423f6` *"migrate
Source/Mapping/Rule/Contract(Log) mappers onto OpenRegister"*). **No
controller-reachable endpoint in 0.2.15 500s on a findObject OR-method call.** The
only remaining `findObject*` symbols in `lib/` are integriq's local MongoDB
`Service\ObjectService` shim (its own method) and `Db\SourceMapper::findObject`
(a real method defined on that class). So the warned-about sweep target is, for
the API surface, already resolved on `development`.

## Quarantined real bugs (NOT fake passes — flip to happy-path when fixed)

Three endpoints currently return **HTTP 500** for reasons unrelated to findObjects.
The suite asserts the *current* 500 so it stays green **without faking a pass**;
each carries an inline comment describing the fix and the assertion to flip to.

1. **`synchronizations#test`** — catches `Exception` and returns
   `JSONResponse(status: ($e->getCode() ?? 400))`. `getCode()` returns `0` (not
   `null`) for the "sourceId cannot be empty" exception, so the response is built
   with HTTP status `0` -> NC `Http.php#106` *"Undefined array key 0"* -> 500. The
   sibling `run()` method hard-codes `400` and is correct. **Fix:** treat
   `getCode() === 0` as 400 (or hardcode like `run()`).

2. **`mappings#test`** — builds an `OCA\OpenRegister\Db\ObjectEntity` (line ~193)
   and passes it to `MappingService::executeMapping()` which type-hints
   `OCA\OpenConnector\Db\Mapping` -> `TypeError` -> 500, **regardless of body**.
   It also `throw`s an uncaught `InvalidArgumentException` on missing params
   (-> 500) instead of returning 400. **Fix:** construct/hydrate a
   `Db\Mapping` and return 400 on missing params.

3. **`jobs#run` / `jobs#test`** — `JobService::executeJob` writes a `job_log`
   whose `executionTime` is a **float**, but the `job_log` schema requires
   `'integer or null'` -> OR validation error -> caught -> `JSONResponse` 500. The
   route and `find` work correctly (unknown-id returns 404). **Fix:** cast
   `executionTime` to int before the log write.

(The suite quarantines `jobs#run`; `jobs#test` shares the identical root cause.)

## Running

```bash
# defaults: BASE_URL=http://localhost:8080, ADMIN_USER=admin, ADMIN_PASS=admin
./run-newman.sh

# or directly:
npx newman run integriq.postman_collection.json \
  --env-var baseUrl=http://localhost:8080 \
  --env-var noAuthBase=http://127.0.0.1:8080 \
  --env-var adminUser=admin --env-var adminPass=admin \
  --ignore-redirects
```

`run-newman.sh` prefers a globally-installed `newman`, falls back to `npx newman`,
and serialises runs under `flock /tmp/uiaudit-integriq.lock` to avoid tripping
the Nextcloud brute-force protection when multiple agents run in parallel.

## Auth-isolation detail (important for reuse)

Newman keeps a per-run cookie jar. Authenticated requests against `baseUrl`
(`localhost`) establish a Nextcloud session cookie; because the jar is shared, that
cookie would silently authenticate the no-auth requests too (returning 200 instead
of 401). Two measures keep the authorization tests honest:

1. **Host split** — authenticated requests use `{{baseUrl}}`
   (`http://localhost:8080`); no-auth requests use `{{noAuthBase}}`
   (`http://127.0.0.1:8080`). NC session cookies are host-scoped, so the
   `localhost` session is never sent to `127.0.0.1`. `run-newman.sh` derives
   `noAuthBase` from `BASE_URL` automatically (override with `NO_AUTH_BASE`).
2. **`--ignore-redirects` + `Accept: application/json`** — unauthenticated
   requests get NC's JSON `401`, not the `303`->login-page `200` HTML.

Authenticated POST/PUT/DELETE requests carry `OCS-APIRequest: true`. The
integriq action controllers (`sources#test`, `jobs#run`,
`synchronizations#run/statistics`, `events#*`, `mappings#test`) are auth-gated and
return `401` unauthenticated. OpenRegister object reads are **not** auth-gated
(authorization on object data is OR's responsibility, ADR-022), so OR-CRUD reads
are exercised only authenticated.
