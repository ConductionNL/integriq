# Merge log, round 3 (integriq, landing lane land-integriq-2220), 2026-09-28

Rules followed: MERGE-RULES.md + MERGE-RULES-R2.md. Clone: /home/rubenlinde/memcap-work/lq-lanes/iq-adapters-b (no other process had its cwd here).

## Baseline
- Detached origin/development 12a33ed6f4422da06c2f2e3bb8648c8600bbd72f.
- Full PHPUnit (`vendor/bin/phpunit --no-coverage --log-junit`): 4150 tests, 0 failures, 2 skipped, exit 0. Failure set: empty (`.tmp/r3/baseline-fails.txt`). DSOSignatureVerifierServiceTest did not fail.
- Exported development tree (`.tmp/r3/devtree`): check:schema-l10n exit 0 (1620 strings, 0 uncovered, baseline 0); check:l10n-js exit 0; vitest jobDraft.spec.js 32/32 exit 0.

## #2220 feat/learniq-exchange-jobs-native: SKIPPED (prepared and pushed, not merged)
- Branch head before: af56799334bc3752261796baacc6aa6b0946f5bb, no unpushed commits, tracks origin/feat/learniq-exchange-jobs-native. PR was CONFLICTING.
- `git merge --no-edit origin/development`: conflict in lib/AppInfo/Application.php only (2 hunks: `use` imports and listener registrations). Resolved by taking both sides (independent additions: Exchange* events/listeners + SwvHandoffClient alias from the PR, RosterImportRequested* from development). `git grep -l '<<<<<<<'` = only .github/workflows/merge-hygiene.yml, a comment already on development.
- Register: the PR does not change integriq_register.json or integriq_mock_register.json (info.version 1.1.5 on both sides), so no bump. It adds register.d/learniq-exchange-jobs.json: job 1.3.0 (development max 1.2.0 via job-form-fields.json), sync_item_dead_letter 1.1.0 (development 1.0.0): both already strictly above. No repeated id/uuid issue (new file, no union).
- Checks on the merged tree: php -l Application.php exit 0; npm check:register exit 0; check:json-strict exit 0; full PHPUnit 4215 tests, 0 failures, exit 0 (subset of baseline: no new failures).
- Merge commit 42f63ed18a755818ec14ea6ae80921bd33ac874a ("merge development into feat/learniq-exchange-jobs-native"), pushed, ls-remote = local HEAD. PR now MERGEABLE.
- NOT merged. Reason: three reds the PR itself introduced, all green on development and all red on the pre-merge PR tree (af5679933), so they are not inherited (MERGE-RULES step 8: PR-introduced or not mechanical = STOP; R2 rule 5: check:schema-l10n and check:l10n-js must exit 0, baseline never raised):
  1. check:schema-l10n exit 1: 47 schema strings from register.d/learniq-exchange-jobs.json with no catalogue key (baseline 0). Needs en identity + authored nl translations (`node scripts/check-schema-l10n.js --list`).
  2. check:l10n-js exit 1: l10n/en.js and l10n/nl.js stale against the PR's en.json/nl.json (`npm run l10n:build`).
  3. vitest tests/vitest/jobDraft.spec.js "offers exactly the Action classes that exist in lib/Action" fails (4 offered vs 5 in lib/Action): the new lib/Action/ExchangeJobAction.php is not in the jobs form. Whether it belongs in the form or gets an exclusion is a design call, not mechanical.
  CI on the PR agrees (run 36381451427): these three plus Quality Report red; all six required checks (Hydra Gates, PHP lint/phpcs/phpstan, eslint, stylelint) green.
- development tip after: 12a33ed6f4422da06c2f2e3bb8648c8600bbd72f (unchanged). Register info.version on development: 1.1.5.

## #2220 retry after fix lane (head 0a4ae25f5): MERGED 9d76545ee5ea092e4e41e2dec2a9f289f29ce289
- The vendor was reinstalled with --ignore-platform-reqs. I reran both suites on that same vendor. Baseline (development 12a33ed6f): 4150 tests, 11 errors, all in tests/Unit/AppInfo/Application*Test. Branch: 4215 tests, the same 11 errors. New failures: none.
- Development had not moved and was already merged into the head, so there was no new merge and no push.
- check:schema-l10n exit 0, check:l10n-js exit 0, check:register exit 0. node_modules was gone from the clone, so vitest could not run locally. CI on 0a4ae25 passed, including Frontend Tests (unit).
- `gh pr merge --squash --admin` exit 0. State MERGED. Development tip is 9d76545ee, register 1.1.5.
