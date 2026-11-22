---
description: Run the Playwright regression tests against a running instance
---

Run the Playwright regression project.

1. Make sure a Nextcloud with integriq and OpenRegister is running, installed and seeded.
2. Point the suite at it; there is no default:
   `export PLAYWRIGHT_BASE_URL=http://localhost:8080` (use your instance's URL).
3. `npm run test:regression` for the regression project, or `npm run test:e2e` for all
   projects.

Report failing specs with the assertion that failed.
