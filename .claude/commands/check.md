---
description: Run the full quality suite (PHP gate, frontend lint, spec checks)
---

Run the quality suite from the repo root and report every failure with file and line.

1. `composer check:strict` (legacy-type and route checks, lint, phpcs, phpmd, psalm, phpstan, PHPUnit)
2. `npm run lint`
3. `npm run stylelint`
4. `npm run check:specs` (strict JSON, manifest, register, connector templates)

Needs `composer install` and `npm ci` first. Fix what you can; for style issues try
`composer phpcs:fix` or `npm run lint-fix`, then run the failing step again.
