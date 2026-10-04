# Proposal: stuf-zkn-inbound-on-the-consumer-model

kind: code. Part of `public-webhooks-on-the-consumer-model`, which holds the shared design. Ruben approved the consumer model on 2026-10-04: "prove live, then fix".

## Why

`POST /api/stuf-zkn/inbound` answered 401 to every correctly signed delivery without a session. Its secret lived in an admin-only `source`, read with RBAC on. Live on 2026-10-04 (`iwh-live/commands.md`): run-1: 401. run-2: with an admin session 200 (Fo03: no zaken register on the throwaway) and stuf_message stored.

## What changes

- The delivery authenticates the `stuf-zkn-webhook` consumer and writes `stuf_message` as that consumer's account, through `WebhookGate`.
- A missing connection, account or right answers 503 and alerts the administrators. A refused write answers 503, not 200.

## Live after

run-5: 503 stufzkn_account_unavailable. run-7: 200 Fo03, and the existing stuf_message is updated by webhook-intake (audit). The organisation codes and target register are read with an engine read.

## Capabilities

### Modified Capabilities

- `stuf-zkn-bridge`: REQ-020.
