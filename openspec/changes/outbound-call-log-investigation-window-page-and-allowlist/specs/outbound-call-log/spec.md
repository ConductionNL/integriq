# outbound-call-log Specification (delta)

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- outbound-call-log-investigation-window-page-and-allowlist

## Purpose

An administrator opens and closes an investigation window from the source
page, the call log detail says when no body was stored, and only listed
public-data callers may keep bodies in code. Woo row 13.23, decision D5
(2026-10-05). Builds on `outbound-call-log-investigation-window`, which owns REQ-OCD-001, REQ-OCD-008 and
REQ-OCD-009.

## ADDED Requirements

### Requirement: Code may keep bodies only for listed public-data callers (REQ-OCD-010)

The per-call `logBody` option MUST be honoured only for callers on an allowlist
held in one constant. The allowlist MUST contain exactly the callers whose data
is public. On `development` at the time of writing that is
`OCA\Integriq\Adapters\Slo\SloCurriculumClientHttp`. A test MUST fail when a
class outside the allowlist passes `logBody`.

#### Scenario: a new caller cannot switch body logging on in code
- GIVEN a class outside the allowlist that passes `logBody: true`
- WHEN the test suite runs
- THEN `CallServiceBodyCaptureTest::testOnlyListedCallersPassLogBody` fails, naming the class
- @e2e exclude a source scan; covered by PHPUnit

### Requirement: The source page shows the window and says when no body was stored (REQ-OCD-011)

The source detail page SHALL let an administrator open an investigation window
(hours and a reason) through the endpoints of REQ-OCD-008, SHALL show
"Bodies are stored until {time}" while a window is open, and SHALL offer a
button that closes it early. The call log detail of a record that was not
captured SHALL say "No body stored: no investigation window was open". The
dialog SHALL live in its own file under `src/dialogs/`. A user without the
administrator permission SHALL NOT see the open button.

#### Scenario: an administrator investigates a partner for a day from the page
- GIVEN the source `zgw-zaken` without a window, and an administrator on its detail page
- WHEN she opens a window of 24 hours with the reason "melding 4711: verkeerde zaaktypen"
- THEN the page shows "Bodies are stored until" with the end time and a stop button, and a call made in that window shows its bodies in the call log detail
- e2e: `tests/e2e/outbound-call-log.spec.ts`

#### Scenario: a record without a window says why it has no body
- GIVEN a call log record made while no window was open
- WHEN an administrator opens its detail
- THEN the detail says "No body stored: no investigation window was open"
- e2e: `tests/e2e/outbound-call-log.spec.ts`
