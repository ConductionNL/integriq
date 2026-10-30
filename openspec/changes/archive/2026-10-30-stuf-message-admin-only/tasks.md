# Tasks: stuf-message-admin-only

- [x] 1. Register fragment `99-stuf-message-lockdown.json` and the ratchet test.
- [x] 2. `StufZdsOutbound` adds the UsernameToken at send time; the persisted envelope carries none.
- [x] 3. `StufZdsMessageLog` and `StufZknSyncService` read and write `stuf_message` with `_rbac: false`.
- [x] 4. `expires` on a row that keeps an envelope, and the retention sweep for unconcluded rows.
- [x] 5. The StUF-ZKN webhook profile no longer requires rights on `stuf_message` for its account.
