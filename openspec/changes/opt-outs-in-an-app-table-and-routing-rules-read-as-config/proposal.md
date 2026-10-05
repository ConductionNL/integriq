# Proposal: opt-outs-in-an-app-table-and-routing-rules-read-as-config

kind: code. Cites **ADR-099** (acting on behalf of a user, section 9: `runAsSystem()` is unreachable from a request path) and **ADR-022** (apps consume OpenRegister abstractions). Ruben approved both decisions on 2026-10-05.

## Why

**The unsubscribe link answered 500 without a login.** `GET /unsubscribe/{token}` wrote the opt-out to OpenRegister's `recipient_opt_out`. OpenRegister refuses an anonymous write, and the schema is deny-all for every account but an administrator. Live on 2026-10-05 (`iur-live` run-1): 500 without a login and 500 for an ordinary account; only an administrator got 200. The person following the link has no account. ADR-099 forbids `runAsSystem()` from a request path, so the write cannot be elevated.

**Every intake message was held.** `intake_routing_rule` is deny-all. The routing read ran as the intake account of the connection, which is no administrator, so it saw no rule. Live (`iur-live` run-1 B3): an enabled rule, and the message was held "No routing rule matched".

## What changes

- **Opt-outs move to a table integriq owns**, `integriq_opt_outs`, through a Nextcloud migration, an `OptOut` entity and an `OptOutMapper`. The link verifies the signed token, then writes one row. Nothing else is written, and OpenRegister is not touched.
- **New links expire.** Format `v2.<claims>.<signature>`, with `e` (unix time) in the claims and the `v2.` prefix under the signature. Default one year, `outbound.unsubscribe_ttl_days` per instance. An expired link answers 410 and changes nothing. A tampered link answers 400 and changes nothing.
- **Old links keep working.** A link in the old format whose signature verifies is honoured, and the row says `unsubscribe-link-v1`. They are already in mail people received, an opt-out is the recipient's right, and the most a leaked old link does is stop the non-statutory updates of one case for the one address it names. Nothing mints the old format any more.
- **Every reader reads the table.** `OptOutRegistry::decide()` and the opt-out page (`GET /api/outbound/opt-outs`, administrators only, custom page `RecipientOptOutsPage`).
- **The old opt-outs are copied.** Repair step `MigrateOptOutsToTable` reads `recipient_opt_out` as the engine and inserts each one once, keyed on address, scope and case. A second run copies nothing.
- **`recipient_opt_out` stays as read-only history** for this release. It is deny-all already, nothing writes it, and it is the source of the copy on every instance that has not upgraded yet. Retiring it is a follow-up once the copy has run everywhere.
- **Routing rules are read as admin configuration**: `_rbac: false`, `_multitenancy: false`, reads only, like the DSO and webhook consumer reads. Writing a rule stays an administrator action.

## Capabilities

### Modified Capabilities

- `outbound-sender-identity`: new REQ-OSI-010 and REQ-OSI-011.
- `intake-channels`: new REQ-IC-007.

## Impact

- New table `integriq_opt_outs` (migration `Version2Date20261005100000`), repair step `MigrateOptOutsToTable`, app version 0.4.9-unstable.20261005140000.
- `SenderIdentityController::unsubscribe()` answers 200, 400 or 410. New `SenderIdentityController::optOuts()`.
- `UnsubscribeTokenService` takes an `ITimeFactory`; `OptOutRegistry` takes the mapper and an `ITimeFactory` instead of OpenRegister's `ObjectService`.
- `IntakeRoutingService::firstMatchingRule()` reads with RBAC off.
- No sender calls `OptOutRegistry::decide()` yet (integriq#2114). The table is ready for the first one.
