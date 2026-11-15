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
- [ ] A dossiq requester with a BSN, BRP source in mock mode: a `verblijfplaats` change lands on `brpPerson.residence` (live pass, decision 139)
