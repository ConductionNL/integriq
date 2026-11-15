# Proposal: uwlr-eduv-retour-on-the-consumer-model

kind: code. Part of `public-webhooks-on-the-consumer-model`, which holds the shared design. Ruben approved the consumer model on 2026-10-04: "prove live, then fix".

## Why

`POST /api/uwlr-eduv/retour` answered 401 to every correctly signed delivery without a session. Its secret lived in an admin-only `source`, read with RBAC on. Live on 2026-10-04 (`iwh-live/commands.md`): run-1: 401. run-2: with an admin session 200 and stored.

## What changes

- The delivery authenticates the `uwlr-eduv-webhook` consumer and writes `uwlr_eduv_message` as that consumer's account, through `WebhookGate`.
- A missing connection, account or right answers 503 and alerts the administrators. A refused write answers 503, not 200.

## Live after

run-5: 503 uwlreduv_account_unavailable. run-7: 200 and stored, owned by webhook-intake.

## Capabilities

### Modified Capabilities

- `uwlr-eduv-adapter`: REQ-020.
