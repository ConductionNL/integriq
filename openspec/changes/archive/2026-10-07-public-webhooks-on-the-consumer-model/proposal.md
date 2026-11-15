# Proposal: public-webhooks-on-the-consumer-model

kind: code. Cites **ADR-022** (apps consume OpenRegister abstractions) and ADR-099 (scoped identity, `runAs`). Ruben approved the model on 2026-10-04: "prove live, then fix", on the consumer model of #2499 (DSO STAM) and #2506 (Open Formulieren).

## Why

Ten signed public webhooks lost every real delivery. Each read its signature secret from an admin-only `source` (`99-source-lockdown.json`) with RBAC on. A delivery has no session, so the read returned nothing and the controller answered 401 "invalid signature". A live run on 2026-10-04 proved it (throwaway Nextcloud 35, OpenRegister development e80cd62, integriq development b7a86424; evidence in `iwh-live/commands.md`):

- run-1: eleven correctly signed deliveries without a login: 401 each, nothing stored.
- run-2: the same bytes with an admin session: 200, 201 or 202, and stored.

So the signature and the payloads were right. Only the missing session differed. Most of these controllers also caught every write error and answered 200, so a delivery that a fixed read would still lose would have looked delivered.

## Inventory

| Endpoint | Before (no login) | Control (admin session) | After |
|---|---|---|---|
| POST /api/peppol/inbound | 401 | 200, event stored | 200, stored as the connection account |
| POST /api/notifynl/inbound | 401 | 200 | 200, sms_message updated as the account |
| POST /api/rod/retour | 401 | 200, stored | 503: kenmerk column renamed (open point) |
| POST /api/oso/import | 401 | 200, stored | 200, stored as the account |
| POST /api/oso/retour | 401 | 200, stored | 200, stored as the account |
| POST /api/uwlr-eduv/retour | 401 | 200, stored | 200, stored as the account |
| POST /api/verzuimloket/retour | 401 | 200, stored | 503: kenmerk column renamed (open point) |
| POST /api/iwmo-ijw/retour | 401 | 200, stored | 200, stored as the account |
| POST /api/stuf-zkn/inbound | 401 | 200 Fo03, stuf_message stored | 200 Fo03, stuf_message updated as the account |
| POST /api/verdicts/inbound | 401 | 201 | 201 with an admin account only (open point) |
| POST /api/intake/channels/{channel}/inbound | 401 | 202 | 202 with an admin account only (open point) |

Left as they are, with their reason:

- `GET /unsubscribe/{token}`: 500 without a login, 200 with one (live run-3). It is a citizen's link, not a partner webhook, so there is no consumer to act as. Open point.
- `POST /api/payments/webhook`, `POST /api/cti/{sourceId}/events`: their source reads are already engine reads (`_rbac: false`). Not proven live: the payment webhook fetches the status from the provider, which the throwaway has no account for.
- `POST /api/notificaties/callback/{id}`, Objecten API, SCIM, LTI: already resolve a consumer and act as its user, or write with `_rbac: false`.
- `POST /api/synchronizations/{id}/destroyed`, `/api/endpoint/{path}`, the EUDI wallet routes: read their trust with `_rbac: false` but write with RBAC on. Not intakes of partner data; not proven live here. Open point.
- Health, IdP broker, user login and logout: no OpenRegister write.

## What changes

- **One mechanism.** `WebhookConnection` is the Open Formulieren connection with the webhook as a parameter (`WebhookProfile`). It reuses the raw consumer read of `DsoConnection`, the rights check of `DsoAccountRights` and OpenRegister `runAs()`. `OpenFormulierenConnection` now delegates to it.
- **One consumer per webhook.** Each webhook has its own `authorizationType` (`peppol-webhook`, `intake-channel-<channelId>`, ...). The consumer holds the trust and, in `userId`, the account.
- **The answers.** 401 for a bad signature. 503 and one admin alert for a missing connection, account or right. 503 when the account's write is refused, so the partner delivers again.
- **Upgrade.** A repair step moves each source's webhook secret into its consumer, with no account. The administrators get one notification per webhook.
- **Settings.** An admin section lists every webhook and lets an administrator choose its account.

## Capabilities

### Modified Capabilities

- `consumer-management`: REQ-CM-020, REQ-CM-021.
- Per webhook, in its own change: `peppol-inbound-on-the-consumer-model`, `notifynl-inbound-on-the-consumer-model`, `rod-retour-on-the-consumer-model`, `oso-inbound-on-the-consumer-model`, `uwlr-eduv-retour-on-the-consumer-model`, `verzuimloket-retour-on-the-consumer-model`, `iwmo-ijw-retour-on-the-consumer-model`, `stuf-zkn-inbound-on-the-consumer-model`, `intake-channels-on-the-consumer-model`.

## Open points

- `verdict` and `intake_message` grant create to administrators only (`99-mail-schemas-lockdown.json`), and `intake_message` carries the submitter's BSN. They need access rules like the BSN intake ones (an intake group). Not invented here.
- ROD and Verzuimloket retours: `RenameDutchColumns` renamed the `kenmerk` column to `reference` in `rod_message` and `verzuim_message`, while both schemas declare `kenmerk`. Every non-admin kenmerk lookup fails, and `kenmerk` is never stored. Pre-existing; the 503 now shows it instead of a 200.
- The unsubscribe link writes `recipient_opt_out` without a user and fails with 500.
