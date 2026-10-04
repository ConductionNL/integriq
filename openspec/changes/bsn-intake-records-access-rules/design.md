## Context

OpenRegister evaluates a schema's `authorization` block in `PermissionHandler::hasPermission()` (one object) and `MagicRbacHandler` (lists). Read from its code on development (e80cd62):

- No block: every authenticated account has every action. An anonymous caller still cannot write (#1955).
- A block: an action that is not listed is denied (fail closed). `admin` always passes. The owner of an object always passes for that object.
- An entry is a group id, `{group, match}`, or a single account: `user:<uid>` or `{user, match}` (delegation, `rbac-zaaktype`).
- Groups named in a block are created on import (`rbac-scopes`, "A declared group MUST exist as a Nextcloud group"), create-only, with no members.

## Decisions

### D1. The blocks

| Schema | create | read | update | delete |
|---|---|---|---|---|
| `dso_verzoek` | `dso-intake` | `dso-behandelaars` | `dso-intake`, `dso-behandelaars` | nobody |
| `openformulieren_submission` | `openformulieren-intake` | `openformulieren-behandelaars` | `openformulieren-intake`, `openformulieren-behandelaars` | nobody |

Administrators pass every rule. The intake account also reads its own records, through OpenRegister's owner rule. The retry match of both intakes depends on that and needs no `read` grant.

### D2. A group for the intake account, not `user:<uid>`

OpenRegister can name one account. The intake account differs per instance, and a register file is the same on every instance. So the rule names the intake group, and integriq puts the account in it:

- when an administrator chooses the account in the DSO or Open Formulieren connection section: the chosen account joins the group before its rights are checked, and the previous account leaves it;
- on upgrade, through the repair step `ProvisionIntakeGroups`.

A refused account does not stay in the group.

### D3. Fixed group names

The names are fixed, not settings. The register import rewrites a schema's authorization block on every upgrade (`importFromApp`), so a name configured on one instance would be overwritten by the next import. An administrator who wants an LDAP or SAML group to handle verzoeken adds its members to the fixed group, or maps it with OpenRegister's derived grants.

### D4. The handoff and the attachment copy

The `submission-to-case` and `verzoek-to-case` handoffs run as the handler who triggers them. The handler needs `read` and `update` on the record (the engine sets `onSuccess.set` on it); both are in the handler grant.

### D5. Repair step

`ProvisionIntakeGroups` creates the four groups when they are missing and puts the account of each `dso-stam` and `open-formulieren` consumer in its intake group. It runs after the two connection migrations, and on a fresh install. It writes nothing to OpenRegister and never adds anyone to a handler group.

## Risks

- **Handlers lose access until an administrator fills the handler group.** Until then only administrators read verzoeken and submissions. That is the intended default for BSN data; the proposal tells operators.
- **Records owned by another account.** A record stored by an account that is no longer the intake account stays invisible to the new account. A retry of such a record creates a second one.
- **Delete.** Nobody but an administrator and the owner (the intake account) can delete. OpenRegister's owner rule cannot be switched off per schema.
