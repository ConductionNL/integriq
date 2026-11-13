# Tasks: integriq-tenant-scoping

Kind: code. Size M, split into small PRs by area. Row `integriq:acc-tenants`, decision 408.

## Implementation tasks

### Task 1: Sources
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001`
- **files**: `lib/Controller/SourcesController.php`
- **acceptance_criteria**:
  - GIVEN a source of another organisation WHEN a user tests it or trips or resets its breaker THEN the answer is 404 and the source is not called
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 2: Synchronisations, contracts, dead letters and jobs
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001`
- **files**: `lib/Controller/SynchronizationsController.php`, `lib/Controller/SynchronizationContractsController.php`, `lib/Controller/SyncDeadLetterController.php`, `lib/Controller/JobsController.php`
- **acceptance_criteria**:
  - GIVEN a synchronisation or job of another organisation WHEN a user runs, resets or changes it THEN the answer is 404 and nothing runs
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: Logs and events
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001`
- **files**: `lib/Controller/EventsController.php`, `lib/Controller/BodyCaptureController.php`, `lib/Controller/LogsController.php`, `lib/Controller/ProductSubscriptionsController.php`
- **acceptance_criteria**:
  - GIVEN logs and events of two organisations WHEN a user lists or opens them THEN only their own organisation's entries are reached
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 4: Endpoints and services called from user requests
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-system-paths-run-unscoped-only-with-a-stated-reason-req-ten-003`
- **files**: `lib/Service/EndpointTargetResolver.php`, `lib/Service/EndpointService.php`, and the services under `lib/Service/` that a controller calls
- **acceptance_criteria**:
  - GIVEN each remaining `_multitenancy: false` WHEN reviewed THEN it is either removed (user-facing) or carries a one-line reason (system)
- [ ] Implement
- [ ] Test (PHPUnit)
