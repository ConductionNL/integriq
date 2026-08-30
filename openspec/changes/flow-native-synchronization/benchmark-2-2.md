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

1. `occ integriq:synchronization-to-flow b1e14f05…` generates the full
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

---

# UPDATE 2026-08-20 — D1 and D2 are fixed and verified live

Both defects reported above are fixed and measured. **The legacy baseline in this
document is now stale** — see "What this does to the benchmark" below.

## D1 — fixed (#1306)

`isBefore()`/`mappingUnchangedSince()` normalise both sides to a Unix timestamp.
The two unknowns fall in opposite directions on purpose: an absent *mapping*
timestamp is not evidence the mapping changed (must not block a skip), while an
absent `sourceLastChecked` means there is no previous check to be newer than
(must write).

## D2 — took FOUR attempts, and the first three fixed the wrong layer

The defect was never in the writers. Both sites in `synchronizeContract()` read:

```php
if (($synchronizationContract['uuid'] ?? null) === null) {
    $synchronizationContract['uuid'] = (string)Uuid::v4();
}
```

A contract loaded back from OpenRegister carries `id`, **never `uuid`** — so this
minted a fresh identity on every rerun, and every downstream writer then
faithfully upserted on that brand-new uuid and created a row. The writers were
doing exactly what they were told.

| # | what was fixed | measured effect |
|---|---|---|
| #1306 | `persist()` + `persistContract()` | none (+97/run) |
| #1307 | `ensureUuid` ordering | none (+97/run) |
| #1309 | `persistBulk()` (the buffered path) | none (+97/run) |
| #1311 | **`assignContractIdentity()` — the root** | **+0/run** |

**The signal I misread for three attempts was the number not moving.** Each fix
provably landed and was verified in the container, and the count still went
8237 -> 8334 -> 8431 -> 8528, exactly +1 per updated object every time. A fix that
lands and changes nothing means the cause is upstream of everything you are
looking at — not that there is one more writer to find.

The writer fixes are still worth keeping: all four paths now derive identity the
same way, so none can reintroduce the mint alone. They were necessary but not
sufficient; this was sufficient.

## The verification that counts

A flat total is **necessary but not sufficient** — it reads identically whether
the row was updated in place or nothing was written at all. So the check is by
the narrowest identifier: one origin that actually takes the update path.

Probe origin `86bee4b2-25f0-4c2a-a38c-ddad3729b403` had accumulated **9
contracts, one per run**, a clean chronological record of the defect
(02:29:34, 02:29:52, 02:30:20, 02:30:37, 08:12:01, 08:13:06, 09:47:18, 09:56:37,
11:08:02).

After the fix, on the very next run:

- its contract count stayed at **9** — no tenth row, and
- the **oldest** row (`3575d316…`, previously `02:29:34`) was **updated in place
  to `11:59:34`**, `targetHash` still set.

That is the pair that distinguishes "upserts correctly" from "silently stopped
writing". It updates `matches[0]`, which is what `findContractBySyncAndOrigin()`
returns.

Five consecutive runs, contract delta **0** every time:

| run | found | created | updated | skipped | contract delta |
|-----|-------|---------|---------|---------|----------------|
| 1 | 2000 | 0 | 97 | 1903 | **0** |
| 2 | 2000 | 0 | 0 | **2000** | **0** |
| 3 | 2000 | 0 | 0 | 2000 | **0** |
| 4 | 2000 | 0 | 0 | 2000 | **0** |
| 5 | 2000 | 0 | 0 | 2000 | **0** |

The 97 that used to update forever were only doing so because their contract was
re-created every run, so the hash comparison could never hold for them. With the
contract persisted correctly the whole corpus now skips: **2000/2000, nothing
written.** That is full idempotency for a mapped synchronization, which is what
task 2.3 set out to establish and could not while D1 and D2 stood.

## What this does to the benchmark — NUMBERS ABOVE ARE STALE

