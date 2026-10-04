# Proposal: notifynl-inbound-on-the-consumer-model

kind: code. Part of `public-webhooks-on-the-consumer-model`, which holds the shared design. Ruben approved the consumer model on 2026-10-04: "prove live, then fix".

## Why

`POST /api/notifynl/inbound` answered 401 to every correctly signed delivery without a session. Its secret lived in an admin-only `source`, read with RBAC on. Live on 2026-10-04 (`iwh-live/commands.md`): run-1: 401. run-2: with an admin session 200.

## What changes

- The delivery authenticates the `notifynl-webhook` consumer and writes `sms_message` as that consumer's account, through `WebhookGate`.
- A missing connection, account or right answers 503 and alerts the administrators. A refused write answers 503, not 200.

## Live after

run-5: 503 notifynl_account_unavailable. run-9: 200 and the seeded sms_message is delivered, audit "update by webhook-intake".

## Capabilities

### Modified Capabilities

- `notifynl-sms-channel`: REQ-020.
