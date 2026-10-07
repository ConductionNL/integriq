# Tasks: public-webhooks-on-the-consumer-model

## 1. Proof

- [x] 1.1 Inventory every public endpoint (`#[PublicPage]`) that reads admin config or writes without a user
- [x] 1.2 Live before: eleven signed deliveries without a login answer 401 and store nothing; the same bytes with an admin session store (`iwh-live/commands.md` run-1, run-2, run-2b); unsubscribe 500 (run-3)

## 2. The mechanism

- [x] 2.1 `WebhookProfile`, `WebhookProfiles`, `WebhookConnection`; `OpenFormulierenConnection` delegates. Verify: OF tests unchanged and green
- [x] 2.2 `WebhookGate` (401, 503 plus alert, 503 on a refused write). Verify: a runAs mutation fails every identity test

## 3. Upgrade and settings

- [x] 3.1 `WebhookTrustMigrator` and the repair step `MigrateWebhookConnections`; OF migration delegates; version bump. Verify in PHPUnit: one consumer per source, no account, idempotent, disabled and secretless sources skipped
- [x] 3.2 Notifier names the webhook; one consumer per webhook type
- [x] 3.3 Admin section: list and save each webhook's consumer and account. Verify in PHPUnit: no secret in GET, refusals with field errors, admin warning

## 4. Live after

- [x] 4.1 Upgrade creates the consumers; deliveries answer 503 until an account is chosen; then store as that account (`iwh-live/commands.md` run-4 to run-12)
