# Proposal: peppol-inbound-on-the-consumer-model

kind: code. Part of `public-webhooks-on-the-consumer-model`, which holds the shared design. Ruben approved the consumer model on 2026-10-04: "prove live, then fix".

## Why

`POST /api/peppol/inbound` answered 401 to every correctly signed delivery without a session. Its secret lived in an admin-only `source`, read with RBAC on. Live on 2026-10-04 (`iwh-live/commands.md`): run-1: 401, nothing stored. run-2: with an admin session 200 and the inbound-document event stored.

## What changes

- The delivery authenticates the `peppol-webhook` consumer and writes `peppol_transmission` as that consumer's account, through `WebhookGate`.
- A missing connection, account or right answers 503 and alerts the administrators. A refused write answers 503, not 200.

## Live after

run-5: 503 peppol_account_unavailable without an account. run-7 and run-9: 200; the inbound-document event is owned by the account, and a delivery callback updates the transmission (audit "update by webhook-intake").

## Capabilities

### Modified Capabilities

- `peppol-access-point-connector`: REQ-020.
