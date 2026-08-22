# Migration coverage — what fraction of real synchronizations the generator accepts

Task 3.4 (removing the legacy pages) was gated on a number nobody had: not
whether the decomposed flow is fast enough — task 2.2 settled that — but whether
the generator can migrate the synchronizations that actually exist.

Measured by running `occ openconnector:synchronization-to-flow` against every
synchronization on the dev instance. The command WRITES NOTHING; it renders the
document or names the reasons it cannot.

## Result

| | accepted | of | coverage |
|---|---|---|---|
| before `payloadFrom` | 20 | 119 cleanly judged | **16.8%** |
| after (openregister #2684 + openconnector #1334) | **161** | **240** | **67.1%** |

`sourceTargetMapping is not set` went from **98 refusals to 0**.

## The remaining 79 refusals are almost all ONE thing

| count | refusal |
|---|---|
| **74** | **`actions`** — the synchronization declares rules and no generated step evaluates them |
| 4 | `targetId` is not a `register/schema` pair |
| 2 | `sourceType ""` |
| 2 | `targetType ""` |
| 1 | `sourceType "file"` |
| 1 | `targetType "api"` |
| 1 | `conditions` |
| 1 | `sourceHashMapping` |

**94% of what is left is `actions`.** The shape repeats: one dominant blocker,
then a scatter of single digits. Whoever picks this up next should measure
whether rule evaluation can be expressed as flow steps before assuming the rest
matters.

## I PROJECTED ~99% AND IT WAS 67.1%

The projection came from the earlier sweep, where 98 of 99 refusals were the
missing mapping. Removing that one cause therefore looked like it would clear
nearly everything.

It did clear that cause completely — 98 to 0 — and the projection was still
wrong by thirty points, because **the 119 synchronizations that sweep judged
were not a representative sample of the 240.** It aborted partway when the
instance degraded, and the `actions`-heavy synchronizations were concentrated in
the part it never reached. A partial measurement is not a proportional one.

That is the third time this programme has been misled by a measurement that
looked complete: the 30-77 s timings taken on a loaded box, the "14.6% faster"
that was noise, and now this. The common shape is a number produced under
conditions that were not what they appeared to be.

## Harness notes for whoever re-runs this

- **A run that did not happen looks exactly like a refusal.** The first attempt
  reported 8.3% coverage; an unknown number of those had never executed the
  command at all — the instance had dropped into "requires upgrade" and returned
  "There are no commands defined in the openconnector namespace". The harness
  now separates ACCEPTED / REFUSED / did-not-run and aborts rather than counting
  a non-run as a verdict.
- **The instance degrades under a shared box.** It went into maintenance mode or
  needed a DB upgrade repeatedly mid-sweep, because other work was changing app
  versions on the bind mounts. The sweep is resumable: it skips ids already
  judged, so it can be run in cycles.
- **Do not clear maintenance mode without checking for a running `occ upgrade`**
  (`ps -eo args | grep '[o]cc upgrade'`). Twice during this measurement another
  session was mid-upgrade; forcing maintenance off then would have interrupted
  it.
- **Four refusals parsed as UNKNOWN** because the console wrapped their message
  across lines — their synchronization names are long. They were re-read
  individually and are all `actions`. A reason-extractor that assumes one line
  per bullet undercounts.

## What this does NOT settle

3.4 stays gated on a human decision. Two thirds coverage is not "the pages can
go": a third of synchronizations still cannot migrate, and 74 of them for a
single reason that has not been designed for yet.
