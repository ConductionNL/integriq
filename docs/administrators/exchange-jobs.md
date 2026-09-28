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

## Give people access

Three actions control the exchange screens. All three are for administrators only until you
change them in Admin settings > Integriq > Action authorization.

| Action | Lets people |
|---|---|
| `exchange.read` | see exchange jobs and rejections, for example in learniq's status panel |
| `exchange.resubmit` | resubmit a rejected record |
| `exchange.waive` | waive a rejected record with a reason |

To let learniq's coordinators see the status panel, add their group to `exchange.read`.

## For developers

The interface, including the events an app raises and answers and the read endpoints under
`/api/exchange/`, is in
`openspec/changes/learniq-exchange-jobs-native/contract.md`.
