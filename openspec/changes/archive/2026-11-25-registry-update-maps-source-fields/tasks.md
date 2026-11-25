# Tasks: registry-update-maps-source-fields

Kind: code. Size S. Decision 178 (Q-dossiq-L4b-1). Unblocks dossiq contacts-domain 4.4.

### Task 1: Providers declare a map per target schema
- **spec_ref**: `openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004`
- **files**: `lib/Service/Registry/MapsSourceFieldsInterface.php`, `lib/Service/Registry/BrpVolgindicatieProvider.php`, `lib/Service/Registry/KvkMutatieProvider.php`, `lib/Service/Registry/SubscriptionChange.php`
- [x] Implement
- [x] Test (`tests/Unit/Service/Registry/SourceFieldMapTest.php`)

### Task 2: The roster records target schemas, the handler fills them
- **files**: `lib/Service/Registry/SubscriptionRoster.php`, `lib/Service/Registry/SubscriptionRequestHandler.php`
- [x] Implement
- [x] Test (`tests/Unit/Service/Registry/SubscriptionRequestHandlerTest.php`)

### Task 3: The poll job posts one mapped update per target schema
- **files**: `lib/BackgroundJob/RegistrySubscriptionPollJob.php`
- [x] Implement
- [x] Test (`tests/Unit/BackgroundJob/RegistrySubscriptionPollJobTest.php`)

### Task 4: Live pass
- [x] A dossiq requester with a BSN, BRP source in mock mode: a `verblijfplaats` change lands on `brpPerson.residence` (live pass, decision 139)
  > 2026-11-25, live. Sandbox: Nextcloud 34.0.6 + MariaDB, OpenRegister main 292aa09d, dossiq development 7ba031ca, integriq development. The `brp-haalcentraal` source points at a stand-in Haal Centraal volgindicaties API on loopback, which answers the PUT/DELETE of a volgindicatie and serves one `gewijzigd.verblijfplaats` row on the poll.
  > A dossiq case naming a `brpPerson` (BSN 999990032) as requester subscribes it. integriq PUTs the volgindicatie, and the subscription turns `active`. `RegistrySubscriptionPollJob` then polls, and the change lands on `brpPerson.residence` mapped from `verblijfplaats` (`Oude Gracht 5` → `Nieuwe Gracht 12, 3512LS Utrecht`), with `@self.registry.lastUpdateSource` = `vi-999990032`. Name and birth are untouched.
  > The pass needed two upstream fixes, both found by it:
  > - dossiq#6984: dossiq read the requester rendered, so the BSN reached OpenRegister and integriq masked (`*****3653`), and integriq subscribed that at the BRP.
  > - openregister#11327: OpenRegister's inbound update looked up `active` subscriptions only, and a row became active only when an update was applied, so a subscription never left `requested` and every confirm and change answered 404.
  > integriq now refuses a masked identity: no BRP call, and the subscription is reported `failed` with the reason (live with the old dossiq; `SubscriptionRequestHandlerTest::testAMaskedIdentityIsRefusedNotSubscribed`).
  > Deployment notes, unchanged here:
  > - The post-back to OpenRegister is an HTTP call to the instance's own URL, with `registry_subscription.connector_credential` (Basic). Nextcloud refuses it when that host resolves to a local address, unless `allow_local_remote_servers` is set.
  > - In cron, the URL comes from `overwrite.cli.url`.

