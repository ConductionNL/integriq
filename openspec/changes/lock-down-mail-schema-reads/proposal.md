---
kind: config
---

# Proposal: lock-down-mail-schema-reads

## Summary

Nine schemas added by the current release carry no `authorization` block, which in
OpenRegister means reads are granted to every authenticated account rather than
denied. For `mail_message` that is the body, subject, sender and recipients of
every intercepted message. This change adds one `register.d` lockdown fragment
closing all nine, as the agreed interim for this beta.

## Motivation

Blocker 3 of the #1983 security review (review `5260087915`). Ten schemas were
found with no `authorization` block; `sender_identity` was closed by
`enrol-sender-identity-in-credential-broker` because it also held a secret. These
are the remaining nine.

The reason an absent block is not safe is specific and worth stating, because the
obvious reading is the opposite. `PermissionHandler::hasGroupPermission()` treats an
absent **or empty** block as default-OPEN, and `enforce_default_closed` cannot close
it for reads because `read` is not in `DEFAULT_CLOSED_WRITE_ACTIONS`
(`create`/`update`/`delete`/`destroy`). There is no instance configuration that
makes these schemas private. It has to be done per schema.

**The shape matters, and the wrong one looks identical to the right one:**

| Written | Effect |
|---|---|
| `"authorization": {}` | **wide open** — `empty($authorization)` takes the default-OPEN branch |
| `"authorization": {"create": [], "read": [], …}` | **closed** — `empty($auth['read'])` reads as "grant to nobody" |

An empty *block* is not an empty *rule list*. This change writes the second.

## Capabilities

### Modified Capabilities

- `mail-intake` — the schemas backing mail intake, outbound messaging and message
  routing become readable only to administrators and the object's owner.

### New Capabilities

None.

## Scope

### In Scope

- One `register.d` fragment adding an `authorization` block with explicit empty
  rule lists to: `digitalPostMessage`, `intake_message`, `intake_routing_rule`,
  `mail_message`, `mapping_version`, `outbound_message`, `recipient_key`,
  `recipient_opt_out`, `verdict`.
- A fragment `_comment` recording the decision and its reasoning, per the
  convention `catalog-item-schema.json` sets for a deliberate authorization choice.
- A test asserting the fragment closes rather than opens — that is, asserting the
  rule lists are present and empty, not merely that a block exists.

### Out of Scope

- **Deciding who *should* read a `mail_message`.** This change makes the schemas
  private; it does not design the access model. See Impact.
- `sender_identity`, closed by `enrol-sender-identity-in-credential-broker`.
- Any change to `PermissionHandler` or to OpenRegister's read default.

## Approach

One fragment rather than nine. They are one finding with one decision, and ADR-037's
reason for per-change fragments — disjoint files so concurrent builds do not
conflict — is satisfied by one file owned by one change.

## New Dependencies

None.

## Impact

**The Mail intake page becomes administrator-only for polled mail, and this is the
part to be deliberate about.**

Traced rather than assumed. `SaveObject::applyOwnerAttribution()` sets the owner
from the user session, and falls back to the *system user id* when there is none.
`MailboxSourceHandler` — the mailbox poll, which is how mail normally arrives —
runs without a session. So polled messages are owned by the system user, which is
nobody, and the object-owner bypass does not fire for any real person.

| Path | Owner | Who can read after this change |
|---|---|---|
| `MailboxSourceHandler` (poll) | system user | administrators only |
| `MailIntakeController` (a person imports an `.eml`) | that person | administrators + that person |

So the interim is genuinely restrictive: a Mail intake page that exists so people
can read their messages will show polled messages to administrators only. That is
accepted for this beta because the alternative is leaving every intercepted message
readable by every account on the instance, which is worse. It is not a design, and
it should not be mistaken for one.

No code, no migration, no API change. Fragments merge at runtime through
`InitializeRegister`, so the base register JSON is untouched.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A non-admin UI silently shows nothing instead of refusing

**Severity:** Medium — **Mitigation:** RBAC filtering on the list path removes rows
rather than returning an error, so a functional-beheerder opening Mail intake sees
an empty table, not a permission message. Nothing breaks and nothing says why. This
is a real usability regression, and the honest mitigation is that it is visible
immediately to anyone who opens the page — unlike the disclosure it replaces, which
was invisible to everyone. The access model that fixes it properly is
ConductionNL/integriq#2105.

### Risk 2: The fragment is written with an empty block and closes nothing

**Severity:** Medium — **Mitigation:** This is the failure this change is most
likely to have, because `"authorization": {}` reads as "locked down" to anyone
skimming. The test asserts the rule lists exist and are empty rather than asserting
a block is present, and the fragment `_comment` states the distinction with the
`PermissionHandler` line numbers.

### Risk 3: A writer that relied on default-open write access starts failing

**Severity:** Low — **Mitigation:** The blocks close `create`/`update`/`delete`/
`destroy` as well as `read`. Writers run either as an administrator or sessionless
as the system user; the latter is unaffected because these paths pass through
OpenRegister's own save, and the former is admin. Covered by the existing mail
intake tests continuing to pass.

## Rollback Strategy

Delete the fragment, or `git revert` — per repository policy, never a history
rewrite. Fragments are merged at runtime and persist no state of their own, so
removing it restores the previous (open) behaviour on the next `occ upgrade`.

## Open Questions

1. **Who should read a `mail_message`?** Tracked as ConductionNL/integriq#2105.
   A functional-beheerder group, case-based access, or per-object ownership with
   the poller attributing messages to a real user rather than the system identity.
   This is the design conversation this change defers, and the third option is the
   one that would make the intake page work as intended.
2. **Should `mapping_version` and `intake_routing_rule` be admin-only permanently?**
   They are configuration rather than content, so unlike the message schemas they
   are probably correct as they now are, and need no follow-up.
