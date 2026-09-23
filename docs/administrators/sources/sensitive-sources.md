# Sensitive sources: who may query a connector

Most sources answer questions nobody minds being asked. A lifecycle API, a
postcode lookup, a company register — the answer is public, and the only
reason to restrict the connector is cost or rate limits.

Some sources are not like that. A query against the Basisregistratie Personen
(BRP) returns a person and their **burgerservicenummer**. A query against a
payroll or case system returns someone's circumstances. For these, *who may ask
the question* is itself the control, because there is no such thing as a
harmless result.

This page is the convention for those.

## The preference: a named group, not "everyone" and not "admin"

For a source that returns personal data, **create a user group for the people
responsible for that connector and grant access to that group** — not to all
authenticated users, and not by leaving it to administrators.

The reasoning is the same one that governs the register itself:

- **Not everyone.** A signed-in account is not a reason to see a BSN. The
  applicant filling in a form needs an address resolved, not the population
  register.
- **Not administrators, by default.** "Admin" is an operational role — someone
  who can restart a job, read a log, fix a failing sync. It is not the same
  population as "people entitled to look up a citizen", and on most instances it
  is a larger one. Granting by administrator status means access follows
  whoever holds the keys to the server rather than whoever carries the legal
  basis for the query.
- **A named group, because the burden is named.** Someone maintains the RvIG
  connection: the credentials, the certificate, the renewal, the questions when
  it breaks. That is the group that should be able to use it, and naming it
  makes both the access and the responsibility auditable.

A good group name says what it is for — `brp-beheer`, not `power-users`.

## How this is expressed

OpenRegister resolves authorization as a cascade: **register → schema →
object**. A `source` object can therefore carry its own `authorization` block
that overrides the `source` schema's baseline for that one connector, so a
sensitive source is restricted without restricting every source.

```json
{
  "authorization": {
    "read":    ["brp-beheer"],
    "create":  [],
    "update":  [],
    "delete":  [],
    "destroy": []
  }
}
```

### The entry forms that are actually recognised

A rule list holds *entries*, and `PermissionHandler::hasGroupPermission()` recognises
exactly these:

| Entry | Meaning |
|---|---|
| `"brp-beheer"` | A bare group id. **This is the form every block in this repo uses.** |
| `{"group": "brp-beheer"}` | The same thing, object form. Note **`group`, singular**. |
| `{"group": "…", "match": {…}}` | Conditional — granted only when the object matches. |

Anything else falls through every branch and the loop ends in `return false`, so the
entry grants nothing and the action is denied to everyone but the two bypasses below.

⚠️ **`{"groups": [...]}` — plural — is not a form.** It looks like the others and reads
naturally, which is exactly why it is worth naming: `grep -c "'groups'"` on
`PermissionHandler` returns **0**. An earlier version of this page used it in the
example above, so a reader following it would have written a block that denied
everything while appearing to delegate to a group.

### Read this part before writing one

Two shapes look similar and mean opposite things:

| Written | Means |
|---|---|
| `"authorization": {}` | An empty **block**. Closes **nothing** — it takes the same default-open branch as no block at all. |
| `"authorization": {"read": []}` | A non-empty block with an empty **rule list**. Reads as *"grant to nobody"*, so it denies. |

Silence is not deny. `read` is absent from OpenRegister's
`DEFAULT_CLOSED_WRITE_ACTIONS`, so a schema or object with no authorization
block grants reads to every authenticated account, and the
`enforce_default_closed` flag does not change that.

Declare **every** action you mean to close. An action left out of the block
inherits the baseline from the schema or register rather than denying.

> **Note.** `ObjectEntity`'s docblock currently states that an empty array means
> "all users have permission". That is the opposite of what
> `PermissionHandler` does. Trust the table above; the docblock is being
> corrected upstream.

## Two bypasses remain, and they are deliberate

Even with the block above, two callers still pass:

- **The `admin` group**, as operational break-glass.
- **The owner** of an individual object.

So this convention restricts a source to its maintainer group *plus*
administrators. If you need a source that administrators cannot query either —
a reasonable thing to want for BRP — that is not expressible today, at either
layer, and needs a change in OpenRegister rather than configuration here.

## A source is only as closed as the code path that reads it

Configuration alone is not enough. If the code that resolves the source reads
it in system context — `_rbac: false` — then the object's authorization is
never consulted and the block above has no effect.

Before relying on a block, check that the read path honours RBAC. A control
that is configured but not consulted is worse than none, because it reads as
protection in a review.

## Where this is decided, not just enforced

Restricting a connector is a statement about who is entitled to ask a question,
so record it where the connector is defined — in the source fragment's
`$comment`, naming the group and why. The next person to widen it should have
to disagree with a stated reason rather than fill in a blank.
