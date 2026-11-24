# integration-regression-tests Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- automation-integration-regression-tests

## Purpose

An integration engineer keeps known inputs with their expected outputs for a
mapping or a synchronization, and runs them again as a suite after an edit and
before a promotion. Matrix row `integriq:auto-regression-tests`.

## ADDED Requirements

### Requirement: A test case stores an input, an expected output and its subject (REQ-IRT-001)

Integriq MUST persist a test case as an OpenRegister object of schema
`integration_test_case` carrying a name, a subject type (`mapping` or
`synchronization`), the subject's uuid, an input object, an expected output
object and a list of JSON paths ignored in the comparison. An integration
engineer MUST be able to create, edit and delete a case from the test cases
page.

#### Scenario: an engineer adds a case by hand
- GIVEN an integration engineer on the test cases page with the mapping `lowercase-keys` selected
- WHEN they enter an input, an expected output and save
- THEN a case is listed for that mapping with its input and expected output
- e2e: tests/e2e/integration-regression-tests.spec.ts

### Requirement: A test case can be recorded from a traced execution (REQ-IRT-002)

The trace detail page MUST offer "Save as test case" on a trace that contains a
mapping step. Saving MUST copy that step's input and output into a new case for
the mapping that ran, and MUST list every field that carries a redaction
marker before the case is saved.

#### Scenario: a real run becomes a case
- GIVEN an administrator on the trace detail page of a successful synchronization run
- WHEN they choose "Save as test case" on its mapping step
- THEN a case is created whose input and expected output equal that step's snapshot
- e2e: tests/e2e/integration-regression-tests.spec.ts

#### Scenario: redacted fields are shown before save
- GIVEN a mapping step whose input holds a redacted `authorization` field
- WHEN the administrator opens "Save as test case"
- THEN the dialog names the redacted field and lets them replace it before saving
- e2e: tests/e2e/integration-regression-tests.spec.ts

### Requirement: A suite run replays every case without calling the source or writing (REQ-IRT-003)

Running the suite of a mapping or a synchronization MUST execute every case of
that subject and MUST record one `integration_test_run` object with the total,
the passed count, the failed count and, per failed case, the difference between
the expected and the actual output. A synchronization case MUST feed the stored
input through the synchronization's mapping and rules in test mode, and MUST
NOT fetch from the live source. A run MUST NOT create, update or delete any
object other than the run record.

#### Scenario: an edited mapping breaks a case
- GIVEN a mapping with two cases that both passed yesterday
- WHEN an integration engineer edits a rule and chooses "Run test cases" on the mapping detail page
- THEN the run shows one pass and one fail, and the failed case shows the path whose value changed
- e2e: tests/e2e/integration-regression-tests.spec.ts

#### Scenario: a synchronization suite leaves the target untouched
- GIVEN a synchronization with a case whose expected output would create a target object
- WHEN the suite runs
- THEN no target object is created and the live source receives no request
- @e2e exclude an absence claim across two systems; covered by PHPUnit on RegressionSuiteService with a mocked CallService

#### Scenario: an ignored path does not fail a case
- GIVEN a case that ignores `dateModified`
- WHEN the actual output differs from the expected output only at `dateModified`
- THEN the case passes
- @e2e exclude a comparison rule; covered by PHPUnit on the comparator

### Requirement: Running and recording suites are named actions (REQ-IRT-004)

Running a suite MUST require the action `regression-suite.run` and recording a
case from a trace MUST require `regression-suite.record`, both declared in the
action matrix with admin as the default group.

#### Scenario: a user without the action cannot run a suite
- GIVEN a signed-in user outside every group allowed `regression-suite.run`
- WHEN they call `POST /api/regression-suites/mapping/{id}/run`
- THEN the response is 403 and no run record is written
- @e2e exclude an authorization refusal; covered by PHPUnit on RegressionSuiteController

### Requirement: The promotion preview shows failing cases before a change goes live (REQ-IRT-005)

The promotion preview MUST run the suites of every mapping and synchronization
in the configuration being promoted and MUST list the failing cases. Confirming
a promotion while a case fails MUST require an explicit override in the
request, and the promotion audit record MUST store the failed count and whether
the override was given.

#### Scenario: a failing case is visible on the preview
- GIVEN a configuration whose mapping has one failing case
- WHEN an administrator opens the promotion preview for the acceptance environment
- THEN the preview lists the failing case by name next to the create and update counts
- e2e: tests/e2e/integration-regression-tests.spec.ts

#### Scenario: a promotion with failures needs an override
- GIVEN the same failing case
- WHEN the administrator confirms the promotion without the override
- THEN the promotion is refused with a message naming the failing count, and nothing is sent to the target
- @e2e exclude a refusal before a cross-instance call; covered by PHPUnit on PromotionService
