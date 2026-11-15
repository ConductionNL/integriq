# Tasks: platform-properties

## 1. Properties hold on development
- [x] 1.1 `appinfo/info.xml` declares a Nextcloud app with `<nextcloud min-version="32" max-version="35"/>` and `<licence>EUPL-1.2</licence>` (REQ-PLTP-001)
- [x] 1.2 `appinfo/info.xml` declares `<database min-version="10">pgsql</database>`, `<database min-version="8.0">mysql</database>` and `<database>sqlite</database>`; storage goes through OpenRegister objects and Nextcloud's query builder (REQ-PLTP-002)
- [x] 1.3 The admin page is registered through `<settings><admin>` (`lib/Settings/IntegriqAdmin.php`, section `lib/Sections/IntegriqAdmin.php`); integriq ships no login form, password store or session of its own (REQ-PLTP-003)

## 2. Matrix
- [x] 2.1 Rows `plt-self-host`, `plt-databases` and `id-admin-sso` cite `built.change: platform-properties` and their requirement in `openspec/parity/capabilities.json`
- [ ] 2.2 After merge, archive this change and repoint the three rows to `built.spec: openspec/specs/platform-properties`
