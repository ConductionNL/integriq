# Proposal: intake-channels-on-the-consumer-model

kind: code. Part of `public-webhooks-on-the-consumer-model`, which holds the shared design. Ruben approved the consumer model on 2026-10-04: "prove live, then fix".

## Why

`POST /api/intake/channels/{channel}/inbound and POST /api/verdicts/inbound` answered 401 to every correctly signed delivery without a session. Its secret lived in an admin-only `source`, read with RBAC on. Live on 2026-10-04 (`iwh-live/commands.md`): run-1: 401 for form-submission and verdicts. run-2: with an admin session 202 (held) and 201.

## What changes

- The delivery authenticates the `intake-channel-<channelId> (verdicts: intake-channel-verdicts)` consumer and writes `intake_message (verdicts: verdict)` as that consumer's account, through `WebhookGate`.
- A missing connection, account or right answers 503 and alerts the administrators. A refused write answers 503, not 200.

## Live after

run-5: 503. run-6: an ordinary account is refused in the settings: both schemas grant create to administrators only. run-10: on an administrator account, 201 and 202.

## Capabilities

### Modified Capabilities

- `intake-channels`: REQ-IC-020.
