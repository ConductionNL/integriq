# Tasks: opt-outs-in-an-app-table-and-routing-rules-read-as-config

Ruben approved both decisions on 2026-10-05.

- [x] 1.1 Routing rules read with `_rbac: false`, `_multitenancy: false`; PHPUnit red before (`testAnEnabledRuleRoutesWhenTheCallerCannotReadRules`)
- [x] 2.1 Migration, `OptOut`, `OptOutMapper` (one row per address, scope and case)
- [x] 2.2 `OptOutRegistry` reads and writes the table; the unsubscribe link writes it after the token verifies (200, 400, 410)
- [x] 2.3 Token format v2 with expiry; v1 honoured; PHPUnit red before (`UnsubscribeLinkTest`)
- [x] 2.4 Repair step `MigrateOptOutsToTable`, idempotent, tested; app version bumped
- [x] 2.5 Opt-out page reads `GET /api/outbound/opt-outs`; English and Dutch strings
- [x] 3.1 Live on the throwaway instance (`iur-live/commands.md`)
- [ ] 4.1 Retire `recipient_opt_out` once the copy has run on every instance (follow-up)
