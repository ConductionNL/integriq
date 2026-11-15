# Proposal: platform-properties

## Why

Three capability rows describe what integriq is rather than a page in it: it runs on your own servers, it runs on the database your Nextcloud already uses, and its admin pages sit behind your organisation's single sign-on. All three are built, but no requirement says so, so nothing can cite them and nothing stops a later change from breaking one.

## The rows this covers

Source: integriq `openspec/parity/capabilities.json`.

- **plt-self-host** Run the integration platform on your own servers (built: `appinfo/info.xml` declares a Nextcloud app for Nextcloud 32 to 35, licence EUPL-1.2).
- **plt-databases** Run on PostgreSQL, MySQL or SQLite (built: `appinfo/info.xml` declares pgsql 10+, mysql 8.0+ and sqlite; every entity is an OpenRegister object).
- **id-admin-sso** Log in to the integration admin with your organisation's single sign-on (built by Nextcloud: integriq has no login of its own; its admin page is registered as a Nextcloud admin setting, `lib/Settings/IntegriqAdmin.php`).

## What changes

1. A new capability `platform-properties` with one requirement per property, each with scenarios.
2. The three rows cite the change and their requirement in `openspec/parity/capabilities.json`.

No code changes: the properties hold on development today.

## Screens

None. These are properties of the app; see `screens.md`.

## Out of scope

- Theming, which thematiq and Nextcloud own.
- The identity provider itself (user_oidc, user_saml), which Nextcloud apps own.
