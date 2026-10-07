# Proposal: rod-retour-on-the-consumer-model

kind: code. Part of `public-webhooks-on-the-consumer-model`, which holds the shared design. Ruben approved the consumer model on 2026-10-04: "prove live, then fix".

## Why

`POST /api/rod/retour` answered 401 to every correctly signed delivery without a session. Its secret lived in an admin-only `source`, read with RBAC on. Live on 2026-10-04 (`iwh-live/commands.md`): run-1 and run-2b: 401 without a login; 200 with an admin session.

## What changes

- The delivery authenticates the `rod-webhook` consumer and writes `rod_message` as that consumer's account, through `WebhookGate`.
- A missing connection, account or right answers 503 and alerts the administrators. A refused write answers 503, not 200.

## Live after

run-5: 503 rod_account_unavailable. run-7: 503 rod_delivery_not_stored: the kenmerk lookup fails because RenameDutchColumns renamed the column (open point). Before, that loss was answered 200.

## Capabilities

### Modified Capabilities

- `rod-adapter`: REQ-020.
