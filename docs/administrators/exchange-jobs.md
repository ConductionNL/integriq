# Exchange jobs of other apps

Integriq runs the data exchanges of other apps. Learniq is the first: its reports to DUO ROD
and the Verzuimloket, its OSO transfer files, its UWLR, Edu-V and Basispoort exports and its
hand-offs to a samenwerkingsverband all run here.

## What you see

An exchange job is an ordinary job in the jobs list. It carries a target (such as `bron-rod`),
a direction, the app that owns it and a status. It runs once, on the next scheduler pass.

Each record the target rejects becomes a dead letter. You can resubmit it once the record is
corrected, or waive it with a reason.

## Who decides whether a job runs

The app that owns the job decides. Right before a job runs, integriq asks that app. Learniq
checks things like the parent's review of an OSO file, the partner approval and the teldatum
check. It answers allow, with the records that may leave, or refuse, with a reason.

Integriq refuses the job itself when the owning app is not installed, does not answer or fails.
Nothing is sent in any of those cases.

Integriq never stores the records it sends. They go straight from the owning app to the
adapter.

## Imports

Three imports come back to the owning app: LVS results, OSO dossiers and migration files.
Integriq translates the received records and hands them to the app. The app answers how many it
took and which it rejected, and why. Each rejected record becomes a dead letter.

If the app does not answer, the job fails with `no-owner-answer`. Update the app, then request
the import again.

## Give people access

Three actions control the exchange screens. Change them in Admin settings > Integriq > Action
authorization.

| Action | Lets people | Default groups |
|---|---|---|
| `exchange.read` | see exchange jobs and rejections, for example in learniq's status panel | `admin`, `coordinators`, `compliance-officers` |
| `exchange.resubmit` | resubmit a rejected record | `admin` |
| `exchange.waive` | waive a rejected record with a reason | `admin` |

Learniq's coordinators and compliance officers read the status panel by default.

An upgrade gives `exchange.read` these three groups only when you never changed it. If it still
held just `admin`, it now holds all three. Any other value you set stays as it is. To keep the
panel for administrators only, set `exchange.read` back to `admin` after the upgrade.

## For developers

The interface, including the events an app raises and answers and the read endpoints under
`/api/exchange/`, is in
`openspec/changes/archive/2026-09-29-learniq-exchange-jobs-native/contract.md`.
The import hand-off event, `ExchangeRecordsReceivedEvent`, is described in
`openspec/changes/archive/2026-09-29-exchange-import-landing/design.md`.
