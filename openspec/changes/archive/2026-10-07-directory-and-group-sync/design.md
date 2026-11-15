# Design: directory-and-group-sync

Kind: code. Size M. A directory is a source, a sync is a synchronisation, and
a membership is a Nextcloud group. Nothing new is invented except the
mapping, the SCIM endpoint and the report of what a leaver still holds.

## D1. Continuous membership, not authentication

The lane's note draws the line and it is the whole capability: "All three
sync membership continuously rather than only authenticating against the
directory." Nextcloud's LDAP backend already authenticates. What it does not
do, for our purposes, is keep `roleType.ncGroupId` filled, so dossiq's
authorisation model rests on groups nothing maintains.

So the unit of work is a membership, not a login. The sync runs on a
schedule, it runs on demand, and a membership absent from the directory is
removed. That last half is the point: a sync that only adds is a sync that
hides a leaver.

## D2. Nextcloud holds the accounts

The build plan gives one sentence to the boundary: "extend integriq's SCIM
and directory sync; Nextcloud holds the accounts". It is worth restating
because the alternative is easy to drift into. Integriq stores no user
record, no password and no group model. It reads a directory and writes
through `IGroupManager`, which is what every consumer already reads.

`UserService` in integriq reads Nextcloud users today and holds none. That
stays true after this change.

## D3. The engine already exists, so the sync is configuration

`synchronization-engine` ships the orchestration, the per-item isolation, the
dead-letter capture, the deletion-ratio guard and the write-nothing test run.
A directory sync that grew its own copy of those would be a second thing to
debug and a second place for a run to disappear.

So a directory connection is a source under `source-management`, with a
mock-mode fixture like the BRP and KvK seeds, and its run is a
synchronisation. The deletion-ratio guard is the one that earns its place
fastest: a directory answering a fraction of its users during an outage is
exactly the shape that empties every group at once.

## D4. The mapping is configuration because organisations differ

One customer has `OU=Vergunningen`; another has a `department` attribute and
one flat group. A mapping in code means a release per customer, which is the
shape C-integrations-28 complains about elsewhere: connectors "built per
customer".

The unknown-target-group setting is deliberate rather than a default. Create
it, or fail naming it. Silently dropping the membership is the third
behaviour and it is the one that produces a permission nobody can explain.

## D5. SCIM is push, the sync is pull, and both write the same thing

They are not alternatives. A customer with Entra or Okta pushes
in-en-uitdienst events the moment they happen; a customer with an LDAP has
nothing to push and is read on a schedule. Both end at the same membership
write, so both inherit the same guard and the same run record.

A SCIM deactivation disables and never deletes. Deleting an account deletes
the trail behind every act it performed, which is the finding an auditor
writes up rather than the one they were looking for.

## D6. What a leaver still holds is asked, never read

C-integrations-34's clause names the real risk: "a leaver with a live case
list is the finding an auditor writes up". Integriq could not answer that by
reading dossiq's data, and should not try: it would be one app reaching into
another's store, which ADR-022 exists to prevent.

So integriq asks. Registered consumers answer a count for an account, and the
run report names each consumer that answered. A consumer that is absent or
silent reads `unknown`, never zero, because a confident zero about an app
that never answered is exactly the instrument that lies.

Integriq reassigns nothing. Reassignment is a decision with a policy behind
it, and the policy belongs to the app that owns the work.

## D7. The identity-provider candidate is recorded and not built

C-integrations-21 is rated `could`, and the lane wrote down why it exists:
"the direction a gemeente wants is the other one, and it is worth a row so
that the distinction is recorded rather than blurred into 12.7". Nextcloud is
already an OAuth provider, which dossiq's own note says. Building a second
issuer inside integriq would put two identity stories in one fleet, against
`digid-eherkenning-auth-adapter`'s rule that integriq is "the only component
in the fleet" holding the government IdP conversation.

Recording it costs a paragraph. Rediscovering it costs a quarter, which is
the reasoning D17 applied to the whole `not` bucket.
