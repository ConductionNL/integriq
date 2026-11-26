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
The synchronizations that deliver a handoff and bring the payslips back
(`payroll-handoff-push`, `payroll-payslip-pull`) are not shipped yet.

## Connect a payroll bureau

1. **In the credential broker**, store the bureau's OAuth client secret as
   `loket-payroll-client-secret` or `nmbrs-payroll-client-secret`; for the
   SFTP drop, store the account's password or key.
2. **In integriq**, open the bureau's source: fill the employer id (Loket.nl)
   or the company id (Nmbrs) and the client id, check the paths against the
   bureau's API documentation, then enable it. For the SFTP drop, set the
   server, the root path and the host key fingerprint the first test shows,
   and agree the folders and the delimiter with the bureau.
