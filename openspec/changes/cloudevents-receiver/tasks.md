## 1. Build

- [x] 1.1 A receiver route per source in `appinfo/routes.php` and a controller under `lib/Controller/`, checked by webhook signature or credential (REQ-017).
- [x] 1.2 A decode method for structured and binary mode in `lib/Broker/CloudEventHttpBinding.php`, with the envelope checks.
- [x] 1.3 Store the event and call `EventService::processEvent()` in `lib/Service/EventService.php`; refuse a repeated `id` per source.
- [x] 1.4 The receiver URL on the endpoint and source pages (boards IqEndpoint, IqGebeurtenissen).
- [x] 1.5 Unit tests in `tests/Unit/` for both content modes, the missing field, the repeat and the unsigned request.

## 2. Hand-over

- [x] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
- [ ] 2.2 When archiving, set `cloud-event-management` REQ-CE-001 to point at this requirement; its spec says `status: implemented` while no receiver existed.
