---
kind: config
depends_on: [connectors-case-system-document-delivery, sources-sftp-adapter]
---

# Proposal: connectors-hr-data-exchange

## Summary

Two humaniq exchanges wait on integriq. An employer that outsources payroll sets a period's mutations ready in humaniq, and nothing delivers them to the bureau or brings the payslips back. An employer with a learning platform wants it to know who works where, and the finished trainings to land on the personnel file. This change ships both as integriq configuration: templates for Loket.nl, Nmbrs, an SFTP drop and Studytube, the mappings, and synchronizations that move the handoff through its declared states and write payslips and training records back into humaniq's register.

## Why

The owner-moves pass of 2026-09-28 handed these halves to integriq from two humaniq changes merged on humaniq `development`. Both are `build` by the decision rule; the LMS row carries tender demand.

- **Payroll bureau.** humaniq `payroll-external-bureau-handoff`, Cross-app dependencies: "integriq: a synchronisation whose source is humaniq's `PayrollHandoff` objects in status `klaargezet`, a target per bureau (for example the Loket.nl API, a Nmbrs API or an SFTP file drop) mapping `PayrollHandoffMutation` to that bureau's mutation format, and the credentials for it. On delivery it sets the handoff to `verzonden` with a delivery reference. On the way back it writes the bureau's payslips into humaniq's register as `Payslip` objects with `payrollHandoffId` set, and sets the handoff to `ontvangen`."
- **Learning platform.** humaniq `talent-training-and-lms`, Cross-app dependencies: "a Studytube (or other LMS) source that reads the people feed and writes completed trainings back as `TrainingRecord` objects through the objects API."

Rows in the humaniq matrix:

- `pay-outsourced`, "Hand payroll processing to the vendor's own payroll service." Visma Raet ("uitbesteding salarisadministratie", https://youforce.nl/uitbesteding-salarisadministratie) and Personio ("Personio Payroll, powered by Loket", https://www.personio.com/whats-new-q2-26/) rate yes.
- `td-lms-sync`, "Keep employees, roles and organisation data in sync with a learning management system." Tender demand: Sudwest-Fryslan E17.1 and E17.2, "koppeling met LMS zoals Studytube, automatische synchronisatie". Visma Raet rates yes (its Learning API "accepts certificates back").
- `tal-training`, "Plan training and register who attended." Five competitors rate yes; the LMS return path feeds it.

## What integriq already has

- Push synchronizations triggered by an object in a register and schema (`openspec/specs/synchronization-engine/spec.md` REQ-001), pulls with an incremental cursor (REQ-016, REQ-017), and target writes into another app's register (REQ-004).
- Outcome write-back onto the triggering object, specified by `connectors-case-system-document-delivery` (REQ-CSD-001).
- An SFTP and FTPS adapter with deliver and pickup, specified by `sources-sftp-adapter` (0 of 11 tasks).
- Brokered credentials on a source (`lib/Service/BrokeredCallService.php`).
- No payroll bureau or LMS source: a search for Loket, Nmbrs, Studytube and TrainingRecord over `lib/` finds nothing.

## What this change builds

1. Source templates `loket-payroll`, `nmbrs-payroll`, `payroll-sftp-drop` and `studytube-lms`.
2. Mappings from `PayrollHandoffMutation` to each bureau's mutation format, and from the bureau's payslip to humaniq's `Payslip`.
3. A push on `PayrollHandoff` in `klaargezet` that delivers and writes `verzonden` with the delivery reference, and a return pull that writes payslips and moves the handoff to `ontvangen`.
4. A pull of humaniq's people feed pushed to Studytube, and a pull of completions that writes `TrainingRecord` objects with source `lms`.

## Out of scope

- Compiling mutations, the intake check and closing a period (humaniq).
- Other bureaus and learning platforms. Each is one more template and mapping.

## Impact

- New: four seed fragments, mappings and synchronizations in the seed data, catalogue entries in `Payroll` and `Education data`.
- No PHP beyond what the two dependency changes add.

## Cross-project dependencies

- humaniq `payroll-external-bureau-handoff` declares the `PayrollHandoff` lifecycle and leaves `verzenden` and `ontvangen` to integriq. humaniq `talent-training-and-lms` serves `GET /api/learning/people` to an integration account and owns `TrainingRecord`.

## Risks

- A bureau's format changes. Each mapping has a recorded example file and a test, and the version is on the template.
- Payslips arrive for an employee humaniq does not know. integriq writes what it receives; humaniq's intake check (its D4) lists it as a blocking finding.