The mapped legacy steady state measured above (17.7 s, 2000 updated, 0 skipped)
described a run doing 2000 pointless writes. It now skips 2000/2000, so that
figure no longer describes the engine and **must not be used as the bar the
decomposed flow has to beat.**

The new figure is NOT recorded here, deliberately. The five runs above were taken
at **loadavg 15.98 with the nextcloud container at 88.7% CPU** — another session
was using the box. The original baseline was taken at loadavg 0.36. Timings from
the two are not comparable, and the wall clocks observed (30-77 s) are dominated
by contention, not by the engine.

**To close 2.2 properly, re-measure all three figures on a quiet box** (unmapped
legacy, mapped legacy, decomposed flow), confirming `docker stats` and
`/proc/loadavg` first. Only the correctness result above — a count, not a timing —
is safe to carry forward from this session.

---

# MEASURED 2026-08-21 — the decomposed flow MEETS AND BEATS legacy

All three figures re-taken back to back on a quiet box, after D1 and D2 were
fixed. **These supersede every wall-clock number above.**

## Quiet gate

The box was polled every 20 minutes for ~4 hours before it qualified. The gate:
1-min loadavg < 2.0 **and** nextcloud container CPU < 5%, on **three** samples
~20 s apart. Ticks that failed included loadavg 24.91 and nextcloud at 282%. One
tick had two of three samples pass (loadavg 1.85, nextcloud 0.00%) — sampling
once would have read as a clean pass and produced numbers worth discarding.

Qualifying reading: loadavg **1.95 / 1.86 / 1.59**, nextcloud
**0.00% / 0.00% / 1.80%**.

## Results

| configuration | steady state | per-run | objects |
|---|---|---|---|
| legacy, unmapped | **7.84 s** | 7.71 / 7.97 | 2000 found, 2000 skipped |
| legacy, **mapped** | **23.87 s** | 22.38 / 25.08 / 24.15 | 2000 found, 2000 skipped |
| **decomposed flow** | **20.38 s** | 21.56 / 19.20 | 2000 through every step |

Legacy unmapped run 1 (10.54 s, 33 created / 167 updated) is excluded as cold —
the source drifted overnight. Contract delta was **0 on all three** mapped runs.

### Verdict — 2.2 is MET

The fair comparison is **mapped**: the flow is generated *from* the mapped
synchronization and performs the same mapping. Against that bar:

- **20.38 s vs 23.87 s — the decomposed flow is 14.6% FASTER.**
- Including the caveated third run (below): 21.03 s, **11.9% faster**.

It does not beat the *unmapped* legacy figure (7.84 s), but that is not the
comparison — an unmapped sync does no mapping at all.

### The third flow run is caveated, and does not change the verdict

External load returned as the third flow run was taken (nextcloud 19.1%, then
13–116% with nothing of ours running). Eight of the nine runs carry a
nextcloud-idle reading taken immediately after them; the third flow run does not.

The protocol written for this measurement says to discard the whole set when the
box gets busy mid-measurement. That is departed from here **deliberately and
visibly**: the evidence is per-run rather than set-level, and the verdict holds
on the clean runs alone (20.38 s) as well as with the suspect one included
(21.03 s). Re-taking a third clean flow run would tighten the figure; it cannot
change the direction.

## Where the time actually goes — mapping dominates BOTH engines

Decomposed flow, per-step (ms), from the flow-run record rather than the enqueue
wall clock (enqueue returns in under a second and measures nothing):

| step | run 1 | run 2 | run 3 |
|---|---|---|---|
| fetch (20 CKAN pages) | 3520 | 4274 | 4591 |
| **map** | **13657** | **10791** | **12214** |
| contract | 4307 | 4049 | 5393 |
| write | 11 | 11 | 18 |
| sweep | 11 | 12 | 12 |

`map` is ~60% of the flow's total. `write` at 11–18 ms confirms `skipWhen` is
skipping all 2000 — the decomposition itself is nearly free.

