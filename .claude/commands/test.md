---
description: Run the PHP and JavaScript unit tests
---

Run the unit tests from the repo root and summarise the failures.

1. `composer test:unit` (PHPUnit)
2. `npm run test` (Vitest, `tests/vitest`)

If $ARGUMENTS names a PHPUnit test or filter, run `./vendor/bin/phpunit --filter "$ARGUMENTS"`
instead of the whole PHP suite.
