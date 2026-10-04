# Merge log R3, integriq (lane land-planninq-integriq, 2026-09-28)

Never staged. Rules: MERGE-RULES.md + MERGE-RULES-R2.md. No LANE-LOG file staged (development tracks a leaked LANE-LOG.md; untouched).

## Baseline
- origin/development 09a2ca45 (detached), `vendor/bin/phpunit --no-coverage`: exit 0, 4130 tests, 2 skipped, 0 failures/errors. Failure set: empty (`.tmp/r3land/baseline-failures.txt`).

## #2222 feat/rostering-adapter-targets-planninq
- Branch head before: bf38f13f (= origin, no unpushed commits). Dev moved 4 commits past its base (966d6458, cd1d9712, 93426b29, 09a2ca45), all openspec/ docs.
- `git merge --no-edit -m "merge development into feat/rostering-adapter-targets-planninq" origin/development`: conflicts NO -> 91008ab9.
- Registers: the PR does not change lib/Settings/ (diff vs development empty). integriq_register.json 1.1.5 = dev, integriq_mock_register.json 1.0.4 = dev; no bump (brief: bump only if the PR changed them).
- `git grep '<<<<<<<'`: only .github/workflows/merge-hygiene.yml:42, a comment in development's own hygiene workflow (identical on development). JSON parse of the PR's seed/fixture JSON: ok.
- Full suite on merged tree, run 1: exit 2, 4150 tests, 1 error: `LtiAgsServiceTest::testValidAssertionIssuesDeploymentScopedToken` "The token has expired" (token iat=time(), exp=+300s, validated 3 s later). Not touched by the PR (Roster/planninq files only, no LTI/AuthorizationService/shared state).
  - `phpunit --filter LtiAgsServiceTest` alone: exit 0 (10 tests).
  - Full suite run 2 (same tree): exit 0, 4150 tests, 0 failures/errors (wall 6:08 under machine load). Read as a wall-clock timing flake, failure set of run 2 empty = subset of baseline.
- Pushed; `git ls-remote` = local 91008ab9.
- `gh pr merge 2222 -R ConductionNL/integriq --squash --admin`: exit 0. `gh pr view`: MERGED 2026-09-28T07:09:32Z, squash 12a33ed6.
- Verify: origin/development tip = 12a33ed6, tree identical to branch 91008ab9 (`git diff --stat` empty); registers on development integriq_register 1.1.5, integriq_mock_register 1.0.4. Branch not deleted.
