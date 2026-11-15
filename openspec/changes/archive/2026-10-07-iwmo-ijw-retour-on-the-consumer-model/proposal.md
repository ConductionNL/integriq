# Proposal: iwmo-ijw-retour-on-the-consumer-model

kind: code. Part of `public-webhooks-on-the-consumer-model`, which holds the shared design. Ruben approved the consumer model on 2026-10-04: "prove live, then fix".

## Why

`POST /api/iwmo-ijw/retour` answered 401 to every correctly signed delivery without a session. Its secret lived in an admin-only `source`, read with RBAC on. Live on 2026-10-04 (`iwh-live/commands.md`): run-1: 401. run-2: with an admin session 200 and stored.

## What changes

- The delivery authenticates the `iwmo-ijw-webhook` consumer and writes `iwmo_ijw_message` as that consumer's account, through `WebhookGate`.
- A missing connection, account or right answers 503 and alerts the administrators. A refused write answers 503, not 200.

## Live after

run-5: 503 iwmoijw_account_unavailable. run-7: 200 and stored, owned by webhook-intake. The linked case update runs as the account too, so the account needs rights on the case schema.

## Capabilities

### Modified Capabilities

- `iwmo-ijw-adapter`: REQ-020.
