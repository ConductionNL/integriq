# platform-properties Specification (delta)

## Purpose

What integriq is as a product, independent of any page: where it runs, on which database, and how administrators log in. Covers rows plt-self-host, plt-databases and id-admin-sso.

## ADDED Requirements

### Requirement: The app runs on the customer's own Nextcloud (REQ-PLTP-001)

Integriq SHALL install and run as a Nextcloud app on a server the customer operates, on Nextcloud 32 to 35, with OpenRegister as its only required app. It SHALL NOT need a service operated by Conduction or another vendor to start, store its configuration or serve its pages. Every outside system it talks to SHALL be a source the customer can see, change and switch off on the sources page.

#### Scenario: An installation without access to vendor services
- GIVEN a Nextcloud 32 server with OpenRegister installed and no route to any Conduction service
- WHEN an administrator runs `occ app:enable integriq`
- THEN the app enables and its sources, endpoints and logs pages load

#### Scenario: An administrator switches off an outside system
- GIVEN a seeded source that points at an outside system
- WHEN an administrator disables it on the sources page
- THEN no synchronization or call reaches that system until it is enabled again

### Requirement: The app runs on the Nextcloud database (REQ-PLTP-002)

Integriq SHALL run on the database of the Nextcloud installation and SHALL support PostgreSQL 10 or later, MySQL 8.0 or later and SQLite, as declared in `appinfo/info.xml`. It SHALL keep its records as OpenRegister objects and SHALL NOT need a second database server. Database-specific SQL SHALL only be used behind a branch on the platform, with a path for each supported database.

#### Scenario: Installed on MySQL
- GIVEN a Nextcloud installation on MySQL 8.0 with OpenRegister enabled
- WHEN integriq is enabled and an administrator creates a source and runs a synchronization
- THEN the source and the synchronization log are stored and can be read back

#### Scenario: The declared databases
- GIVEN `appinfo/info.xml` at any release tag
- WHEN its dependencies are read
- THEN it lists pgsql with min-version 10, mysql with min-version 8.0, and sqlite

### Requirement: The admin pages sit behind the Nextcloud login (REQ-PLTP-003)

Integriq SHALL have no login, password store or session of its own for people. Its admin settings SHALL be registered as a Nextcloud admin setting, so they are reached through the Nextcloud session, including any single sign-on, SAML, OpenID Connect or two-factor setup the instance has. Every routed controller method SHALL declare its access with a Nextcloud attribute, and a method reachable without a session SHALL be marked `#[PublicPage]` and check its own key, token or signature.

#### Scenario: An administrator signs in through the organisation's identity provider
- GIVEN an instance where people sign in through user_oidc or user_saml
- WHEN a signed-in administrator opens the integriq admin settings
- THEN the page opens without asking for other credentials

#### Scenario: A person who is not signed in
- GIVEN no Nextcloud session
- WHEN the integriq admin settings URL is opened
- THEN Nextcloud sends the person to its login page
