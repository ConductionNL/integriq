# Data gathering

Integriq is the fleet's way to say "fetch this feed on a schedule and keep a register filled with it". It replaces the Specter `sync_*.py` ingestion scripts: instead of a Python job writing into its own database, Integriq writes the rows into an OpenRegister register, where flows, agents, dashboards and reports see them like any other object.

## One connector, four parts

A connector is four kinds of object in the `openconnector` register:

| Part | What it does |
|---|---|
| **Source** | Fetches the feed: location, authentication and headers. |
| **Mapping** (one or more) | Turns each fetched record into the target's shape. An optional second mapping picks the fields that decide whether a record changed. |
| **Synchronization** | Writes each mapped record into a register and schema: `targetType` is `register/schema`, `targetId` is `<register>/<schema>` (for example `spectr/tender`). A record is matched on the synchronization and its id in the feed, so a second run updates what the first one created and never makes a copy. |
| **Job** | Runs the synchronization on Integriq's own cron, every `interval` seconds, through `SynchronizationAction`. |

The target register and schema must already exist; a connector only brings these four objects.

## The connectors Integriq ships

Ten connectors come with the app and are created when it is enabled or upgraded: TenderNed, the Nextcloud App Store, the Digital Public Goods registry, Latvia IUB, Germany GovData, France BOAMP, AusTender, Greece Diavgeia, Sweden Avropa (RSS) and Austria OpenTender (gzip/JSONL). Each one has been run end to end against its live feed.

Their sources are switched on, their jobs are switched off. A fresh install pulls no external feed until an administrator opens **Jobs** and enables the ones they want. You can also run a synchronization once from its page, or with `POST /apps/integriq/api/synchronizations/{id}/run`.

A connector that is fixed after it was shipped gets a higher `version` on the object that changed; the import skips an object whose version is not higher than the one already there.

## Gathering or a leaf: which one you need

Both write to OpenRegister, but they answer different questions.

| | Data gathering | OpenRegister leaf provider |
|---|---|---|
| Question | Keep the whole external dataset in a register, refreshed on a schedule | Show the current state of this one object, when it is opened |
| Runs | On the job's schedule | When an object is read |
| Records | Many per run, stored and deduplicated | One object's live data, not stored as rows |
| Built from | Source, Mapping, Synchronization, Job | An integration or object-source provider in OpenRegister |
| Example | TenderNed's weekly feed into `spectr/tender` | A BRP person lookup for one case |

A leaf provider may use an Integriq source only to make its call: the BRP, KvK and xWiki sources are such sources. A source like that has no synchronization and no job. The rule of thumb: a source with a synchronization and a job gathers; a source without them is a leaf's transport.

## Reacting to gathered data

Gathered rows are ordinary OpenRegister objects, so OpenRegister announces each one it creates or updates. A flow or an agent reacts to that announcement through the flow engine's triggers. Gathering itself uses no part of the flow engine: it runs on Integriq's own cron.

## A feed Integriq cannot read yet

Some feeds need something Integriq does not do yet: CSV files, `.zip` archives with many files, offset or cursor paging past the first page, fetching only what changed since the last run, a second request per record for its details, a wildcard results path, building a list from two fields, pages that need a browser to render, or files too large to hold in memory. A connector for such a feed ships with its source and job switched off, and its `$comment` names what is missing. It is switched on once that capability lands, never earlier, so it cannot sit enabled and quietly gather nothing.

## What stays outside Integriq

- **Analytics and scoring.** `sync_adoption_metrics.py`, the relevance and classification scripts and the `run_sync.py` orchestration compute over data that is already there; they do not fetch a feed. They stay scheduled Python jobs, and Integriq has no connector for them.
- **Feeds no service account can reach.** The Croatian procurement portal behind the national eID login (NIAS) and the legacy APIs that have been switched off cannot be gathered unattended. They are not shipped as connectors, enabled or disabled.
