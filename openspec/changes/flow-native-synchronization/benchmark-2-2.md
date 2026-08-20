# Task 2.2 — benchmark: decomposed flow vs `synchronization-run`

Status: **legacy baseline measured; the decomposed side is BLOCKED on this box.**
Measured 2026-08-20 against the shared dev instance (`localhost:8080`).

This is the task that gates 3.4 (page removal). It is **not** complete: a
"meet or beat" verdict needs both numbers, and only one of them exists.

## What was measured

Fixture `93de5cdb-2f40-45eb-9a8e-e736f9cd0767` — "ckanflowa3hp — CKAN datasets",
`data.overheid.nl` CKAN `package_search`, 100 rows/page, `maxPages: 20` = the
2000-dataset run the task names.

The box was quiet before and during (`nextcloud` container at 0.00% CPU,
loadavg 0.36; `status.php` 200 in 0.15 s), which matters — timings taken on a
loaded shared box are not comparable to anything.

### Legacy engine, unmapped fixture — steady state

| run | wall | found | created | updated | skipped |
|-----|------|-------|---------|---------|---------|
| 1 (cold, stale contracts from 2026-08-14) | 9.65 s | 2000 | 1854 | 146 | 0 |
| 2 | 6.42 s | 2000 | 0 | 146 | 1854 |
| 3 | 6.58 s | 2000 | 0 | 146 | 1854 |
| 4 | 7.29 s | 2000 | 0 | 146 | 1854 |

Steady state ≈ **6.8 s** for 2000 found / 1854 skipped / 146 updated.

`deletionGuard` reported `guarded: true, reason: fetch_incomplete` on **every**
run — the deletion GC never ran. That is REQ-009/REQ-010 behaving correctly, and
it matters for the comparison: the legacy runs did no sweeping, so a decomposed
flow compared against them must not sweep either.

### Legacy engine, same source WITH a 1:1 mapping — steady state

The generator refuses a synchronization with no `sourceTargetMapping` (it cannot
enumerate the written properties), so the comparison needs a mapped fixture.
Clone `b1e14f05-39c1-4a87-ad5e-f44dd5121bad` is the same source and target plus a
1:1 mapping of the four properties the target schema declares.

| run | wall | found | created | updated | skipped |
|-----|------|-------|---------|---------|---------|
| 1 (cold) | 15.93 s | 2000 | 2000 | 0 | 0 |
| 2 | 17.42 s | 2000 | 0 | 2000 | 0 |
| 3 | 17.88 s | 2000 | 0 | 2000 | 0 |
| 4 | 17.69 s | 2000 | 0 | 2000 | 0 |

**Adding a mapping made the steady-state run 2.6x slower (6.8 s → 17.7 s) and
took `skipped` from 1854 to 0.** That is not a property of the data — see the two
defects below.

## The decomposed side is blocked, and by what exactly

1. `occ openconnector:synchronization-to-flow b1e14f05…` generates the full
   11-node chain (`trigger → fetch → explode → map → contract → target-uuid →
   write → synced-id → commit → sweep → end`).
2. `POST /api/flow/validate` **refuses it** on this instance:
   > `openregister.object-write` (step `write`) — config key(s) `skipWhen`,
   > which that node never reads.

   That refusal is correct and is the preflight doing its job. The cause is
   deployment, not the flow: `skipWhen` landed on openregister `development`
   (`a1006a8`, 8 occurrences in `ObjectWriteNode`), but the instance is serving
   the checkout at branch `chore/dedupe-ratelimit-prose` (`3bc76ca`), which does
   **not** contain `development`. That checkout belongs to another session and
   was deliberately left alone.
3. Dropping `skipWhen` and the `sweep` node (the legacy runs never swept either)
   produces a flow that validates clean — `valid: true`, 0 blocking, 0 warnings —
   and saves. But **every run of it stalls**: `queued → running` within 2 s, then
   `marking: []`, `log: []`, no error, `updated` frozen. Cron was driven in the
   foreground for 65 s; the run never advanced and nothing flow-related reached
   `nextcloud.log`. `FlowRunWorker` *is* registered on the instance.

   The engine that stalls is the same one that rejected `skipWhen` — i.e. one
   that predates this programme's openregister work. This is consistent with, but
   does not by itself prove, "the deployed engine is too old to run a generated
   flow".

**To finish 2.2** the box needs an openregister that includes `development`
(≥ `a1006a8`). Then: regenerate, validate, run 3x, compare against 17.7 s.

## Two defects found in the LEGACY engine while establishing the baseline

Both are measured, both are pre-existing, and neither is caused by this change.
They are reported here because they make the legacy baseline itself pathological
— the number the decomposed flow has to "meet or beat" is inflated by them.

### D1 — a synchronization WITH a mapping can never skip

Steady state with a mapping is `skipped: 0, updated: 2000`, every run, forever.
Without the mapping, the identical source skips 1854/2000.

The skip in `SynchronizationService` requires, among other conditions:

```php
$sourceTargetMapping->getUpdated() < ($synchronizationContract['sourceLastChecked'] ?? null)
```

`getUpdated()` is a DateTime; `sourceLastChecked` is an ATOM **string**. The data
satisfies the intent — mapping `updated` `02:29:03`, contract `sourceLastChecked`
`02:29:31`, 28 s later — yet the branch is never taken.

### D2 — an update writes a NEW contract instead of updating the existing one

For one `originId` on one synchronization:

| contract uuid | sourceLastChecked |
|---|---|
| `2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33` | 02:29:31 |
| `318e0979-eafe-4d2b-bc86-be50a4aa8c6f` | 02:29:47 |
| `4c9b07dd-5815-47bd-be5b-0ef9c66f9d76` | 02:30:16 |
| `6c6b6144-31c4-45fe-a5a5-2018ccfe06d1` | 02:30:33 |

**Four distinct contract objects** — four distinct uuids, not four versions of
one — for the same `(synchronizationId, originId)`, one per run, all carrying the
**identical** `originHash` (`4da6d1cb78a5…`). Identical hashes prove the source
did not change, so this is contract identity, not real change.

It follows the update path only: the unmapped fixture, which skips 1854 and
updates 146, grew by exactly 146 contracts per run (2000 + 1854 + 4x146 = 4438,
the observed count).

Consequence: `synchronization_contract` grows without bound. This instance holds
**528,656** contract rows. D1 and D2 compound — D1 sends every object of a mapped
sync down the update path, and D2 mints a fresh contract for each.

Fixing either changes engine behaviour on a table with half a million rows, so
neither was changed here.

## Reproduction

Fixtures left in place (not deleted — they are the benchmark):

- mapping `99390e2e-5796-4f57-b406-8eb73bbedc0e`
- synchronization clone `b1e14f05-39c1-4a87-ad5e-f44dd5121bad`
- flow `1c894b1e-ac5a-4b2f-88f7-d00c5363ca1f` (the no-`skipWhen`, no-`sweep` variant)
