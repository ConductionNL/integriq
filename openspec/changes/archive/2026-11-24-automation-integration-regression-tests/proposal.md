---
kind: code
depends_on: []
---

# Proposal: automation-integration-regression-tests

## Summary

Integriq can test one mapping by hand and replay one traced run. It cannot
keep a set of known inputs with their expected outputs and run them again
after someone edits a mapping or before a configuration is promoted. This
change adds recorded test cases, a suite runner that replays them without
writing anything, and a check on the promotion preview that shows which
cases fail before a change goes live.

## Why

Matrix row `integriq:auto-regression-tests`, "Record test cases for an
integration and replay them as regression tests before a change goes live."
The matrix rates integriq `partial` with `built.state` `built`. The missing
half is the recorded suite.

Demand:

- featureRequest, https://community.n8n.io/t/254023. The matrix note reads:
  "n8n community feature request 2026-01-22, workflow unit testing with test
  cases; Frank!Framework issue 6603 asks the same of Larva."

Competitors rated `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/munit/latest/test-recorder.md: "The MUnit Test
  Recorder captures real flow execution data in Anypoint Studio and
  automatically" generates an MUnit test from it; MUnit suites run with the
  Maven build before deployment (https://docs.mulesoft.com/munit/latest/index.md).
- Frank!Framework (`frank`), source read at v10.2.0: "a recorded Ladybug
  report is turned into a Larva test scenario by
  ladybug/debugger/src/main/java/org/frankframework/ladybug/larva/ConvertToLarvaAction.java:64,
  and larva/src/main/java/org/frankframework/larva/ScenarioRunner.java:48
  replays scenarios with expected output comparison". No evidence URL is
  recorded for this cell; the evidence is the source path.

n8n is rated `partial`: its evaluation feature scores metrics rather than
gating a change with pass or fail.

This change covers one row: `integriq:auto-regression-tests`.

## What integriq already has

- A single mapping test by hand: `appinfo/routes.php:423` routes
  `mappings#test` to `lib/Controller/MappingsController.php:145`, guarded by
  the `mapping.test` action at `:151`. The mapping detail page calls it as a
  live preview (`src/views/wrappers/MappingDetailPage.vue:30`).
- A single synchronization test by hand: `appinfo/routes.php:406` routes
  `synchronizations#test` to `lib/Controller/SynchronizationsController.php:266`,
  which calls `synchronize(isTest: true)` at `:288`. That run fetches from
  the live source, so its output changes with the source.
- A replay of one traced execution: `lib/Service/ExecutionTraceService.php:227`
  replays a trace, as a dry run when `force` is false (`:248`), and the trace
  detail page offers it (`src/views/ExecutionTrace/TraceDetailPage.vue:226`
  and `:255`). Each trace keeps redacted step inputs and outputs
  (`lib/Settings/register.d/execution-trace-observability.json:66`).
- Promotion to another environment with a preview:
  `lib/Controller/PromotionController.php:92` and `:139`, service
  `lib/Service/PromotionService.php:298`.

None of these stores an expected output, and none runs more than one check at
a time.

## What this change builds

1. An `integration_test_case` schema: a named input, the mapping or
   synchronization it belongs to, the expected output, and paths to ignore
   when comparing.
2. "Save as test case" on a trace: the input and output of a mapping step in a
   real trace become a test case, the way MUnit and Ladybug record one.
3. A suite runner that replays every case of a mapping or a synchronization
   without calling the live source and without writing, and records an
   `integration_test_run` with a per-case pass or fail and a diff.
4. A "Run test cases" action on the mapping and synchronization detail pages.
5. A regression check on the promotion preview: it runs the suites of every
   mapping and synchronization in the configuration and shows the failures.
   A promotion with failing cases needs an explicit override that the
   promotion audit log records.

## Out of scope

- Test cases for endpoints and for event deliveries. Their replay depends on
  the caller and the subscriber, and `execution-trace` REQ-006 already covers a
  single forced replay.
- Scoring outputs with metrics, the way n8n evaluations do. A case passes or
  fails.
- Running suites in a CI pipeline outside Nextcloud. The occ commands in
  `platform-integrations-as-code` can call the runner later.
