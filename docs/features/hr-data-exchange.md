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

The payroll handoff to Loket.nl, Nmbrs or an SFTP drop is part of the same
change (`connectors-hr-data-exchange`, REQ-HRX-001) and is not shipped yet.
