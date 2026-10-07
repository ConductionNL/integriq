# Tasks: notifynl-inbound-on-the-consumer-model

- [x] 1.1 Prove the loss live before the change, with an admin-session control (`iwh-live/commands.md` run-1, run-2)
- [x] 1.2 Move the endpoint onto `WebhookGate` with its profile. Verify: `tests/Unit/Controller/NotifyNlWebhookConsumerTest.php` errors on development, passes after; a runAs mutation fails its identity case
- [x] 1.3 Prove it live after the change (`iwh-live/commands.md` run-5 to run-10)
