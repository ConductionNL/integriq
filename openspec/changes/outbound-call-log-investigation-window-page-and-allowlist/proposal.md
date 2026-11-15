---
kind: code
depends_on: [outbound-call-log-investigation-window]
---

# Proposal: outbound-call-log-investigation-window-page-and-allowlist

## Summary

Give administrators the source page that opens and closes an investigation window, and let code keep call bodies only for listed public-data callers.

- Rows: Woo row 13.23 "Outgoing request logging can be switched on for an investigation and switches itself off again" (not statutory), together with the first part.
- Wave: 1.
- Depends on: `integriq/outbound-call-log-investigation-window` (https://github.com/ConductionNL/integriq/issues/2538), the first part of this chain.
- Decision: D5 (2026-10-05), body capture is opt-in and time-boxed.

Build rules: openspec/woo-build-rules.md

## Why

This is the second part of `outbound-call-log-investigation-window`, split off
on 2026-10-06 so each part stays within 20 tasks. The first part stores bodies
only inside an investigation window an administrator opens per source, keeps a
failed call replayable, and ages captured bodies out (REQ-OCD-001, REQ-OCD-008,
REQ-OCD-009). Two pieces of that change are left, and they are this part: the
source page an administrator uses to open and close a window, and the rule
that code may switch body logging on only for listed public-data callers.

Woo capability row 13.23, "Outgoing request logging can be switched on for an
investigation and switches itself off again". Ruben's decision D5 of
2026-10-05 settled that the row wins over `outbound-call-log` REQ-OCD-001:
"Body capture becomes opt-in and time-boxed; status, timing and headers stay
permanent." The first part makes that true through the API. This part makes it
usable for an administrator and closes the code path that could store bodies
without a window.

## What changes

1. The code-level `logBody` flag stays only for the callers listed in one
   constant, `BodyCapturePolicy::LOG_BODY_ALLOWLIST`. Today that is the SLO
   adapter (`lib/Adapters/Slo/SloCurriculumClientHttp.php:99`), whose data is
   public. A test scans `lib/` and fails when another class passes `logBody`.
2. The source detail page gets a dialog (in its own file under `src/dialogs/`)
   to open a window with hours and a reason, shows "Bodies are stored until
   {time}" while it is open, and offers a button to stop early.
3. The call log detail of a record without a body says "No body stored: no
   investigation window was open".

## What does not change

- The window endpoints, the storage rule and the cleanup of the first part.
- Secret redaction, replay, dry run and the execution trace.

## Fail closed

A caller outside the allowlist that passes `logBody` stores no body, and the
scan test fails. The open button is not shown to a user without the
administrator permission, and the endpoint refuses such a user anyway
(REQ-OCD-008 in the first part).

## Dependencies

- `integriq/outbound-call-log-investigation-window`, the first part. Build
  after it has merged on `development`.
- Wave 1, size S. Implements decision D5 for row 13.23.
