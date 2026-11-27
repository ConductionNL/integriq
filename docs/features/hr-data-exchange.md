# Learning platform and payroll bureau exchange with humaniq

## Overview

humaniq keeps who works where and which trainings they followed. Two of its
exchanges run through integriq, as configuration on the synchronization engine.

**Learning platform (Studytube).** The platform learns who works where, and the
courses people finish there come back to their personnel file. Integriq ships
a dormant set:

| Object | Slug | What it does |
|--------|------|--------------|
| Source | `studytube-lms` | Studytube Public API v2, OAuth 2.0 client credentials |
| Source | `humaniq-learning-people` | humaniq's people feed, read through an integration account |
| Mapping | `humaniq-person-to-studytube-user` | a person as a Studytube user, `uid` = the humaniq employee id |
| Mapping | `studytube-completion-to-training-record` | a finished course as a humaniq `TrainingRecord` |
| Synchronization | `lms-people-push` | people changed since the last run, to Studytube's users |
| Synchronization | `lms-completions-pull` | courses finished since the last run, into humaniq |

Each finished course becomes one `TrainingRecord` with status `gevolgd`,
source `lms` and `sourceRef` the Studytube result id. The pull is keyed on that
id, so pulling the same completion twice leaves one record. A certificate's
expiry becomes `validUntil`.

Both templates are listed in the connector catalogue under **Education data**.

## Connect Studytube

1. **In Nextcloud**, create an integration account that may read humaniq's
   people feed, and an app password for it.
2. **In the credential broker**, store that app password as
   `humaniq-learning-people-app-password`, and Studytube's OAuth client secret
   as `studytube-lms-client-secret`.
3. **In integriq**, open the source `humaniq-learning-people`: set the location
   to `https://<your Nextcloud>/index.php/apps/humaniq/api/learning` and the
   integration account's user name, then enable it.
4. Open the source `studytube-lms`: fill the client id, check the paths against
   your Studytube API documentation, then enable it.
5. Enable `lms-people-push` first, so Studytube knows every employee by their
   humaniq id, then `lms-completions-pull`.

## Payroll bureaus

When HR sets a humaniq payroll handoff to *ready* (`klaargezet`), its
mutations go to the bureau that runs the administration's payroll. Integriq
ships a dormant template per kind of bureau, each with the mapping that writes
the handoff in that bureau's format:

| Object | Slug | What it does |
|--------|------|--------------|
| Source | `loket-payroll` | Loket.nl REST API v2, OAuth 2.0 client credentials |
| Mapping | `payroll-handoff-to-loket-mutations` | one Loket.nl mutation per handoff mutation, with only the new values |
| Source | `nmbrs-payroll` | Nmbrs REST API, OAuth 2.0 client credentials |
| Mapping | `payroll-handoff-to-nmbrs-changes` | one Nmbrs change per changed field, with the old and the new value |
| Source | `payroll-sftp-drop` | a bureau without an API, over SFTP |
| Mapping | `payroll-handoff-to-csv-rows` | one CSV file `<loonheffingennummer>-<period>.csv`, one row per changed field |

The mappings read the handoff with its mutations and its administration. A
mutation without an effective date applies from the first day of the period.
The humaniq employee id travels as the employee reference, so agree with the
bureau that it is the key it files the employee under.

All three templates are listed in the connector catalogue under **Payroll**.

### Delivering a handoff and bringing the payslips back

| Synchronization | From | To | What it does |
|-----------------|------|----|--------------|
| `payroll-handoff-push-loket` | humaniq `PayrollHandoff` | `loket-payroll` | delivers a `klaargezet` handoff as Loket.nl mutations |
| `payroll-handoff-push-nmbrs` | humaniq `PayrollHandoff` | `nmbrs-payroll` | delivers a `klaargezet` handoff as Nmbrs changes |
| `payroll-handoff-push-sftp` | humaniq `PayrollHandoff` | `payroll-sftp-drop` | writes a `klaargezet` handoff as one CSV file into `incoming` |
| `payroll-payslip-pull-sftp` | `payroll-sftp-drop` | humaniq `Payslip` | picks up the bureau's return files from `outgoing` |

A push sends a handoff only while it reads `klaargezet`. Before mapping it
embeds the handoff's `PayrollHandoffMutation` objects as `mutations` and its
`hrAdministration` as `administration` (`sourceConfig.embedRelated`). On
success it writes `verzonden`, the bureau's reference as `deliveryReference`
(for the SFTP drop: the path of the file) and the time as `deliveredAt` onto
the handoff. A refused delivery writes nothing to the handoff's status; the
error is on the synchronization log, so HR can reopen the handoff.

The pickup reads each row of a return file as one payslip. The bureau's
return file is a CSV with the columns `loonheffingennummer`, `periode`,
`medewerker` (the humaniq employee id), `strookId` (the bureau's payslip
id), `bruto`, `netto`, `loonheffing` and `uren`; a decimal comma is read as
a decimal point. Each payslip is kept on `strookId`, so a file read twice
leaves one payslip per row. The handoff is found by the administration's
loonheffingennummer and the period. Once the run has written its payslips,
each handoff they name that still reads `verzonden` is moved to `ontvangen`
with `receivedAt`, once (`targetConfig.relatedWriteBack`), and humaniq's
intake check runs over every payslip of the file.

The pushes ship unbound: none runs until an administrator chooses humaniq's
`PayrollHandoff` schema as its source. Payslip pulls from the Loket.nl and
Nmbrs APIs are not shipped: neither publishes the payslip read they need.

## Connect a payroll bureau

1. **In the credential broker**, store the bureau's OAuth client secret as
   `loket-payroll-client-secret` or `nmbrs-payroll-client-secret`; for the
   SFTP drop, store the account's password or key.
2. **In integriq**, open the bureau's source: fill the employer id (Loket.nl)
   or the company id (Nmbrs) and the client id, check the paths against the
   bureau's API documentation, then enable it. For the SFTP drop, set the
   server, the root path and the host key fingerprint the first test shows,
   and agree the folders and the delimiter with the bureau.
3. **Link the push.** Open the push for your bureau, choose humaniq's
   `PayrollHandoff` schema as its source, add a condition on
   `administrationId` for the administrations this bureau runs, and enable
   it.
4. **For the SFTP drop's payslips**, put `humaniq/hrAdministration` and
   `humaniq/PayrollHandoff` on the mapping lookup allowlist, then enable
   `payroll-payslip-pull-sftp`. Without them the pickup refuses each row
   instead of writing payslips that name no handoff.
