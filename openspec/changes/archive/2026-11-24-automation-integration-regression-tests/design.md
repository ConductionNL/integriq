# Design: automation-integration-regression-tests

Kind: code. A test case is an OpenRegister object, a suite run is an
OpenRegister object, and the runner reuses the mapping and synchronization
code paths integriq already has.

## Where it fits

- Schemas: a new fragment `lib/Settings/register.d/integration-regression-tests.json`
  (ADR-037) declares `integration_test_case` and `integration_test_run` in the
  `integriq` register, in the shape of
  `lib/Settings/register.d/execution-trace-observability.json:1`.
- Service: a new `lib/Service/RegressionSuiteService.php` holds the runner.
  It calls `MappingService::executeMapping()` at
  `lib/Service/MappingService.php:277` for a mapping case.
- Recorder: `lib/Service/ExecutionTraceService.php:170` (`find()`) reads the
  trace; a new method on the suite service turns one mapping step into a case.
- Controller and routes: a new `lib/Controller/RegressionSuiteController.php`
  with `regressionSuite#run` (POST `/api/regression-suites/{subjectType}/{subjectId}/run`)
  and `regressionSuite#record` (POST `/api/execution-traces/{id}/test-case`),
  added to `appinfo/routes.php` next to the execution-trace block at `:498`.
  The static `record` path sits before any `{id}` wildcard, following the
  comment at `appinfo/routes.php:482`.
- Actions: `regression-suite.run` and `regression-suite.record` in
  `lib/actions.seed.json`, next to `mapping.test` at `:24`, default admin.
- Pages: a manifest fragment `src/manifest.d/integration-regression-tests.json`
  adds a `logs` page over `integration_test_run` and an `index` page over
  `integration_test_case`. The "Run test cases" action goes on
  `src/views/wrappers/MappingDetailPage.vue` and
  `src/views/Synchronization/SynchronizationDetailPage.vue`; "Save as test
  case" goes on `src/views/ExecutionTrace/TraceDetailPage.vue` beside the
  dry-run button at `:226`.
- Promotion: `lib/Service/PromotionService.php:264` (`preview()`) gains the
  suite result; `:298` (`promote()`) refuses when a case fails and no
  override was given. The audit written at `:452` (`writeAudit()`) records the
  override.

## D1. A case is data, not code

A case holds an input object, the expected output, the subject (`mapping` or
`synchronization` plus its uuid) and a list of JSON paths to ignore, such as a
generated timestamp. The alternative was a PHPUnit or Twig test file per case,
the MUnit shape. Rejected: integriq's mappings are edited on a screen by an
integration engineer, and a test that needs a developer to write it will not
be written.

## D2. Record from a trace, not from live traffic

A trace already holds the input and output of each mapping step
(`execution-trace-observability.json:66`). Saving a case from a trace is the
Ladybug and MUnit recorder flow with no new capture path. The alternative was
a recording mode on a source that mirrors live traffic into cases. Rejected:
it doubles storage, and it copies personal data into a second place without a
retention rule.

The trace snapshots are redacted before they are stored
(`execution-trace` REQ-003). A case recorded from one keeps the redaction
marker, and the recorder shows which fields were redacted so the engineer can
replace them with test values before saving.

## D3. Replay never calls the live source and never writes

A mapping case runs through `executeMapping()`, which is a pure transform. A
synchronization case feeds the stored source object through the
synchronization's `sourceTargetMapping` and its rules in test mode, the path
`synchronize(isTest: true)` uses at
`lib/Controller/SynchronizationsController.php:288`, without the fetch. The
alternative was to call `synchronizations#test`. Rejected: it fetches from the
live source, so a regression suite would fail whenever the source's data
changed, which says nothing about the change under test.

`synchronization-engine` REQ-011 already guarantees a test run makes no
writes. The runner relies on it and asserts it in a unit test.

## D4. Promotion shows failures, and an override is audited

A promotion is the moment integriq's configuration goes live somewhere else.
The preview runs every suite whose subject is in the exported configuration
and lists the failing cases. Confirming with failures needs
`regressionOverride: true` in the request; `writeAudit()` stores the failing
count and the override. The alternative was a hard block. Rejected: an
engineer sometimes changes a mapping on purpose, and the fix is to update the
expected output, which the page offers from the failing case.

## Declarative versus imperative

Running a suite is imperative: it executes a mapping and compares outputs,
which no `x-openregister-*` annotation can do. The counts on the run page are
declarative: `integration_test_run` stores `passed`, `failed` and `total`, and
the `logs` page reads them through OpenRegister's aggregate, with no
controller for statistics.

## Seed data

`integration_test_case` seeds two cases through the fragment's
`x-openregister-seed`, both for the seeded `lowercase-keys` mapping at
`lib/Settings/integriq_seed_data.json:275`: one that passes, and one whose
expected output ignores a `dateModified` path. `integration_test_run` seeds
nothing; a run is history.

## Risks

- A case recorded from a trace can carry a redaction marker the engineer
  forgets to replace, and then it never passes. The recorder lists redacted
  fields before save.
- A mapping that reads the current date produces a different output each
  run. Ignored paths handle it; the docs name the pattern.
- Large suites slow down the promotion preview. The runner caps a preview at
  a configurable number of cases and says when it stopped.
