# Design: records-owned-by-an-external-source

Kind: code. One declared policy, two states on an existing record, one refusal.

## D1. Ownership is read from the contract, not stored twice

A `SynchronizationContract` already names the synchronisation, the origin id
and the target id, and app ADR-005 makes it the per-object state record. The
ownership mode therefore sits on the synchronisation and is projected onto the
object through the contract. There is no second place that says who owns a
record, because two places is how they end up disagreeing.

The projected state carries the source, the origin id, the mode and the last
run that saw the record at the source.

## D2. Three policies, and the default is what happens today

`sourceConfig.disappearancePolicy` takes `delete`, `markEnded` or `keepAndFlag`
and defaults to `delete`. An existing synchronisation keeps its current
behaviour, including the ratio guard, until somebody declares something else.
An unknown value is refused at save rather than silently treated as `delete`,
per ADR-102: a policy that cannot be right is a configuration error.

`markEnded` and `keepAndFlag` differ in one thing only. `markEnded` writes an
end date on the record, which is what a party that has left an organisation
needs. `keepAndFlag` leaves the record alone and raises a flag a person has to
resolve, which is what a person who quietly vanished from the BRP needs,
because that is far more often a data problem than a fact.

## D3. The policy runs behind the existing guards, never beside them

REQ-009 and REQ-010 decide whether a disappearance is real. Only when the fetch
was complete and the ratio guard passed does the policy choose what to do about
it. `markEnded` and `keepAndFlag` write no deletion, so they are cheaper than
`delete`, and it is tempting to let them run on an incomplete fetch. We do not:
a truncated page would end-date half a register, and an end date is as wrong as
a delete when the source never said it.

## D4. The lock is a refusal with a name on it, not a hidden field

A delete of a source-owned record is refused with the synchronisation named in
the message, so the person reading it knows where the record comes from and who
to ask. The override is `deleteLocalOverride` carrying a reason, the user and
the timestamp, recorded on the object. A delete that names no reason is refused
the same way an unsigned webhook subscription without a reason is refused under
`signed-outbound-webhooks`: the point of the field is that an auditor reads it a
year later.

A local edit of a property the source owns is not refused. It is overwritten at
the next run, which is the behaviour the hash diff already gives, and the record
says the property is the source's so the person can see why their change did not
survive. Refusing the edit as well would be a second mechanism for the same
fact.

## D5. No migration

Nothing is written on upgrade. A contract gains a last-seen timestamp the first
time its synchronisation runs after the change, and until then the record
reports the last-seen state as unknown rather than inventing one from
`lastSync`. Unknown is honest and a guessed date is not.

## Risks

- **An operator sets `keepAndFlag` and never looks at the flags.** The flag is
  a state on the record, and a register of thousands of flagged records is
  noise. The mitigation is that the flag is queryable and counted on the
  synchronisation, so it is visible as a number before it is a pile.
- **The override becomes routine.** If every delete is overridden, the lock
  bought nothing except a dialog. The reason text is the only defence, and it
  is the same bet `signed-outbound-webhooks` makes.
- **Ownership mode read as permission.** It is not one. It says who maintains
  the record, not who may see it. Access stays OpenRegister's, per ADR-070.