The legacy engine shows the same shape from the other side: **mapped 23.87 s
against unmapped 7.84 s — a 16 s penalty for a run that skips all 2000 objects
and writes nothing.** The mapping is *resolved per item* before the skip decision
is taken, so a skipped item still pays for it. That is ~2000 avoidable lookups
per run and is the single largest remaining cost in the legacy path.

**The optimisation worth having is in the mapping, not in the decomposition.**
That conclusion now holds for both engines and is the useful output of this task.

## What this does NOT settle

3.4 (page removal) stays gated on a human decision. 2.2 asked whether the
decomposed flow can meet or beat `synchronization-run`; it can, on this corpus,
on a quiet box. It does not follow that the legacy pages should be removed —
that is a product call, and the deprecation path, migration coverage and the
528,656 pre-existing duplicate contract rows are separate questions.

---

# CORRECTION 2026-08-21 — "14.6% faster" was noise. It is a TIE.

The verdict recorded immediately above is **wrong on the margin** and is
corrected here. 2.2 is still MET, but the flow does not beat legacy — it
**matches** it.

## What happened

The box qualified again, and far more quietly than for the previous set:

| | loadavg samples | nextcloud samples |
|---|---|---|
| previous set | 1.95 / 1.86 / 1.59 | 0.00% / 0.00% / 1.80% |
| **this set** | **0.44 / 0.72 / 0.51** | **0.01% / 0.00% / 0.00%** |

That is essentially the original 0.36 baseline. All three figures were re-taken,
and the third flow run — the one previously caveated — was taken clean.

## Corrected results

| configuration | mean | runs | spread |
|---|---|---|---|
| legacy, unmapped | **7.48 s** | 6.98 / 7.32 / 8.15 | 1.17 |
| legacy, **mapped** | **18.91 s** | 17.93 / 19.24 / 19.56 | 1.63 |
| **decomposed flow** | **18.90 s** | 16.42 / 20.82 / 19.45 | 4.40 |

Contract delta **0** on all three mapped runs, again.

### Verdict — 2.2 is MET on parity, NOT on a margin

**18.90 s against 18.91 s: a difference of 0.006 s, which is 688x smaller than
the flow's own run-to-run spread of 4.40 s.** These are indistinguishable. The
honest statement is that the decomposed flow *matches* `synchronization-run`;
2.2 asked for "meet or beat" and meeting is satisfied.

The previously recorded **14.6% faster** came from comparing a 20.38 s flow
figure against a 23.87 s legacy figure, both taken at loadavg 1.6-2.5. Both were
inflated by contention, and unequally so. **The margin was an artefact of the
noise floor, not a property of the engines.**

## The lesson, which is the same one twice

A quiet-enough gate is not a quiet gate. The previous set passed a bar of
loadavg < 2.0 and was still contended enough to manufacture a 14.6% difference
out of two engines that are actually tied. **When the difference between two
measurements is smaller than the spread within either one, there is no
difference — report the tie.** The spread column is not decoration; it is the
thing that decides whether a margin exists at all.

## What does NOT change

Everything that rests on counts rather than wall clocks stands unchanged:

- contract delta **0** — D1/D2 remain fixed
- `write` at **3-11 ms** — `skipWhen` still skips all 2000; the decomposition is
  still nearly free
- **mapping still dominates both engines**: `map` is 8.9-9.7 s of the flow's
  ~19 s, and legacy still pays **11.4 s** more mapped than unmapped
  (18.91 vs 7.48) on a run that skips all 2000 objects and writes nothing,
  because the mapping is resolved per item before the skip decision

That last point is the actionable output of this task and it is now measured on
a genuinely quiet box, on both engines, twice.

## Operational note for anyone re-running this

`FlowRunWorker` declares `setInterval(seconds: 60)` — a floor, not a schedule.
Driving `cron.php` repeatedly inside 60 s does nothing: the worker is skipped and
the run stays `queued`, which looks exactly like a stalled engine. Space cron
ticks more than 60 s apart, or you will conclude the flow is broken when it is
merely not yet eligible.
