# hr-data-exchange Specification

## ADDED Requirements

### Requirement: A ready payroll handoff reaches the bureau and its payslips come back (REQ-HRX-001)

Integriq MUST ship dormant templates for the Loket.nl API, the Nmbrs API and an SFTP file drop, with credentials by broker reference. A humaniq `PayrollHandoff` that reaches `klaargezet` MUST be delivered once to the bureau linked to its administration, in that bureau's format, and on success MUST be moved to `verzonden` with the delivery reference and time. The bureau's payslips MUST be written into humaniq's register as `Payslip` objects carrying the handoff id, keyed on the bureau's payslip id, after which the handoff MUST be moved to `ontvangen`.

#### Scenario: September's mutations go to Loket.nl
- GIVEN HR set the September 2026 handoff of Adviesbureau Kade B.V. to `klaargezet` with three mutations
- WHEN the push runs against the linked Loket.nl source
- THEN the three mutations are delivered once and the handoff reads `verzonden` with Loket.nl's reference
- @e2e exclude backend synchronization; covered by PHPUnit against a recorded Loket.nl exchange

#### Scenario: the bureau's payslips come back by SFTP
- GIVEN a `verzonden` handoff and the bureau's return file with two payslips in the SFTP drop
- WHEN the pickup runs
- THEN two `Payslip` objects with the handoff id exist in humaniq and the handoff reads `ontvangen`
- @e2e exclude backend synchronization; covered by PHPUnit and a run against the SFTP container

### Requirement: A learning platform learns who works where, and finished trainings come back (REQ-HRX-002)

Integriq MUST ship a dormant Studytube template. It MUST push the changes in humaniq's people feed to the platform, read through an integration account whose credential the broker holds, and MUST write each completed course back as one `TrainingRecord` with status `gevolgd`, source `lms` and the platform's completion id, never twice for the same completion.

#### Scenario: a BHV refresher is finished in Studytube
- GIVEN an employee who completed "BHV herhaling" in Studytube
- WHEN the completions pull runs twice
- THEN one `TrainingRecord` for that employee exists with status `gevolgd` and source `lms`
- @e2e exclude backend synchronization; covered by PHPUnit against recorded Studytube answers
