# openconnector-frontend-vue-rewrite — Bundle Hygiene Delta

**Spec refs**: `openconnector-frontend-vue-rewrite`, `feedback_shared-deps.md` (apexcharts
from nc-vue, fortawesome not)

## ADDED Requirements

### Requirement: package.json MUST NOT declare unused frontend dependencies

`package.json` `dependencies` MUST NOT list a package with zero import references under
`src/`. Charting libraries in particular MUST be consumed via
`@conduction/nextcloud-vue`'s bundled `apexcharts`/`vue-apexcharts` rather than an
app-local copy, to avoid two chart library major versions coexisting in the same
webpack bundle.

#### Scenario: No dead chart-library dependency

- GIVEN `package.json` declares `apexcharts` and/or `vue-apexcharts`
- WHEN a repo-wide search for `apexcharts` under `src/` is run
- THEN it MUST return at least one match, OR the dependency MUST be removed from
  `package.json`

#### Scenario: No dead icon-library dependency

- GIVEN `package.json` declares a `@fortawesome/*` package
- WHEN a repo-wide search for `fortawesome` under `src/` is run
- THEN it MUST return at least one match, OR the dependency MUST be removed from
  `package.json`

#### Scenario: Utility imports are scoped, not wholesale

- GIVEN a `.vue` or `.js` file needs a single lodash function
- WHEN the import statement is written
- THEN it MUST import the specific submodule (`lodash/toString.js`,
  `lodash/debounce.js`, etc.) or use a native inline equivalent, NOT
  `import _ from 'lodash'` for the whole package
