# Design: migration-source-adapters

Kind: code. Size M for integriq's half of a cluster the build plan sizes L.
One contract, one file source, one incumbent adapter, and a read-only pass
the engine's preview consumes. Nothing here writes.

## D1. Reading and writing are different apps, and that is the whole split

The cluster's mechanism line already divides it: "extend openregister import
and export with a preview and a conflict policy; integriq holds the source
adapters". The division is not administrative. An import engine that also
knew how to read Decos and Mozard and a CSV would carry three vendor shapes
in openregister, which is the app every other app depends on.

So the adapter yields and the engine writes. The seam is a record in a
declared shape with its foreign identity attached.

## D2. A supported path is one you can rehearse and undo

Eight driven passers do this, and the one the lane quotes shows what it
means: OpenProject has `post :test` on its import route and ships
`jira_revert_import_job.rb` among twenty import jobs. A migration you cannot
rehearse is a migration you run once, at night, with a database backup and a
phone tree.

Integriq's half of that is the rehearsal: a test run against the real source
that reads, reports and writes nothing. The undo is the engine's, because
only the writer can unwrite.

## D3. The file source is the same contract with a boring reader

C-configuration-16 and C-configuration-88 look like two features and are one.
A delivered CSV is a source with a very simple reader; an incumbent is a
source with a complicated one. Both yield the same shape.

Treating them separately is how a product ends with a CSV importer nobody
maintains beside a migration tool nobody can test. Treating them as one
contract means the file source is the fixture the incumbent adapters are
tested against.

## D4. The mapping is an object, because a migration happens more than once

dossiq's rating is precise about the gap: "partial, OpenRegister import and
export; no stored mapping object". A mapping typed into a wizard and thrown
away means the second delivery of the same file is mapped again, by hand, by
somebody else, differently.

So the mapping is stored, versioned and validated at save. Validation at save
rather than at run is the same argument the intake submission mapping makes:
fail once in front of the person who wrote it.

## D5. Counts before commitment, and `incomplete` is not a small number

A preview that reports 9,000 cases because the source stopped answering at
9,000 is worse than no preview. `synchronization-engine` REQ-009 already
tracks fetch completeness and REQ-010 already refuses a deletion when it is
missing, so the honest reporting exists and is reused.

Integriq reports and draws no conclusion. Whether 14,000 cases and 61,000
documents is acceptable is a decision for the person doing the migration, and
the engine is where the confirmation lives, per
`configuration-export-import` REQ-008.

## D6. The foreign key is the difference between a migration and a copy

Without it, a second run creates fourteen thousand duplicate cases and the
only way back is a restore. With it, the engine can match, and the record
keeps a thread back to the system it came from, which is what a reconciliation
needs a year later.

The shape is not invented here. `registry-backed-field-source` REQ-RFS-003
already defines the provenance a resolved value carries, and C-integrations-11
asks for exactly this in cluster 26. A second shape would mean two ways to
answer the same question.

An adapter that has no stable key for a record kind says so in `describe()`,
before the run. Guessing a key from a name and a date is the shape of
duplicate that is discovered six months later.

## D7. Which incumbent first

The contract is what makes the second adapter cheap, so the first one is
chosen by the first migration rather than by this proposal. The corpus names
the Dutch field a gemeente actually replaces: Decos JOIN, Mozard, xxllnc
Zaken, PinkRoccade iZaaksuite, Rx.Mission, Visma Circle and Atabix, six of
which the sweep could read only as documented because, in D21's words, they
"will never be installed: they are not obtainable".

That is the honest statement of the risk. An adapter for a system nobody can
install is written against a customer's live instance, under a migration
contract, with the rehearsal from D2 as the only safety net. Writing the
contract first is what keeps that work from being a rewrite each time.
