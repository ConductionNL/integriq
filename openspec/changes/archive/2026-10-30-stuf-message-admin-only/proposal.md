# Proposal: stuf-message-admin-only

## Why

Review of integriq#2643 (iq-f1): a retrying StUF-ZDS kennisgeving keeps its envelope (zaak and betrokkenen with BSN) on its `stuf_message` row, and the schema had no `authorization` block, so any signed-in account could read, create, update and delete the rows.

## What changes

- `stuf_message` is admin-only on every verb (`99-stuf-message-lockdown.json`). Integriq's own code reads and writes the rows with `_rbac: false`.
- The WS-Security UsernameToken is never part of a stored envelope: it is added when the message is sent, on the first attempt and on every retry.
- A row that keeps an envelope for a retry expires after 24 hours and is removed by the retention sweep if it never concluded. Concluded rows are audit records and are not swept.
- REQ-020 of `stuf-zkn-bridge` no longer needs the connection's account to hold rights on `stuf_message`: those rows are engine writes. The zaak and document upserts still run as the account.
