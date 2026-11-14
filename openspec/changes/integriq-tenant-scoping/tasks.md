# Tasks: integriq-tenant-scoping

Kind: code. Size M, split into small PRs by area. Row `integriq:acc-tenants`, decision 408.

## Implementation tasks

### Task 1: Sources
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001`
- **files**: `lib/Controller/SourcesController.php`
- **acceptance_criteria**:
  - GIVEN a source of another organisation WHEN a user tests it or trips or resets its breaker THEN the answer is 404 and the source is not called
- [x] Implement
- [x] Test (PHPUnit)
  > Built in #3444: the source test and circuit-breaker lookups keep the organisation filter on; `tests/Unit/Controller/SourcesControllerTest.php` with `tests/Helpers/ForeignOrganisation.php`.

### Task 2: Synchronisations, contracts, dead letters and jobs
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001`
- **files**: `lib/Controller/SynchronizationsController.php`, `lib/Controller/SynchronizationContractsController.php`, `lib/Controller/SyncDeadLetterController.php`, `lib/Controller/JobsController.php`
- **acceptance_criteria**:
  - GIVEN a synchronisation or job of another organisation WHEN a user runs, resets or changes it THEN the answer is 404 and nothing runs
- [x] Implement
- [x] Test (PHPUnit)
  > Built in #3445: `tests/Unit/Controller/SynchronizationTenantScopingTest.php`.

### Task 3: Logs and events
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001`
- **files**: `lib/Controller/EventsController.php`, `lib/Controller/BodyCaptureController.php`, `lib/Controller/LogsController.php`, `lib/Controller/ProductSubscriptionsController.php`
- **acceptance_criteria**:
  - GIVEN logs and events of two organisations WHEN a user lists or opens them THEN only their own organisation's entries are reached
- [x] Implement
- [x] Test (PHPUnit)
  > Built in #3446 and #3511: `tests/Unit/Controller/EventsAndLogsTenantScopingTest.php`. Approval requests, which ApprovalsController and the API product approvals reach by id, were still read unscoped: `ApprovalService::find()` now keeps the organisation filter on, and the flow node, the engine gate, the task listener and the post-decision re-read pass `asSystem: true` with a reason (`tests/Unit/Service/ApprovalTenantScopingTest.php`).

### Task 4: Endpoints and services called from user requests
- **spec_ref**: `openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-system-paths-run-unscoped-only-with-a-stated-reason-req-ten-003`
- **files**: `lib/Service/EndpointTargetResolver.php`, `lib/Service/EndpointService.php`, and the services under `lib/Service/` that a controller calls
- **acceptance_criteria**:
  - GIVEN each remaining `_multitenancy: false` WHEN reviewed THEN it is either removed (user-facing) or carries a one-line reason (system)
- [ ] Implement
- [ ] Test (PHPUnit)
  > Partial: the remaining `_multitenancy: false` sites in `EndpointService`, `CallService` and `SynchronizationService` carry a one-line reason (all system writes or re-reads of an object already reached in scope); `EndpointTargetResolver` already did. A second pass gave 85 more sites a one-line `// Unscoped:` reason (LTI, exchange, EUDI, StUF, execution traces, outbound calls, the event queue, webhooks, the inbound gateway, migrations and repairs, occ commands, setup checks, caches). About 50 remain, mostly reads a user request can reach (agent tools, connection and certificate alert reads, regression suites, record provenance, KISS caller search, directory, portal activity): each needs the user-facing-or-system decision, not only a comment. `CallRecorder::read()` and `LtiPlatformDetailsService::forTool()` carry a reason but are user-reachable behind an action or admin check, and are worth a second look.
  > Third pass: 33 more system sites carry a one-line `// Unscoped:` reason (flow nodes and the runner, directory sync and SCIM, the consumer credential check, the service-fetch catalogue, engine re-reads, the settlement pull, endpoint serving, the alert and certificate jobs, approval re-reads, DSO and CTI intake, inbound destruction notices and CloudEvents, agent action audit records, the mailbox poll, migrations and repairs, inbound message routing, metrics, the public product channel page). The two MCP agent-tool reads (`IntegriqAgentTools::testSource()` and `findObject()`, behind `testSynchronization` and the dead-letter batches) are user-facing, so their `_multitenancy: false` is removed: another organisation's source or synchronization now answers exactly as an unknown id and nothing runs (`IntegriqAgentToolsTest::testAnotherOrganisationsSourceIsNotFoundAndNotCalled`, `::testAnotherOrganisationsSynchronizationIsNotFoundAndNotRun`). 13 sites remain, all reads a user request reaches through a controller or a cross-app binding, each needing the remove-or-reason decision against its callers: `RegressionSuiteService` (run, mappingOf, cases), `ConnectionStore::readSourceRaw`, `ConnectionMonitorService` (findSource, read), `RecordProvenanceService` (one, read), `Portal\ActivityOverview::recentVerzoeken`, `RunSummaryService::runsInWindow`, `EnvironmentService::resolveSource`, `Kiss\ConfiguredCallerSearch::readSource`, `DigitalPost\BerichtenboxHealth::sourceRows`.
