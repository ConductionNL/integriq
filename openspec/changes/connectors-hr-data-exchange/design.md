# Design: connectors-hr-data-exchange

Kind: config. Size M. Read at integriq development `966d6458` and the humaniq changes at humaniq development on 2026-09-28.

## Context

- **The payroll contract.** humaniq `payroll-external-bureau-handoff` D3: `PayrollHandoff` (`administrationId`, `period`, `compiledAt`, `mutationCount`, `status`, `deliveryReference`, `deliveredAt`, `receivedAt`, `intakeFindings`), lifecycle `klaarzetten` (to `klaargezet`), `verzenden` (to `verzonden`, written by integriq), `ontvangen` (to `ontvangen`, written by integriq). `PayrollHandoffMutation` holds the employee, the kind, `effectiveDate`, `fields` (old and new). `Payslip` gains `payrollHandoffId`; returned payslips carry `externalSource` and the employee's `userId`.
- **The learning contract.** humaniq `talent-training-and-lms` D3: `GET /api/learning/people?modifiedSince=` returns per active employee `id`, `firstName`, `lastName`, `nextcloudUserId`, `orgUnit`, `role`, `managerUserIds`, `startDate`, `endDate`, `modified`, read under the integration account's own rights. D1: `TrainingRecord` (`employeeId`, `title`, `provider`, `status`, `completedOn`, `validUntil`, `competenceCode`, `source`, `sourceRef`).
- **Engine.** Push on a register object (`synchronization-engine` REQ-001), incremental pull (REQ-016), write into another app's register (REQ-004), write-back onto the source object (`connectors-case-system-document-delivery` REQ-CSD-001), SFTP deliver and pickup (`sources-sftp-adapter`).

## D1. Delivering a handoff

`payroll-handoff-push`: source humaniq `PayrollHandoff`, trigger `status = klaargezet`, target the bureau source linked to the handoff's administration. The mapping renders the handoff's mutations in the bureau's format: Loket.nl and Nmbrs as their API's mutation calls, the SFTP drop as a CSV file per period named `<loonheffingennummer>-<period>.csv`. On success the write-back sets `status` `verzonden`, `deliveryReference` and `deliveredAt`, which is the declared `verzenden` transition. On a spent retry budget it writes nothing to the status and records the error on the synchronization log, so HR can reopen the handoff (`heropenen` is humaniq's).

## D2. Bringing payslips back

`payroll-payslip-pull`: per bureau source, an incremental pull of the period's payslips (API) or a pickup of the bureau's return file (SFTP), mapped to `Payslip` with `payrollHandoffId`, `externalSource` and the employee reference, written into humaniq's register keyed on the bureau's payslip id. When the pull has written payslips for a `verzonden` handoff, it sets `status` `ontvangen` and `receivedAt`. humaniq's listener then runs its intake check.

## D3. The people feed to Studytube, completions back

`lms-people-push`: an incremental pull of `GET /api/learning/people` through a source for humaniq with an integration account credential by broker reference, pushed to Studytube as users with their team and manager. `lms-completions-pull`: an incremental pull of completed courses from Studytube, mapped to `TrainingRecord` with `status` `gevolgd`, `completedOn`, `validUntil`, `source` `lms` and `sourceRef` the platform's completion id, keyed on that id so a repeat pull writes nothing new.

## D4. Catalogue

`loket-payroll`, `nmbrs-payroll`, `payroll-sftp-drop` in `Payroll` (the bookkeeping category name `platform-bookkeeping-catalogue-items` fixes); `studytube-lms` in `Education data`.

## Declarative versus imperative

All configuration on the engine: templates, mappings and synchronizations. The behaviour they need (write-back, SFTP) comes from the two dependency changes.

## Seed data

The four dormant templates and the disabled synchronizations. A recorded Loket.nl mutation exchange, one SFTP return file with two payslips, and a recorded Studytube completion as test fixtures.

## Risks

- [A handoff is set ready twice] the push keys on the handoff id, and a second trigger for a `verzonden` handoff does nothing.
